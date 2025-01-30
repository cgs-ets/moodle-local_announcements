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
 * This file defines an adhoc task to send notifications.
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_announcements\task;

defined('MOODLE_INTERNAL') || die();

use local_announcements\persistents\announcement;
use local_announcements\external\announcement_exporter;
require_once($CFG->dirroot . '/local/announcements/locallib.php');

/**
 * Adhoc task to send announcement digests for the specified user.
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_user_digests extends \core\task\adhoc_task {

    // Use the logging trait to get some nice, juicy, logging.
    use \core\task\logging_trait;

    /**
     * @var int     The number of messages sent in this digest.
     */
    protected $sentcount = 0;

    /**
     * @var int     The number of myconnect messages sent in this digest.
     */
    protected $myconnectsentcount = 0;

    /**
     * Send out messages.
     */
    public function execute() {
        global $CFG;

        $config = get_config('local_announcements');

        $starttime = time();

        $data = (array) $this->get_custom_data();
        $this->log("Processing the following digests: " . json_encode($data), 1);

        $inclmyconnect = $this->include_myconnect();

        foreach ($data as $userid => $posttypes) {
            $this->log("posttypes: " . json_encode($posttypes), 1);
            $recipient = \core_user::get_user($userid);

            $this->log_start("Sending announcement digests for {$recipient->username} ({$recipient->id})");

            // Prepare announcement posts.
            $postids = $posttypes->announcements;
            $announcements = $this->prepare_data($postids, $recipient);

            // Prepare myconnect posts.
            /*$myconnectpostdefs = $myconnectposts = array();
            if ($inclmyconnect) {
                // Deep convert postdefs to array.
                $myconnectpostdefs = json_decode(json_encode($posttypes->myconnectposts), true);
                $this->log("myconnectpostdefs: " . json_encode($myconnectpostdefs), 1);
                $myconnectposts = \local_myconnect\persistents\post::prepare_data(
                    $myconnectpostdefs, 
                    $recipient, 
                    $this->get_trace()
                );
            }*/

            $myconnectposts = array();
            $myconnect_mentee_posts = array();
            if ($inclmyconnect) {

                $this->log("Direct MyConnect posts: " . json_encode($posttypes->myconnectposts), 1);
                $myconnectpostids = json_decode(json_encode($posttypes->myconnectposts), true);
                $myconnectposts = \local_myconnect\persistents\post::prepare_data(
                    $myconnectpostids, 
                    $recipient, 
                    $this->get_trace()
                );

                if (isset($posttypes->myconnectmenteeposts)) {
                    $this->log("Mentee MyConnect posts: " . json_encode($posttypes->myconnectmenteeposts), 1);
                    $myconnectmenteeposts = json_decode(json_encode($posttypes->myconnectmenteeposts), true);
                    foreach ($myconnectmenteeposts as $menteeid => $menteeposts) {
                        $preparedposts = \local_myconnect\persistents\post::prepare_data_for_mentee(
                            $menteeposts,
                            $menteeid,
                            $recipient,
                            $this->get_trace()
                        );
                        $mentee = \core_user::get_user($menteeid);
                        $myconnect_mentee_posts[] = array(
                            'user' => $mentee,
                            'posts' => $preparedposts
                        );
                    }
                }

            }


            //var_export($myconnectposts); exit;
            
            if (empty($announcements) && empty($myconnectposts) && empty($myconnect_mentee_posts)) {
                $this->log_finish("No posts found to send.");
                continue;
            }

            // This digest has at least one post and should therefore be sent.
            if ($this->send_mail($recipient, $announcements, $myconnectposts, $myconnect_mentee_posts)) {
                $announcementidstr = implode(", ", $postids);
                $myconnectidstr = implode(", ", $myconnectpostids);
                $myconnectmenteepostidsstr = implode(", ", $myconnectpostids);
                $this->log_finish("Digest sent with {$this->sentcount} announcements, and {$this->myconnectsentcount} myconnect posts. Announcement IDs [{$announcementidstr}]. MyConnect post ids [{$myconnectidstr}].");
            } else {
                $this->log_finish("Issue sending digest. Skipping.");
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
        global $OUTPUT;

        if (empty($postids)) {
            return [];
        }

        $posts = array();

        $announcements = announcement::get_by_ids_and_username($postids, $recipient->username);
        
        $context = \context_system::instance();
        foreach ($announcements as $announcement) {
            $exporter = new announcement_exporter($announcement->persistent, [
                'context' => $context,
                'audiences' => $announcement->audiences,
            ]);
            $posts[] = $exporter->export($OUTPUT);
        }

        if (empty($posts)) {
            // All posts have been removed since the task was queued.
            return [];
        }

        return $posts;
    }

    /**
     * Send the composed message to the user.
     */
    protected function send_mail($recipient, $announcements, $myconnect_direct_posts, $myconnect_mentee_posts) {
        global $OUTPUT;

        $config = get_config('local_announcements');
        $this->sentcount = 0;
        $this->myconnectsentcount = 0;

        // Headers to help prevent auto-responders.
        $userfrom = \core_user::get_noreply_user();
        $userfrom->customheaders = array(
            'Precedence: Bulk',
            'X-Auto-Response-Suppress: All',
            'Auto-Submitted: auto-generated',
            
            // Header to send via Postmark Broadcast stream.
            //'X-PM-Message-Stream: broadcasts',
        );

        // Set the subject of the message.
        $postsubject = get_string('digest:mailsubject', 'local_announcements', fullname($recipient));

        // Render the digest template with the posts
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

        $notificationtext = $OUTPUT->render_from_template('local_announcements/message_digest_text', $content);
        $notificationhtml = $OUTPUT->render_from_template('local_announcements/message_digest_html', $content);

        $this->sentcount = count($announcements);
        $this->myconnectsentcount = isset($myconnectposts) ? count($myconnectposts) : 0;

        $eventdata = new \core\message\message();
        $eventdata->courseid = SITEID;
        $eventdata->component = 'local_announcements';
        $eventdata->name = 'digests';
        $eventdata->userfrom = $userfrom;
        $eventdata->userto = $recipient;
        $eventdata->subject = $postsubject;
        $eventdata->fullmessage = $notificationtext;
        $eventdata->fullmessageformat = FORMAT_PLAIN;
        $eventdata->fullmessagehtml = $notificationhtml;
        $eventdata->notification = 1;
        $eventdata->smallmessage = get_string('digest:smallmessage', 'local_announcements', ($this->sentcount + $this->myconnectsentcount));

        return message_send($eventdata);
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