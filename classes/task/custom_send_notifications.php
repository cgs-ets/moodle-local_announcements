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
 * Processes the notification queue and sends notifications/emails.
 *
 * @package   local_announcements
 * @copyright 2026 Michael Vangelovski
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_announcements\task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/../utils.php');
require_once($CFG->dirroot . '/local/announcements/locallib.php');

use local_announcements\persistents\announcement;
use local_announcements\external\announcement_exporter;
use \local_announcements\utils;


class custom_send_notifications {

    /** @var digest_logger */
    protected $logger;

    /** @var \stdClass The current recipient. */
    protected $recipient;

    /**
     * Constructor.
     *
     * @param digest_logger $logger
     */
    public function __construct(digest_logger $logger) {
        $this->logger = $logger;
    }

    /**
     * Process pending notification queue entries.
     */
    public function execute() {
        global $DB, $CFG;

        $config = get_config('local_announcements');
        $batchsize = isset($config->cronsendnum) ? (int) $config->cronsendnum : 1;

        $this->logger->log("Sending Notifications " . date('Y-m-d H:i:s') . ".");

        // Initialise $USER, $PAGE, and $OUTPUT for CLI context.
        \core\cron::setup_user();
        \core\cron::prepare_core_renderer();

        // Read up to N pending rows from the queue.
        $rows = $DB->get_records_select(
            'ann_notifications_queue',
            'status = 0',
            null,
            'id ASC',
            '*',
            0,
            $batchsize
        );

        if (empty($rows)) {
            $this->logger->log("No pending notification queue entries found.", 1);
            return;
        }

        $this->logger->log("Found " . count($rows) . " pending notification(s) to process.", 1);

        // Mark selected rows as processing (status = 1).
        $ids = array_keys($rows);
        list($insql, $params) = $DB->get_in_or_equal($ids);
        $DB->set_field_select('ann_notifications_queue', 'status', 1, "id {$insql}", $params);

        foreach ($rows as $row) {
            try {
                $data = json_decode($row->customdata);
                $postids = $data->posts;

                $this->recipient = \core_user::get_user_by_username($row->username);

                if (!$this->recipient || $this->recipient->deleted) {
                    $this->logger->log("User {$row->username} not found or deleted. Skipping.", 1);
                    $DB->set_field('ann_notifications_queue', 'status', 3, ['id' => $row->id]);
                    $DB->set_field('ann_notifications_queue', 'timeprocessed', time(), ['id' => $row->id]);
                    continue;
                }

                $this->minimise_recipient_record();

                // Switch $USER to the recipient so tokenized URLs are generated for them.
                \core\cron::setup_user($this->recipient);
                \core\cron::prepare_core_renderer();

                $this->logger->log("Processing notifications for {$this->recipient->username} ({$this->recipient->id}). Posts: " . implode(',', $postids), 1);

                $posts = $this->prepare_data($postids);

                if (empty($posts)) {
                    $this->logger->log("No posts found to send for {$this->recipient->username}.", 1);
                    $DB->set_field('ann_notifications_queue', 'status', 2, ['id' => $row->id]);
                    $DB->set_field('ann_notifications_queue', 'timeprocessed', time(), ['id' => $row->id]);
                    continue;
                }

                $errorcount = 0;
                $sentcount = 0;
                foreach ($posts as $post) {
                    if ($this->send_post($post)) {
                        $sentcount++;
                    } else {
                        $errorcount++;
                    }
                }

                $this->logger->log("Sent {$sentcount} notifications with {$errorcount} failures for {$this->recipient->username}.", 1);

                $DB->set_field('ann_notifications_queue', 'status', 2, ['id' => $row->id]);
                $DB->set_field('ann_notifications_queue', 'timeprocessed', time(), ['id' => $row->id]);

            } catch (\Exception $e) {
                $this->logger->log("Error processing notifications for {$row->username}: " . $e->getMessage(), 1);
                $DB->set_field('ann_notifications_queue', 'status', 3, ['id' => $row->id]);
                $DB->set_field('ann_notifications_queue', 'timeprocessed', time(), ['id' => $row->id]);
            }
        }

        // Reset $USER back to the cron admin user.
        \core\cron::setup_user();
    }

