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
 * Processes the digest queue and sends emails/notifications.
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
 * Reads pending rows from ann_digest_queue and sends digest emails/notifications.
 *
 * @package   local_announcements
 * @copyright 2026 Michael Vangelovski
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_send_digests {

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

        $this->logger->log("Sending Digests " . date('Y-m-d H:i:s') . ".");

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
                $posttypes = json_decode($row->customdata);
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

                // Prepare announcement posts.
                $postids = $posttypes->announcements;
                $announcements = $this->prepare_data($postids, $recipient);

                $myconnectposts = array();
                $myconnect_mentee_posts = array();
                if ($inclmyconnect) {
                    $myconnectpostids = json_decode(json_encode($posttypes->myconnectposts), true);
                    $myconnectposts = \local_myconnect\persistents\post::prepare_data(
                        $myconnectpostids,
                        $recipient,
                        null
                    );

                    if (isset($posttypes->myconnectmenteeposts)) {
                        $this->logger->log("Mentee MyConnect posts: " . json_encode($posttypes->myconnectmenteeposts), 1);
                        $myconnectmenteeposts = json_decode(json_encode($posttypes->myconnectmenteeposts), true);
                        foreach ($myconnectmenteeposts as $menteeid => $menteeposts) {
                            $preparedposts = \local_myconnect\persistents\post::prepare_data_for_mentee(
                                $menteeposts,
                                $menteeid,
                                $recipient,
                                null
                            );
                            $mentee = \core_user::get_user($menteeid);
                            $myconnect_mentee_posts[] = array(
                                'user' => $mentee,
                                'posts' => $preparedposts
                            );
                        }
                    }

                }

                if (empty($announcements) && empty($myconnectposts) && empty($myconnect_mentee_posts)) {
                    $this->logger->log("No posts found to send for {$recipient->username}.", 1);
                    $DB->set_field('ann_digest_queue', 'status', 2, ['id' => $row->id]);
                    $DB->set_field('ann_digest_queue', 'timeprocessed', time(), ['id' => $row->id]);
                    continue;
                }

                // Prepare the mail content (renders templates, checks user preferences).
                $maildata = $this->prepare_mail_content($recipient, $announcements, $myconnectposts, $myconnect_mentee_posts);

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
     * Prepare all data for this run.
     *
     * @param   int[]   $postids The list of post IDs
     * @param   stdClass   $recipient user record
     */
    protected function prepare_data($postids, $recipient) {
        global $OUTPUT, $PAGE;

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

        if (empty($posts)) {
            // All posts have been removed since the task was queued.
            return [];
        }

        return $posts;
    }

    /**
     * Prepare the mail content for a single recipient.
     *
     * Renders the digest templates and checks user preferences.
     * Returns an array with the rendered HTML, subject, and preference flags.
     */
    protected function prepare_mail_content($recipient, $announcements, $myconnect_direct_posts, $myconnect_mentee_posts) {
        global $OUTPUT, $DB;

        $config = get_config('local_announcements');

        $subject = get_string('digest:mailsubject', 'local_announcements', fullname($recipient));
        $sentcount = count($announcements);

        $content = [
            'posts' => $announcements,
            'myconnectposts' => $myconnect_direct_posts,
            'myconnectmenteeposts' => $myconnect_mentee_posts,
            'hasposts' => !empty($announcements),
            'hasmyconnectposts' => !empty($myconnect_direct_posts) || !empty($myconnect_mentee_posts),
            'userprefs' => (new \moodle_url('/local/announcements/preferences.php'))->out(false),
            'myconnecturl' => (new \moodle_url('/local/myconnect/index.php'))->out(false),
            'announcementsurl' => (new \moodle_url('/local/announcements/index.php'))->out(false),
            'myconnectheaderimage' => $config->myconnectheaderimage,
            'digestheaderimage' => $config->digestheaderimage,
            'digestfooterimage' => $config->digestfooterimage,
            'digestfooterimageurl' => $config->digestfooterimageurl,
            'digestfootercredits' => $config->digestfootercredits,
        ];

        $digesthtml = $OUTPUT->render_from_template('local_announcements/message_digest_email', $content);

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

            $digesttext = $OUTPUT->render_from_template('local_announcements/message_digest', $maildata['content']);
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
