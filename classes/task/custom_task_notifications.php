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
 * Queue population task for notifications.
 *
 * @package   local_announcements
 * @copyright 2026 Michael Vangelovski
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_announcements\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/announcements/locallib.php');

use local_announcements\persistents\announcement;


class custom_task_notifications {

    /** @var digest_logger */
    protected $logger;

    /**
     * @var The list of posts to be sent.
     */
    protected $posts = [];

    /**
     * @var The list of post authors.
     */
    protected $users = [];

    /**
     * @var The list of recipients for a post.
     */
    protected $postusers = [];

    /**
     * @var The list of posts for each user.
     */
    protected $userposts = [];

    /**
     * Constructor.
     *
     * @param digest_logger $logger
     */
    public function __construct(digest_logger $logger) {
        $this->logger = $logger;
    }

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('crontask_notifications', 'local_announcements');
    }

    /**
     * Execute the task.
     * 1. Get unsent posts
     * 2. Get the recipients of the posts
     * 3. Reverse the array, so that you have users and their posts
     * 4. Queue one row per user into ann_notifications_queue
     */
    public function execute() {
        global $DB;

        $config = get_config('local_announcements');

        $this->logger->log("Running Notifications for " . date('Y-m-d') . ".");

        if (!$config->enablenotify) {
            $this->logger->log("Notifications are not enabled in plugin configuration. Exiting.", 1);
            return;
        }

        $timenow = time();
        $readabletime = date('Y-m-d H:i:s', $timenow);
        $this->logger->log("Fetching unsent announcements that are available now ({$readabletime}).", 1);
        if (!$posts = announcement::get_unsent($timenow)) {
            $this->logger->log("No unsent announcements found.", 1);
            return false;
        }

        foreach ($posts as $id => $post) {
            $this->posts[$id] = $post;
        }
        $this->logger->log("Found the following announcements: " . implode(', ', array_keys($posts)), 1);

        // Please note, this order is intentional.
        $this->fill_postusers_cache();
        $this->fill_userposts_cache();
        $this->logger->log("Queueing user tasks.", 1);
        $this->queue_user_tasks();

        // Mark posts as notified.
        list($in, $params) = $DB->get_in_or_equal(array_keys($posts));
        $DB->set_field_select('ann_posts', 'notified', ANN_NOTIFIED_SUCCESS, "id {$in}", $params);

        $this->logger->log("Notifications Queued.");
    }

    /**
     * Fill the cache of recipients.
     */
    protected function fill_postusers_cache() {
        foreach ($this->posts as $postid => $post) {
            $users = announcement::get_post_users($postid);
            foreach ($users as $user) {
                if ($user->suspended) {
                    continue;
                }
                $this->postusers[$postid][$user->id] = $user->id;
                $this->users[$user->id] = $user;
            }
        }
    }

    /**
     * Fill the cache of user posts.
     */
    protected function fill_userposts_cache() {
        foreach ($this->postusers as $postid => $users) {
            foreach ($users as $userid) {
                $this->userposts[$userid][] = $postid;
            }
        }
    }

    /**
     * Queue one row per user into ann_notifications_queue.
     */
    protected function queue_user_tasks() {
        global $DB;

        $numusers = count($this->users);
        $this->logger->log("Processing " . $numusers . " users.", 1);

        foreach ($this->users as $user) {
            $notificationposts = $this->fetch_posts_for_user($user);

            $this->logger->log(sprintf("Found %d notifications for %s (%d)",
                count($notificationposts),
                $user->username,
                $user->id,
            ), 2);

            if (empty($notificationposts)) {
                continue;
            }

            // Insert into notifications queue.
            $record = new \stdClass();
            $record->username = $user->username;
            $record->customdata = json_encode(['posts' => $notificationposts]);
            $record->status = 0;
            $record->timecreated = time();
            $DB->insert_record('ann_notifications_queue', $record);
        }
    }

    /**
     * Fetch posts for this user.
     *
     * @param   \stdClass   $user The user to fetch posts for.
     */
    protected function fetch_posts_for_user($user) {
        $notificationposts = [];
        if (isset($this->userposts[$user->id])) {
            foreach ($this->userposts[$user->id] as $postid) {
                $notificationposts[] = $postid;
            }
        }
        return $notificationposts;
    }
}
