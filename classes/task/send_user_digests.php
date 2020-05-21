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
     * @var \stdClass   A shortcut to $USER.
     */
    protected $recipient;

    /**
     * @var \stdClass[] The posts to be sent.
     */
    protected $posts = [];

    /**
     * @var bool    Whether the user has requested HTML or not.
     */
    protected $allowhtml = true;

    /**
     * @var string  The subject of the message.
     */
    protected $postsubject = '';

    /**
     * @var string  The plaintext content of the whole message.
     */
    protected $notificationtext = '';

    /**
     * @var string  The HTML content of the whole message.
     */
    protected $notificationhtml = '';

    /**
     * @var int     The number of messages sent in this digest.
     */
    protected $sentcount = 0;

    /**
     * Send out messages.
     */
    public function execute() {
        $starttime = time();

        //for testing purposes:
        //$this->set_userid(3);

        $this->recipient = \core_user::get_user($this->get_userid());
        $this->log_start("Sending announcement digests for {$this->recipient->username} ({$this->recipient->id})");

        if (empty($this->recipient->mailformat) || $this->recipient->mailformat != 1) {
            // This user does not want to receive HTML.
            $this->allowhtml = false;
        }

        // Fetch all of the data we need to mail these posts.
        $data = $this->get_custom_data();
        $postids = (array) $data;
        $this->prepare_data($postids);

        if (empty($this->posts)) {
            $this->log_finish("No messages found to send.");
            return;
        }

        // This digest has at least one post and should therefore be sent.
        if ($this->send_mail()) {
            $idstr = implode(", ", $postids);
            $this->log_finish("Digest sent with {$this->sentcount} announcements. Announcement IDs {$idstr}.");
        } else {
            $this->log_finish("Issue sending digest. Skipping.");
        }

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

        $announcements = announcement::get_by_ids_and_username($postids, $this->recipient->username);
        
        $context = \context_system::instance();
        foreach ($announcements as $announcement) {
            $exporter = new announcement_exporter($announcement->persistent, [
                'context' => $context,
                'audiences' => $announcement->audiences,
            ]);
            $this->posts[] = $exporter->export($OUTPUT);
        }

        if (empty($this->posts)) {
            // All posts have been removed since the task was queued.
            return;
        }
    }

    /**
     * Send the composed message to the user.
     */
    protected function send_mail() {
        global $OUTPUT;

        $config = get_config('local_announcements');

        // Headers to help prevent auto-responders.
        $userfrom = \core_user::get_noreply_user();
        $userfrom->customheaders = array(
            'Precedence: Bulk',
            'X-Auto-Response-Suppress: All',
            'Auto-Submitted: auto-generated',
        );

        // Set the subject of the message.
        $this->postsubject = get_string('digest:mailsubject', 'local_announcements');

        // Render the digest template with the posts
        $content = [
            'posts' => $this->posts,
            'userprefs' => (new \moodle_url('/local/announcements/preferences.php'))->out(false),
            'digestheaderimage' => $config->digestheaderimage,
            'digestfooterimage' => $config->digestfooterimage,
            'digestfooterimageurl' => $config->digestfooterimageurl,
        ];
        
        if ($this->allowhtml) {
            $this->notificationhtml = $OUTPUT->render_from_template('local_announcements/message_digest_html', $content);
        } else {
            $this->notificationtext = $OUTPUT->render_from_template('local_announcements/message_digest_text', $content);
        }

        $this->sentcount = count($this->posts);

        $eventdata = new \core\message\message();
        $eventdata->courseid = SITEID;
        $eventdata->component = 'local_announcements';
        $eventdata->name = 'digests';
        $eventdata->userfrom = $userfrom;
        $eventdata->userto = $this->recipient;
        $eventdata->subject = $this->postsubject;
        $eventdata->fullmessage = $this->notificationtext;
        $eventdata->fullmessageformat = FORMAT_PLAIN;
        $eventdata->fullmessagehtml = $this->notificationhtml;
        $eventdata->notification = 1;
        $eventdata->smallmessage = get_string('digest:smallmessage', 'local_announcements', $this->sentcount);

        return message_send($eventdata);
    }


}