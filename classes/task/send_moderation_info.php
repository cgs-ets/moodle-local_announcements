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
 * This file defines an adhoc task to send moderation info to a moderator.
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_announcements\task;

defined('MOODLE_INTERNAL') || die();

use local_announcements\providers\moderation;
use local_announcements\persistents\announcement;
use local_announcements\external\announcement_exporter;

/**
 * Adhoc task to send notification to moderator about post that requires mod.
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_moderation_info extends \core\task\adhoc_task {

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
        global $DB;

        $config = get_config('local_announcements');
        if (!$config->enablenotify) {
            $this->log("Notifications are not enabled in plugin configuration. Exiting.");
            return;
        }

        // Raise the time limit.
        \core_php_time_limit::raise(120);

        // Get recipient user record.
        $this->recipient = \core_user::get_user($this->get_userid());
        $this->minimise_recipient_record();
        $this->log_start("Sending moderation info to {$this->recipient->username} ({$this->recipient->id})");

        // Get the custom data which includes the moderation record id, and what type of message this is.
        $data = $this->get_custom_data();

        // Get the moderation info.
        $postsmoderationid = $data->postsmoderationid;
        $moderation = $DB->get_record('ann_posts_moderation', array('id' => $postsmoderationid));
        if (empty($moderation)) {
            $this->log("Failed to find ann_posts_moderation record id = {$postsmoderationid}", 1);
            return;
        }

        // Send the moderation info.
        if ($this->send($moderation, $data->messagetype)) {
            moderation::mod_setmailed($postsmoderationid);
            $this->log("Moderation record {$moderation->id} sent", 1);
        } else {
            $this->log("Failed to send moderation notification ann_posts_moderation record id = {$moderation->id}", 1);
        }

    }

    /**
     * Send the mail.
     *
     * @param   \stdClass   $moderation
     */
    protected function send($moderation, $messagetype) {
        global $DB, $PAGE, $OUTPUT;

        // Get the post.
        $announcement = announcement::get_with_all_audiences($moderation->postid);
        
        // Export it.
        $context = \context_system::instance();
        $exporter = new announcement_exporter($announcement->persistent, [
            'context' => $context,
            'audiences' => $announcement->audiences,
        ]);
        $post = $exporter->export($OUTPUT);

        // Message headers are stored against the message author.
        $userfrom = \core_user::get_noreply_user();
        $userfrom->customheaders = $this->get_message_headers($post);

        // Set up the message content.
        $message = new \core\message\message();
        $message->courseid = SITEID;
        $message->component = 'local_announcements';
        $message->name = 'moderationmail';
        $message->userfrom = $userfrom;
        $message->userto = $this->recipient;
        $message->fullmessage = $post->messageplain;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->notification = 1;
        $message->smallmessage = '';

        // Set up action URLs.
        $contexturl = new \moodle_url('/local/announcements/moderation.php');
        $message->contexturl = $contexturl->out();
        $message->contexturlname = get_string('moderation:heading', 'local_announcements');

        // Default sender (for icon) is announcement author.
        $sender = $DB->get_record('user', array('username' => $post->authorusername));

        if ($messagetype == "PENDING") {
            $postsubject = html_to_text(get_string('moderation:mailsubject', 'local_announcements', $post), 0);
            $message->subject = $postsubject;
            $token = get_user_key('local_announcements', $this->recipient->id);
            $approveurl = new \moodle_url('/local/announcements/tokenmoderation.php', array(
                'token' => $token,
                'postid' => $post->id,
                'modid' => $moderation->id,
                'action' => 'approve',
            ));
            $moderaionurl = new \moodle_url('/local/announcements/moderation.php', ['id' => $post->id]);
            $templatedata = array (
                'posts' => array($post),
                'approve_url' => $approveurl->out(),
                'moderation_url' => $moderaionurl->out(),
            );
            $message->fullmessagehtml = $OUTPUT->render_from_template(
                'local_announcements/message_mod_pending_html', 
                $templatedata
            );
        }
        else if ($messagetype == "REJECTED") {
            $postsubject = html_to_text(get_string('moderation:rejectedmailsubject', 'local_announcements', $post->subject), 0);
            $message->subject = $postsubject;
            $contexturl = new \moodle_url('/local/announcements/view.php', ['id' => $post->id]);
            $message->contexturl = $contexturl->out();
            $message->contexturlname = html_to_text($post->subject);
            $sender = $DB->get_record('user', array('username' => $moderation->actionedusername));
            $modfullname = '';
            if ($moderation->actionedusername != $moderation->modusername) {
                $moduser = $DB->get_record('user', array('username' => $moderation->modusername));
                $modfullname = fullname($moduser);
            }
            $templatedata = array (
                'posts' => array($post),
                'actionedby' => fullname($sender),
                'moderator' => $modfullname,
                'comment' => $moderation->comment,
            );
            $message->fullmessagehtml = $OUTPUT->render_from_template(
                'local_announcements/message_mod_rejected_html', 
                $templatedata
            );
        }
        else if ($messagetype == "APPROVED") {
            $postsubject = html_to_text(get_string('moderation:approvedmailsubject', 'local_announcements', $post->subject), 0);
            $message->subject = $postsubject;
            $contexturl = new \moodle_url('/local/announcements/view.php', ['id' => $post->id]);
            $message->contexturl = $contexturl->out();
            $message->contexturlname = html_to_text($post->subject);
            $sender = $DB->get_record('user', array('username' => $moderation->actionedusername));
            $modfullname = '';
            if ($moderation->actionedusername != $moderation->modusername) {
                $moduser = $DB->get_record('user', array('username' => $moderation->modusername));
                $modfullname = fullname($moduser);
            }
            $templatedata = array (
                'posts' => array($post),
                'actionedby' => fullname($sender),
                'moderator' => $modfullname,
            );
            $message->fullmessagehtml = $OUTPUT->render_from_template(
                'local_announcements/message_mod_approved_html', 
                $templatedata
            );
        }
        else {
            return;
        }
        
        // Notification icon.
        $userpicture = new \user_picture($sender);
        $userpicture->includetoken = $this->recipient->id; // Generate an out-of-session token for the user receiving the message.
        $message->customdata = [
            'postid' => $post->id,
            'notificationiconurl' => $userpicture->get_url($PAGE)->out(false),
        ];

        return message_send($message);
    }

    /**
     * Get the list of message headers.
     *
     * @param   \stdClass   $post
     * @return  \stdClass
     */
    protected function get_message_headers($post) {
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