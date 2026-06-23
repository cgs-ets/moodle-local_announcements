<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Processes the digest queue and sends categorised digest emails/notifications.
 *
 * @package   local_announcements
 * @copyright 2026 Michael Vangelovski
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_announcements\task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/../utils.php');
require_once($CFG->dirroot . '/local/announcements/locallib.php');
require_once($CFG->dirroot . '/local/announcements/vendor/autoload.php');

use local_announcements\persistents\announcement;
use local_announcements\external\announcement_exporter;
use \local_announcements\utils;
use Postmark\PostmarkClient;


/**
 * Reads pending rows from ann_digest_queue (v2 sectioned customdata) and sends
 * categorised digest emails/notifications.
 *
 * @package   local_announcements
 * @copyright 2026 Michael Vangelovski
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_send_digests_categorised {

    /** @var digest_logger */
    protected $logger;

    /**
     * Constructor.
     *
     * @param digest_logger $logger
     */
    public function __construct(digest_logger $logger) {
        $this->logger = $logger;
    }

    /**
     * Process pending digest queue entries.
     */
    public function execute() {
        global $DB, $CFG;

        $config = get_config('local_announcements');
        $batchsize = isset($config->digestbatchnum) ? (int) $config->digestbatchnum : 1;

        $this->logger->log("Sending Categorised Digests " . date('Y-m-d H:i:s') . ".");

        // Initialise $USER, $PAGE, and $OUTPUT for CLI context.
        // This ensures tokenized URLs and template rendering work outside of a web request.
        \core\cron::setup_user();
        \core\cron::prepare_core_renderer();

        // Read up to N pending rows from the queue.
        $rows = $DB->get_records_select(
            'ann_digest_queue',
            'status = 0',
            null,
            'id ASC',
            '*',
            0,
            $batchsize
        );

        if (empty($rows)) {
            $this->logger->log("No pending digest queue entries found.", 1);
            return;
        }

        $this->logger->log("Found " . count($rows) . " pending digest(s) to process.", 1);

        // Mark selected rows as processing (status = 1).
        $ids = array_keys($rows);
        list($insql, $params) = $DB->get_in_or_equal($ids);
        $DB->set_field_select('ann_digest_queue', 'status', 1, "id {$insql}", $params);

        $inclmyconnect = $this->include_myconnect();
        $postmarkapikey = isset($config->postmarkapikey) ? $config->postmarkapikey : '';
        $postmarkfromemail = isset($config->postmarkfromemail) ? $config->postmarkfromemail : '';

        // Phase 1: Prepare all digests, send notifications, and collect email payloads.
        $emailbatch = [];
        $batchmeta = [];

        foreach ($rows as $row) {
            try {
                $data = json_decode($row->customdata);
                $recipient = \core_user::get_user_by_username($row->username);

                if (!$recipient || $recipient->deleted) {
                    $this->logger->log("User {$row->username} not found or deleted. Skipping.", 1);
                    $DB->set_field('ann_digest_queue', 'status', 3, ['id' => $row->id]);
                    $DB->set_field('ann_digest_queue', 'timeprocessed', time(), ['id' => $row->id]);
                    continue;
                }

                // Switch $USER to the recipient so tokenized URLs are generated for them.
                \core\cron::setup_user($recipient);
                \core\cron::prepare_core_renderer();

                $this->logger->log("Preparing digest for {$recipient->username} ({$recipient->id}). Data: " . $row->customdata, 1);

                $sections = (isset($data->sections) && is_array($data->sections)) ? $data->sections : array();
                $childsections = (isset($data->childsections) && is_array($data->childsections)) ? $data->childsections : array();
                $prepared = $this->prepare_sections($sections, $childsections, $recipient, $inclmyconnect, $data->role);

                if (!$prepared['hascontent']) {
                    $this->logger->log("No posts found to send for {$recipient->username}.", 1);
                    $DB->set_field('ann_digest_queue', 'status', 2, ['id' => $row->id]);
                    $DB->set_field('ann_digest_queue', 'timeprocessed', time(), ['id' => $row->id]);
                    continue;
                }

                // Prepare the mail content (renders templates, checks user preferences).
                $maildata = $this->prepare_mail_content($recipient, $prepared, $data->role);

                // Send notification (independent of email).
                $this->send_notification($recipient, $maildata);

                if (!$maildata['wantsemail']) {
                    // User doesn't want email digests — mark as complete and move on.
                    $DB->set_field('ann_digest_queue', 'status', 2, ['id' => $row->id]);
                    $DB->set_field('ann_digest_queue', 'timeprocessed', time(), ['id' => $row->id]);
                    continue;
                }

                // Add email payload to batch.
                $emailbatch[] = [
                    'From' => $postmarkfromemail,
                    'To' => $recipient->email,
                    'Subject' => $maildata['subject'],
                    'HtmlBody' => $maildata['digesthtml'],
                    'TextBody' => '',
                    'Headers' => [
                        'Precedence' => 'Bulk',
                        'X-Auto-Response-Suppress' => 'All',
                        'Auto-Submitted' => 'auto-generated',
                    ],
                ];
                $batchmeta[] = [
                    'row' => $row,
                    'recipient' => $recipient,
                    'sentcount' => $maildata['sentcount'],
                ];

            } catch (\Exception $e) {
                $this->logger->log("Error preparing digest for {$row->username}: " . $e->getMessage(), 1);
                $DB->set_field('ann_digest_queue', 'status', 3, ['id' => $row->id]);
                $DB->set_field('ann_digest_queue', 'timeprocessed', time(), ['id' => $row->id]);
            }
        }

        // Reset $USER back to the cron admin user.
        \core\cron::setup_user();

        // Phase 2: Send all collected emails in a single Postmark batch.
        if (empty($emailbatch)) {
            return;
        }

        if (empty($postmarkapikey) || empty($postmarkfromemail)) {
            $this->logger->log("PostMark API key or from email not configured. Skipping emails.", 1);
            foreach ($batchmeta as $meta) {
                $DB->set_field('ann_digest_queue', 'status', 2, ['id' => $meta['row']->id]);
                $DB->set_field('ann_digest_queue', 'timeprocessed', time(), ['id' => $meta['row']->id]);
            }
            return;
        }

        try {
            $client = new PostmarkClient($postmarkapikey);
            $this->logger->log("Sending PostMark batch of " . count($emailbatch) . " email(s).", 1);
            $results = $client->sendEmailBatch($emailbatch);

            foreach ($results as $i => $result) {
                $meta = $batchmeta[$i];
                $row = $meta['row'];
                $email = $meta['recipient']->email;

                if ($result->ErrorCode === 0) {
                    $this->logger->log("PostMark email digest sent to {$email} with {$meta['sentcount']} announcements.", 2);
                    $DB->set_field('ann_digest_queue', 'status', 2, ['id' => $row->id]);
                } else {
                    $this->logger->log("PostMark error for {$email}: [{$result->ErrorCode}] {$result->Message}", 2);
                    $DB->set_field('ann_digest_queue', 'status', 3, ['id' => $row->id]);
                }
                $DB->set_field('ann_digest_queue', 'timeprocessed', time(), ['id' => $row->id]);
            }
        } catch (\Exception $e) {
            $this->logger->log("PostMark batch error: " . $e->getMessage(), 1);
            foreach ($batchmeta as $meta) {
                $DB->set_field('ann_digest_queue', 'status', 3, ['id' => $meta['row']->id]);
                $DB->set_field('ann_digest_queue', 'timeprocessed', time(), ['id' => $meta['row']->id]);
            }
        }
    }

    /**
     * Turn the sectioned customdata into the grouped template context.
     *
     * Leaf sections are grouped under their top-level group (the part before
     * ' > '); ungrouped categories (From Head, Newsletter link, etc.) become a
     * group with a single label-less subsection.
     *
     * @param   array      $sections  Decoded section objects from customdata.
     * @param   \stdClass  $recipient
     * @param   bool       $inclmyconnect
     * @param   string     $role  'staff', 'student' or 'parent'
     * @return  array  ['groups' => [...], 'sentcount' => int, 'hascontent' => bool]
     */
    protected function prepare_sections($sections, $childsections, $recipient, $inclmyconnect, $role) {
        global $PAGE;

        $groups = array();
        $groupindex = array();
        $sentcount = 0;
        $hascontent = false;

        // School-wide / generic groups, derived from the category prefix.
        foreach ($sections as $section) {
            $prepared = $this->prepare_subsection($section, $recipient, $inclmyconnect, false, $role);
            if ($prepared === null) {
                continue;
            }
            $hascontent = true;
            $sentcount += $prepared['postcount'];
            $grouptitle = $prepared['grouptitle'];
            if (!isset($groupindex[$grouptitle])) {
                $groupindex[$grouptitle] = count($groups);
                $group = array(
                    'grouptitle' => $grouptitle,
                    'subsections' => array(),
                );
                // Promoted student sub-categories carry their own rank (the
                // original "Students > *" sortorder) since their title is no
                // longer a prefix known to group_rank().
                if (isset($prepared['grouprank'])) {
                    $group['grouprank'] = $prepared['grouprank'];
                }
                $groups[] = $group;
            }
            $groups[$groupindex[$grouptitle]]['subsections'][] = $prepared['subsection'];
        }

        // Per-child groups (parents): each child's name heads its own copy of the
        // "Students > *" sub-sections. Mentee MyConnect posts are folded in as plain
        // posts (the child heading already names the child).
        $childordinal = 0;
        foreach ($childsections as $childsection) {
            $subsections = array();
            $childposts = 0;
            $childsubs = (isset($childsection->sections) && is_array($childsection->sections)) ? $childsection->sections : array();
            foreach ($childsubs as $section) {
                $prepared = $this->prepare_subsection($section, $recipient, $inclmyconnect, true, $role);
                if ($prepared === null) {
                    continue;
                }
                $childposts += $prepared['postcount'];
                $subsections[] = $prepared['subsection'];
            }
            if (empty($subsections)) {
                continue;
            }
            $hascontent = true;
            $sentcount += $childposts;
            $grouptitle = isset($childsection->childname) ? $childsection->childname : '';
            $childordinal++;

            // Build the child's tokenised profile picture. This is done here (send
            // time) rather than at queue time because the recipient is the active
            // $USER and $PAGE/renderer are initialised, so the token is minted for
            // them — mirroring how the author photo is built in announcement_exporter.
            $childphoto = '';
            if (!empty($childsection->childid)) {
                $childuser = \core_user::get_user($childsection->childid);
                if ($childuser) {
                    $up = new \user_picture($childuser, ['size' => 35]);
                    $up->includetoken = $childuser->id;
                    $childphoto = $up->get_url($PAGE)->out(false);
                }
            }

            $groups[] = array(
                'grouptitle' => $grouptitle,
                'grouptitlephoto' => $childphoto,
                'subsections' => $subsections,
                'grouprank' => 50 + $childordinal,
            );
        }

        // Order groups so per-child groups sit in the natural "Student" slot.
        usort($groups, function($a, $b) {
            $ra = isset($a['grouprank']) ? $a['grouprank'] : $this->group_rank($a['grouptitle']);
            $rb = isset($b['grouprank']) ? $b['grouprank'] : $this->group_rank($b['grouptitle']);
            return $ra <=> $rb;
        });

        return array(
            'groups' => $groups,
            'sentcount' => $sentcount,
            'hascontent' => $hascontent,
        );
    }

    /**
     * Build a single subsection (label + posts + myconnect) from a section.
     *
     * @param   \stdClass  $section   Decoded section object.
     * @param   \stdClass  $recipient
     * @param   bool       $inclmyconnect
     * @param   bool       $foldmentee  When true, mentee MyConnect posts are rendered
     *                                  as plain posts (no per-mentee "Posts for X"
     *                                  heading) — used inside per-child groups.
     * @param   string     $role  'staff', 'student' or 'parent'
     * @return  array|null  ['grouptitle','label','subsection','postcount'] or null when empty.
     */
    protected function prepare_subsection($section, $recipient, $inclmyconnect, $foldmentee, $role) {
        $category = isset($section->category) ? $section->category : '';
        if ($category === '') {
            return null;
        }

        // Prepare announcement posts for this section.
        $postids = (isset($section->announcements) && is_array($section->announcements)) ? $section->announcements : array();
        $posts = $this->prepare_data($postids, $recipient);

        // Prepare MyConnect posts for this section.
        $myconnectposts = array();
        $myconnectmenteeposts = array();
        if ($inclmyconnect) {
            if (!empty($section->myconnectposts)) {
                $mcids = json_decode(json_encode($section->myconnectposts), true);
                $myconnectposts = \local_myconnect\persistents\post::prepare_data($mcids, $recipient, new \null_progress_trace()) ?: array();
            }
            if (!empty($section->myconnectmenteeposts)) {
                $menteemap = json_decode(json_encode($section->myconnectmenteeposts), true);
                foreach ($menteemap as $menteeid => $menteeposts) {
                    $preparedposts = \local_myconnect\persistents\post::prepare_data_for_mentee(
                        $menteeposts,
                        $menteeid,
                        $recipient,
                        new \null_progress_trace()
                    ) ?: array();
                    if ($foldmentee) {
                        // Render under the child heading without a per-mentee label.
                        $myconnectposts = array_merge($myconnectposts, $preparedposts);
                    } else {
                        $mentee = \core_user::get_user($menteeid);
                        $myconnectmenteeposts[] = array(
                            'user' => $mentee,
                            'posts' => $preparedposts,
                        );
                    }
                }
            }
        }

        if (empty($posts) && empty($myconnectposts) && empty($myconnectmenteeposts)) {
            return null;
        }

        // Drop the trailing separator border from the very last item in the section so it closes cleanly.
        if (!empty($myconnectmenteeposts)) {
            $lastmentee = count($myconnectmenteeposts) - 1;
            if (!empty($myconnectmenteeposts[$lastmentee]['posts'])) {
                $menteeposts = $myconnectmenteeposts[$lastmentee]['posts'];
                $menteeposts[count($menteeposts) - 1]->islast = true;
            }
        } else if (!empty($myconnectposts)) {
            $myconnectposts[count($myconnectposts) - 1]->islast = true;
        } else if (!empty($posts)) {
            $posts[count($posts) - 1]->islast = true;
        }

        // Split the category into a group title and an (optional) sub-label.
        if (strpos($category, ' > ') !== false) {
            list($grouptitle, $label) = explode(' > ', $category, 2);
            //$grouptitle = "$grouptitle > $label";
            //$label = '';
        } else {
            $grouptitle = $category;
            $label = '';
        }

        // For student digests, promote the "Students > *" sub-category (Academic,
        // Co-curricular, House, Boarding) to be the group heading itself, dropping
        // the redundant "Students" parent heading. The promoted group keeps the
        // original category's sortorder so ordering is preserved.
        $grouprank = null;
        if ($role === 'student' && strpos($category, 'Students > ') === 0) {
            $grouptitle = $label;
            $label = '';
            $grouprank = $this->category_sortorder($category);
        }

        $result = array(
            'grouptitle' => $grouptitle,
            'label' => $label,
            'postcount' => count($posts),
            'subsection' => array(
                'label' => $label,
                'posts' => $posts,
                'hasposts' => !empty($posts),
                'myconnectposts' => $myconnectposts,
                'myconnectmenteeposts' => $myconnectmenteeposts,
                'hasmyconnectposts' => !empty($myconnectposts) || !empty($myconnectmenteeposts),
            ),
        );
        if ($grouprank !== null) {
            $result['grouprank'] = $grouprank;
        }
        return $result;
    }

    /**
     * Rank a group by its title for ordering, derived from announcement::CATEGORIES
     * sortorder (the part before ' > '). Per-child groups carry their own grouprank
     * (50 + ordinal) so they sort just after the generic "Student" group.
     *
     * @param   string  $grouptitle
     * @return  int
     */
    protected function group_rank($grouptitle) {
        static $ranks = null;
        if ($ranks === null) {
            $ranks = array();
            foreach (announcement::CATEGORIES as $category) {
                $shortname = $category['shortname'];
                $prefix = (strpos($shortname, ' > ') !== false) ? explode(' > ', $shortname, 2)[0] : $shortname;
                // Keep the smallest sortorder seen for each prefix.
                if (!isset($ranks[$prefix]) || $category['sortorder'] < $ranks[$prefix]) {
                    $ranks[$prefix] = $category['sortorder'];
                }
            }
        }
        return isset($ranks[$grouptitle]) ? $ranks[$grouptitle] : PHP_INT_MAX;
    }

    /**
     * The sortorder of a full category shortname from announcement::CATEGORIES.
     * Used to rank promoted student sub-category groups (e.g. "Students > Academic")
     * by their original category position.
     *
     * @param   string  $category  Full category shortname.
     * @return  int
     */
    protected function category_sortorder($category) {
        static $orders = null;
        if ($orders === null) {
            $orders = array();
            foreach (announcement::CATEGORIES as $cat) {
                $orders[$cat['shortname']] = $cat['sortorder'];
            }
        }
        return isset($orders[$category]) ? $orders[$category] : PHP_INT_MAX;
    }

    /**
     * Export a list of announcement post ids for a recipient.
     *
     * @param   int[]      $postids The list of post IDs
     * @param   \stdClass  $recipient user record
     * @return  array
     */
    protected function prepare_data($postids, $recipient) {
        global $PAGE;

        if (empty($postids)) {
            return [];
        }

        $posts = array();
        $announcements = announcement::get_by_ids_and_username($postids, $recipient->username);

        $context = \context_system::instance();
        $output = $PAGE->get_renderer('core');

        foreach ($announcements as $announcement) {
            $exporter = new announcement_exporter($announcement->persistent, [
                'context' => $context,
                'audiences' => $announcement->audiences,
            ]);
            $posts[] = $exporter->export($output);
        }

        return $posts;
    }

    /**
     * Prepare the mail content for a single recipient.
     *
     * Renders the categorised digest templates and checks user preferences.
     * Returns an array with the rendered HTML, subject, and preference flags.
     */
    protected function prepare_mail_content($recipient, $prepared, $role) {
        global $OUTPUT, $DB;

        $config = get_config('local_announcements');

        $name = fullname($recipient);
        $subject = "My Daily Announcements from CGS Connect for $name ($role digest)";
        $sentcount = $prepared['sentcount'];

        $content = [
            'groups' => $prepared['groups'],
            'hascontent' => $prepared['hascontent'],
            'userprefs' => (new \moodle_url('/local/announcements/preferences.php'))->out(false),
            'myconnecturl' => (new \moodle_url('/local/myconnect/index.php'))->out(false),
            'announcementsurl' => (new \moodle_url('/local/announcements/index.php'))->out(false),
            'myconnectheaderimage' => $config->myconnectheaderimage,
            'digestheaderimage' => $config->digestheaderimage,
            'digestfooterimage' => $config->digestfooterimage,
            'digestfooterimageurl' => $config->digestfooterimageurl,
            'digestfootercredits' => $config->digestfootercredits,
        ];

        $digesthtml = $OUTPUT->render_from_template('local_announcements/message_digest_email_categorised', $content);

        // Check user preferences.
        $digests = $DB->get_field('ann_user_preferences', 'digests', array('username' => $recipient->username));
        if ($digests === false) {
            $digests = 1;
        }
        $wantsemail = (bool) $digests;

        if (!$wantsemail) {
            $this->logger->log("User {$recipient->username} does not want digests. Not sending email.", 1);
        }

        $notify = $DB->get_field('ann_user_preferences', 'notify', array('username' => $recipient->username));
        if ($notify === false) {
            $notify = 1;
        }

        return [
            'subject' => $subject,
            'digesthtml' => $digesthtml,
            'content' => $content,
            'sentcount' => $sentcount,
            'wantsemail' => $wantsemail,
            'wantsnotify' => (bool) $notify,
        ];
    }

    /**
     * Send the Moodle notification for a single recipient.
     */
    protected function send_notification($recipient, $maildata) {
        global $OUTPUT;

        if (!$maildata['wantsnotify']) {
            $this->logger->log("User {$recipient->username} does not want notifications. Not notifying.", 1);
            return;
        }

        try {
            $userfrom = \core_user::get_noreply_user();
            $userfrom->customheaders = array(
                'Precedence: Bulk',
                'X-Auto-Response-Suppress: All',
                'Auto-Submitted: auto-generated',
            );

            $digesttext = $OUTPUT->render_from_template('local_announcements/message_digest_categorised', $maildata['content']);
            $eventdata = new \core\message\message();
            $eventdata->courseid = SITEID;
            $eventdata->component = 'local_announcements';
            $eventdata->name = 'notificationsv2';
            $eventdata->userfrom = $userfrom;
            $eventdata->userto = $recipient;
            $eventdata->subject = $maildata['subject'];
            $eventdata->fullmessage = '';
            $eventdata->fullmessageformat = FORMAT_PLAIN;
            $eventdata->fullmessagehtml = $digesttext;
            $eventdata->notification = 1;
            $eventdata->smallmessage = get_string('digest:smallmessage', 'local_announcements', $maildata['sentcount']);
            //message_send($eventdata);
            $this->logger->log("Sent digest as push notification to {$recipient->username} with {$maildata['sentcount']} announcements.", 2);
        } catch (\Exception $e) {
            $this->logger->log("Error sending push notification to {$recipient->username}: " . $e->getMessage(), 2);
        }
    }



    private function include_myconnect() {
        global $CFG;

        $config = get_config('local_announcements');

        $incl = isset($config->myconnectdigest) ? $config->myconnectdigest : false;

        $dir = file_exists($CFG->dirroot . '/local/myconnect/version.php');
        if ($incl && $dir) {
            return true;
        }

        return false;
    }

}