    /**
     * Prepare all data for this run.
     *
     * @param   int[]   $postids The list of post IDs
     */
    protected function prepare_data($postids) {
        global $OUTPUT, $PAGE;

        if (empty($postids)) {
            return [];
        }

        $posts = array();
        $announcements = announcement::get_by_ids_and_username($postids, $this->recipient->username);

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
            return [];
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

        if ($post->timemodified > $post->timecreated) {
            $postsubject = '[Updated] ' . $postsubject;
        }

        // Message headers are stored against the message author.
        $userfrom = \core_user::get_noreply_user();
        $userfrom->customheaders = $this->get_message_headers($post, $a);

        $fullmessagehtml = $OUTPUT->render_from_template('local_announcements/message_notification_email', $data);

        $result = false;

        // CHECK USER PREFERENCES.
        $notify = $DB->get_field('ann_user_preferences', 'notify', array('username' => $this->recipient->username));
        if ($notify === false) {
            // By default, send notifications.
            $notify = 1;
        }

        $email = $DB->get_field('ann_user_preferences', 'email', array('username' => $this->recipient->username));
        if ($email === false) {
            // By default, do not send emails.
            $email = 0;
        }

        if ($post->forcesend) {
            $notify = $email = 1;
        }

        // THE EMAIL.
        if ($email == 1) {
            $result = utils::email_to_user($this->recipient, $userfrom, $postsubject, '', $fullmessagehtml, '');
            $this->logger->log("Email sent for announcement {$post->id}", 2);
        }

        // THE NOTIFICATION.
        try {
            if ($notify == 1) {
                $notificationhtml = $OUTPUT->render_from_template('local_announcements/message_notification', $data);
                $eventdata = new \core\message\message();
                $eventdata->courseid = SITEID;
                $eventdata->component = 'local_announcements';
                $eventdata->name = 'notificationsv2';
                $eventdata->userfrom = $userfrom;
                $eventdata->userto = $this->recipient;
                $eventdata->subject = $postsubject;
                $eventdata->fullmessage = '';
                $eventdata->fullmessageformat = FORMAT_PLAIN;
                $eventdata->fullmessagehtml = $notificationhtml;
                $eventdata->notification = 1;
                $eventdata->smallmessage = get_string('notification:smallmessage', 'local_announcements', (object) [
                    'user' => $post->authorfullname,
                    'subject' => $postsubject,
                ]);
                $result = message_send($eventdata);
                $this->logger->log("Notification sent for announcement {$post->id}", 2);
            } else {
                $this->logger->log("User {$this->recipient->username} does not want notifications. Not sending {$post->id}.", 2);
            }
        } catch (\Exception $e) {
            $this->logger->log("Error sending notification: " . $e->getMessage(), 2);
        }

        return $result;
    }

    /**
     * Get the list of message headers.
     *
     * @param   \stdClass   $post
     * @param   \stdClass   $a The list of strings for this post
     * @return  array
     */
    protected function get_message_headers($post, $a) {
        $viewurl = new \moodle_url('/local/announcements/index.php');
        $headers = [
            'List-Id: ' . generate_email_messageid('lannouncement_' . $this->recipient->id . '_' . $post->id),
            'List-Help: ' . $viewurl->out(),
            'Message-ID: ' . generate_email_messageid(hash('sha256', $post->id . 'to' . $this->recipient->id)),
            'Precedence: Bulk',
            'X-Auto-Response-Suppress: All',
            'Auto-Submitted: auto-generated',
        ];
        return $headers;
    }

    /**
     * Removes properties from user record that are not necessary for sending post notifications.
     */
    protected function minimise_recipient_record() {
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
