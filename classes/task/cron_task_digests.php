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
 * A scheduled task for digests.
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_announcements\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/announcements/locallib.php');
use local_announcements\providers\audience_loader;
use local_announcements\persistents\announcement;

/**
 * The main scheduled task for the digests.
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cron_task_digests extends \core\task\scheduled_task {

    // Use the logging trait to get some nice, juicy, logging.
    use \core\task\logging_trait;

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
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('crontask_digests', 'local_announcements');
    }

    /**
     * Execute the scheduled task.
     */
    public function execute() {
        global $DB;

        $config = get_config('local_announcements');

        if (!$config->enabledigest) {
            $this->log("Digest is not enabled in plugin configuration. Exiting.");
            return;
        }

        $timenow = time();
        $this->log_start("Fetching unmailed announcements that are available now ({$timenow}).");
        if (!$posts = announcement::get_unmailed($timenow)) {
            $this->log_finish("No unmailed announcements found.", 1);
            return false;
        }
        $this->log_finish("Done");

        foreach ($posts as $id => $post) {
            $this->posts[$id] = new \stdClass();
            $this->posts[$id]->id = $post->get('id');
            $this->posts[$id]->forcesend = $post->get('forcesend');
        }

        // Please note, this order is intentional.
        $this->log_start("Filling caches");
        $start = microtime(true);
        $this->log_start("Filling post recipients cache", 1);
        $this->fill_postusers_cache();
        $this->log_finish("Done", 1);
        $this->log_start("Filling user posts cache", 1);
        $this->fill_userposts_cache();
        $this->log_finish("Done", 1);
        $this->log_finish("All caches filled");
        $this->log_start("Queueing user tasks.");
        $this->queue_user_tasks();
        $this->log_finish("All tasks queued.");

        // Mark posts as read.
        list($in, $params) = $DB->get_in_or_equal(array_keys($posts));
        $DB->set_field_select('ann_posts', 'mailed', 1, "id {$in}", $params);
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
     * Fill the cache of recipients.
     */
    protected function fill_userposts_cache() {
        foreach ($this->postusers as $postid => $users) {
            foreach ($users as $userid) {
                $this->userposts[$userid][] = $postid;
            }
        }
    }

    /**
     * Queue the user tasks.
     */
    protected function queue_user_tasks() {
        global $DB;

        $timenow = time();
        $sitetimezone = \core_date::get_server_timezone();
        $counts = array(
            'digests' => 0,
            'forcesends' => 0,
            'users' => 0,
            'ignored' => 0,
        );
        $this->log("Processing " . count($this->users) . " users", 1);
        foreach ($this->users as $user) {
            $counts['users']++;
            $usercounts = [
                'digests' => 0,
                'posts' => 0,
            ];

            $send = false;
            // Setup this user so that the capabilities are cached, and environment matches receiving user.
            if (CLI_SCRIPT) {
                cron_setup_user($user);
            }

            $digestposts = $this->fetch_posts_for_user($user);

            if (!empty($digestposts)) {
                $this->log("creating ad hoc task (send_user_digests) for " . $user->id, 1);
                $task = new \local_announcements\task\send_user_digests();
                $task->set_userid($user->id);
                $task->set_custom_data($digestposts);
                $task->set_component('local_announcements');
                \core\task\manager::queue_adhoc_task($task);
                $usercounts['posts'] = $usercounts['posts'] + count($digestposts);
                $send = true;
            }

            if ($send) {
                $usercounts['digests']++;
                $counts['digests']++;
            } else {
                $counts['ignored']++;
            }

            $this->log(sprintf("Queued %d digests with %d posts for %s",
                    $usercounts['digests'],
                    $usercounts['posts'],
                    $user->id
                ), 2);
        }
        $this->log(
            sprintf(
                "Queued %d digests. " .
                "Unique users: %d (%d ignored)",
                $counts['digests'],
                $counts['users'],
                $counts['ignored']
            ), 1);
    }

    /**
     * Fetch posts for this user.
     *
     * @param   \stdClass   $user The user to fetch posts for.
     */
    protected function fetch_posts_for_user($user) {
        $digestposts = [];
        foreach ($this->userposts[$user->id] as $postid) {
            $post = $this->posts[$postid];
            // Do not include forcesend posts in digests as they are emailed immediately.
            if( ! $post->forcesend ) {
                $digestposts[] = $postid;
            }
        }
        return $digestposts;
    }

}