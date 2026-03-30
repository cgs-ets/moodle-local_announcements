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
 * Processes the notification queue and sends emails via Postmark.
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
     * Process pending notification queue entries and send emails via Postmark.
     */
    public function execute() {
        global $DB, $CFG;

        $config = get_config('local_announcements');
        $batchsize = isset($config->cronsendnum) ? (int) $config->cronsendnum : 1;

        $this->logger->log("Sending Notification Emails " . date('Y-m-d H:i:s') . ".");

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

        $postmarkapikey = isset($config->postmarkapikey) ? $config->postmarkapikey : '';
        $postmarkfromemail = isset($config->postmarkfromemail) ? $config->postmarkfromemail : '';

        // Phase 1: Loop rows, prepare data, check email pref, render templates, collect Postmark batch payloads.
        $emailbatch = [];
        $batchmeta = [];

        foreach ($rows as $row) {
            try {
                $data = json_decode($row->customdata);

                // Support new format (array of objects with id+forcesend) and old format (array of plain IDs).
                $forcesendmap = [];
                if (!empty($data->posts) && is_object($data->posts[0])) {
                    $postids = array_map(function($p) { return $p->id; }, $data->posts);
                    foreach ($data->posts as $p) {
                        $forcesendmap[$p->id] = !empty($p->forcesend);
                    }
                } else {
                    $postids = $data->posts;
                }

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

                $this->logger->log("Processing notification emails for {$this->recipient->username} ({$this->recipient->id}). Posts: " . implode(',', $postids), 1);

                $posts = $this->prepare_data($postids);

                if (empty($posts)) {
                    $this->logger->log("No posts found to send for {$this->recipient->username}.", 1);
                    $DB->set_field('ann_notifications_queue', 'status', 2, ['id' => $row->id]);
                    $DB->set_field('ann_notifications_queue', 'timeprocessed', time(), ['id' => $row->id]);
                    continue;
                }

                // Check user email preference.
                $email = $DB->get_field('ann_user_preferences', 'email', array('username' => $this->recipient->username));
                if ($email === false) {
                    // By default, do not send emails.
                    $email = 0;
                }

                $emailcount = 0;
                foreach ($posts as $post) {
                    // Check forcesend override. Use the value captured at queue time if available,
                    // otherwise fall back to the live post value (old queue format).
                    $postemail = $email;
                    $isforcesend = !empty($forcesendmap) ? !empty($forcesendmap[$post->id]) : $post->forcesend;
                    if ($isforcesend) {
                        $postemail = 1;
                    }

                    if ($postemail != 1) {
                        $this->logger->log("User {$this->recipient->username} does not want emails. Not sending {$post->id}.", 2);
                        continue;
                    }

                    $payload = $this->prepare_email_payload($post, $postmarkfromemail);
                    if ($payload) {
                        $emailbatch[] = $payload;
                        $emailcount++;
                    }
                }

                $this->logger->log("Collected {$emailcount} email payloads for {$this->recipient->username}.", 1);

                $batchmeta[] = [
                    'row' => $row,
                    'recipient' => $this->recipient,
                    'emailcount' => $emailcount,
                ];

            } catch (\Exception $e) {
                $this->logger->log("Error processing notification emails for {$row->username}: " . $e->getMessage(), 1);
                $DB->set_field('ann_notifications_queue', 'status', 3, ['id' => $row->id]);
                $DB->set_field('ann_notifications_queue', 'timeprocessed', time(), ['id' => $row->id]);
            }
        }

        // Reset $USER back to the cron admin user.
        \core\cron::setup_user();

        // Mark all rows that made it to batchmeta as complete (they were processed successfully).
        foreach ($batchmeta as $meta) {
            $DB->set_field('ann_notifications_queue', 'status', 2, ['id' => $meta['row']->id]);
            $DB->set_field('ann_notifications_queue', 'timeprocessed', time(), ['id' => $meta['row']->id]);
        }

        // Phase 2: Send all collected emails in a single Postmark batch.
        if (empty($emailbatch)) {
            $this->logger->log("No emails to send.", 1);
            return;
        }

        if (empty($postmarkapikey) || empty($postmarkfromemail)) {
            $this->logger->log("PostMark API key or from email not configured. Skipping emails.", 1);
            return;
        }

        try {
            $client = new PostmarkClient($postmarkapikey);
            $this->logger->log("Sending PostMark batch of " . count($emailbatch) . " email(s).", 1);
            $results = $client->sendEmailBatch($emailbatch);

            foreach ($results as $i => $result) {
                if ($result->ErrorCode === 0) {
                    $this->logger->log("PostMark email sent: {$emailbatch[$i]['To']} - {$emailbatch[$i]['Subject']}", 2);
                } else {
                    $this->logger->log("PostMark error for {$emailbatch[$i]['To']}: [{$result->ErrorCode}] {$result->Message}", 2);
                }
            }
        } catch (\Exception $e) {
            $this->logger->log("PostMark batch error: " . $e->getMessage(), 1);
        }
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
     * Prepare a single Postmark email payload for a post.
     *
     * @param   \stdClass   $post
     * @param   string      $fromEmail
     * @return  array|null
     */
    protected function prepare_email_payload($post, $fromEmail) {
        global $OUTPUT;

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

        $fullmessagehtml = $OUTPUT->render_from_template('local_announcements/message_notification_email', $data);

        // Convert message headers to Postmark key-value format.
        $headers = $this->get_message_headers($post, $a);

        return [
            'From' => $fromEmail,
            'To' => $this->recipient->email,
            'Subject' => $postsubject,
            'HtmlBody' => $fullmessagehtml,
            'TextBody' => '',
            'Headers' => $headers,
        ];
    }

    /**
     * Get the list of message headers as Postmark key-value pairs.
     *
     * @param   \stdClass   $post
     * @param   \stdClass   $a The list of strings for this post
     * @return  array
     */
    protected function get_message_headers($post, $a) {
        return [
            'Precedence' => 'Bulk',
            'X-Auto-Response-Suppress' => 'All',
            'Auto-Submitted' => 'auto-generated',
        ];
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
