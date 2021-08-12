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
            $myconnectpostdefs = $myconnectposts = array();
            if ($inclmyconnect) {
                $myconnectpostdefs = (array) $posttypes->myconnectposts;
                $this->log("myconnectpostdefs: " . json_encode($myconnectpostdefs), 1);
                $myconnectposts = \local_myconnect\persistents\post::prepare_data(
                    $myconnectpostdefs, 
                    $recipient, 
                    $this->get_trace()
                );
            }
            
            if (empty($announcements) && empty($myconnectposts)) {
                $this->log_finish("No posts found to send.");
                continue;
            }

            // This digest has at least one post and should therefore be sent.
            if ($this->send_mail($recipient, $announcements, $myconnectposts)) {
                $announcementidstr = implode(", ", $postids);
                $myconnectidstr = implode(", ", array_keys($myconnectpostdefs));
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
    protected function send_mail($recipient, $announcements, $myconnectposts) {
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
        );

        // Set the subject of the message.
        $postsubject = get_string('digest:mailsubject', 'local_announcements', fullname($recipient));

        // Render the digest template with the posts
        $content = [
            'posts' => $announcements,
            'myconnectposts' => $myconnectposts,
            'hasposts' => !empty($announcements),
            'hasmyconnectposts' => !empty($myconnectposts),
            'userprefs' => (new \moodle_url('/local/announcements/preferences.php'))->out(false),
            'myconnecturl' => (new \moodle_url('/local/myconnect/index.php'))->out(false),
            'announcementsurl' => (new \moodle_url('/local/announcements/index.php'))->out(false),
            'myconnectheaderimage' => $config->myconnectheaderimage,
            'digestheaderimage' => $config->digestheaderimage,
            'digestfooterimage' => $config->digestfooterimage,
            'digestfooterimageurl' => $config->digestfooterimageurl,
            'digestfootercredits' => $config->digestfootercredits,
        ];

        // Commented mailformat check out. Send both and allow the client to determine display.
        //$notificationhtml = $notificationtext = '';
        //if (empty($recipient->mailformat) || $recipient->mailformat != 1) {
            // This user does not want to receive HTML. Send text version.
            $notificationtext = $OUTPUT->render_from_template('local_announcements/message_digest_text', $content);
        //} else {
            // Send HTML version of digest.
            $notificationhtml = $OUTPUT->render_from_template('local_announcements/message_digest_html', $content);
        //}

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