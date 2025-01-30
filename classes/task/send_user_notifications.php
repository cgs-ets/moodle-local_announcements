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

/**
 * Adhoc task to send user notifications.
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_user_notifications extends \core\task\adhoc_task {

    // Use the logging trait to get some nice, juicy, logging.
    use \core\task\logging_trait;

    /**
     * @var \stdClass   A shortcut to $USER.
     */
    protected $recipient;

    /**
     * Send out messages.
     */
    public function execute() {

        // Raise the time limit.
        \core_php_time_limit::raise(120);

        $data = (array) $this->get_custom_data();
        $this->log("Processing the following notifications: " . json_encode($data), 1);


        $errorcount = 0;
        $sentcount = 0;   
        foreach ($data as $userid => $postids) {
            $this->recipient = \core_user::get_user($userid);
            $this->minimise_recipient_record();
            $this->log("Recipient is {$this->recipient->username} ({$this->recipient->id})", 1);

            $posts = $this->prepare_data($postids);

            foreach ($posts as $post) {
                if ($this->send_post($post)) {
                    $this->log("Announcement {$post->id} sent", 1);
                    $sentcount++;
                } else {
                    $this->log("Failed to send announcement {$post->id}", 1);
                    $errorcount++;
                }
            }
        }

        $this->log_finish("Sent {$sentcount} messages in batch with {$errorcount} failures");
    }

    /**
     * Prepare all data for this run.
     *
     * @param   int[]   $postids The list of post IDs
     */
    protected function prepare_data(array $postids) {
        global $OUTPUT;

        if (empty($postids)) {
            return;
        }

        $posts = array();

        $announcements = announcement::get_by_ids_and_username($postids, $this->recipient->username);
        $this->log("Announcement data retrieved for: " . implode(',', array_keys($announcements)), 1);
        
        $context = \context_system::instance();
        foreach ($announcements as $announcement) {
            $exporter = new announcement_exporter($announcement->persistent, [
                'context' => $context,
                'audiences' => $announcement->audiences,
            ]);
            $posts[] = $exporter->export($OUTPUT);
        }
        $this->log("Announcement data exported and ready for sending: " . implode(',', array_column($posts, 'id')), 1);

        if (empty($posts)) {
            // All posts have been removed since the task was queued.
            return;
        }

        return $posts;
    }

    /**
     * Send the specified post for the current user.
     *
     * @param   \stdClass   $post
     */
    protected function send_post($post) {
        global $DB, $PAGE, $OUTPUT;

        $config = get_config('local_announcements');

        $data = array (
            'posts' => array($post),
            'forcesendheaderimage' => $config->forcesendheaderimage,
            'userprefs' => (new \moodle_url('/local/announcements/preferences.php'))->out(false),
        );

        // Not all of these variables are used in the default string but are made available to support custom subjects.
        $site = get_site();
        $a = (object) [
            'subject' => $post->subject,
            'sitefullname' => format_string($site->fullname),
            'siteshortname' => format_string($site->shortname),
        ];
        $postsubject = html_to_text(get_string('notification:subject', 'local_announcements', $a), 0);

        // Message headers are stored against the message author.
        $userfrom = \core_user::get_noreply_user();
        $userfrom->customheaders = $this->get_message_headers($post, $a);

        $eventdata = new \core\message\message();
        $eventdata->courseid            = SITEID;
        $eventdata->component           = 'local_announcements';
        $eventdata->name                = 'notifications';
        if ($post->forcesend) {
            $eventdata->name            = 'forced';
        }
        $eventdata->userfrom            = $userfrom;
        $eventdata->userto              = $this->recipient;
        $eventdata->subject             = $postsubject;
        $eventdata->fullmessage         = $post->messageplain;
        $eventdata->fullmessageformat   = FORMAT_PLAIN;
        $fullmessagehtml                = $OUTPUT->render_from_template('local_announcements/message_notification_html', $data);
        $eventdata->fullmessagehtml     = $fullmessagehtml;
        $eventdata->notification        = 1;
        $eventdata->smallmessage = get_string('notification:smallmessage', 'local_announcements', (object) [
            'user' => $post->authorfullname,
            'subject' => $postsubject,
        ]);

        $contexturl = new \moodle_url('/local/announcements/view.php', ['id' => $post->id]);
        $eventdata->contexturl = $contexturl->out();
        $eventdata->contexturlname = get_string('pluginname', 'local_announcements');

        // Author profile photo.
        $author = $DB->get_record('user', array('username' => $post->authorusername));
        if (empty($author)) {
            return false;
        }
        $userpicture = new \user_picture($author);
        $userpicture->includetoken = $this->recipient->id; // Generate an out-of-session token for the user receiving the message.

        $eventdata->customdata = [
            'postid' => $post->id,
            'notificationiconurl' => $userpicture->get_url($PAGE)->out(false),
        ];

        // Send email/web notification
        $result = message_send($eventdata);
        return $result;
        // Send mobile notification
        //$eventdata->name                = 'notificationsmobile';
        //if ($post->forcesend) {
        //    $eventdata->name            = 'forcedmobile';
        //}
        //$eventdata->fullmessage         = $post->shortmessageplain;
        //$eventdata->fullmessageformat   = FORMAT_PLAIN;
        //$eventdata->fullmessagehtml     = $post->shortmessage;
        //return message_send($eventdata);

    }

    /**
     * Get the list of message headers.
     *
     * @param   \stdClass   $post
     * @param   \stdClass   $a The list of strings for this  post
     * @return  \stdClass
     */
    protected function get_message_headers($post, $a) {
        $viewurl = new \moodle_url('/local/announcements/index.php');
        $headers = [
            // Headers to make emails easier to track.
            'List-Id: ' . generate_email_messageid('lannouncement_' . $this->recipient->id . '_' . $post->id),
            'List-Help: ' . $viewurl->out(),
            'Message-ID: ' . generate_email_messageid(hash('sha256', $post->id . 'to' . $this->recipient->id)),

            // Headers to help prevent auto-responders.
            'Precedence: Bulk',
            'X-Auto-Response-Suppress: All',
            'Auto-Submitted: auto-generated',

            // Header to send via Postmark Broadcast stream.
            //'X-PM-Message-Stream: broadcasts',
        ];
        return $headers;
    }
    
   
    /**
     * Removes properties from user record that are not necessary for sending post notifications.
     *
     */
    protected function minimise_recipient_record() {
        // Make sure we do not store info there we do not actually
        // need in mail generation code or messaging.
        unset($this->recipient->institution);
        unset($this->recipient->department);
        unset($this->recipient->address);
        unset($this->recipient->city);
        unset($this->recipient->url);
        unset($this->recipient->currentlogin);
        unset($this->recipient->description);
        unset($this->recipient->descriptionformat);
        unset($this->recipient->icq);
        unset($this->recipient->skype);
        unset($this->recipient->yahoo);
        unset($this->recipient->aim);
        unset($this->recipient->msn);
        unset($this->recipient->phone1);
        unset($this->recipient->phone2);
        unset($this->recipient->country);
        unset($this->recipient->firstaccess);
        unset($this->recipient->lastaccess);
        unset($this->recipient->lastlogin);
        unset($this->recipient->lastip);
    }
}
