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
     * MyConnect Vars
     */
    protected $includemyconnect = false;

    protected $myconnectposts = [];
    protected $myconnectpostusers = [];
    protected $myconnectpostmentees = [];
    
    protected $myconnectuserposts = [];
    protected $myconnectmenteeposts = [];


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
     * The process:
     * 1. Get unmailed posts (announcements and myconnect)
     * 2. Get the recipients of the posts. For myconnect posts also need to get the mentors of the recipients.
     * 3. Reverse the array, so that you have users and their posts.
     * 4. Queue send tasks for each user (batched).
     */
    public function execute() {
        global $DB, $CFG;

        $config = get_config('local_announcements');

        if (!$config->enabledigest) {
            $this->log("Digest is not enabled in plugin configuration. Exiting.");
            return;
        }

        $timenow = time();
        $this->log("Fetching unmailed announcements that are available now ({$timenow}).");
        if ($posts = announcement::get_unmailed($timenow)) {
            foreach ($posts as $id => $post) {
                $this->posts[$id] = new \stdClass();
                $this->posts[$id]->id = $post->get('id');
            }
        }

        $myconnectdir = '/local/myconnect/version.php';
        $cfgincludemyconnect = isset($config->myconnectdigest) ? $config->myconnectdigest : false;
        if ($cfgincludemyconnect && file_exists($CFG->dirroot.$myconnectdir)) {
            $this->includemyconnect = true;
            $this->log("Fetching unmailed myconnect posts.");
            $this->myconnectposts = \local_myconnect\persistents\post::get_unmailed();
        }

        if (empty($this->myconnectposts) && empty($this->posts)) {
            $this->log("No unmailed announcements / myconnect posts found.", 1);
            return false;
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
        if (count($this->posts)) {
            list($in, $params) = $DB->get_in_or_equal(array_keys($this->posts));
            $DB->set_field_select('ann_posts', 'mailed', 1, "id {$in}", $params);
        }
        if ($this->includemyconnect && count($this->myconnectposts)) {
            list($in, $params) = $DB->get_in_or_equal(array_keys($this->myconnectposts));
            $DB->set_field_select('myconnect_posts', 'mailed', 1, "id {$in}", $params);
        }
    }


    /**
     * Fill the cache of recipients.
     */
    protected function fill_postusers_cache() {

        // Announcements.
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

        // MyConnect.
        if ($this->includemyconnect) {
            foreach ($this->myconnectposts as $postid => $post) {
                // Add the direct recipients.
                $postusers = \local_myconnect\persistents\post::get_post_users($postid);
                foreach ($postusers as $postuser) {
                    $user = \local_myconnect\utils::get_user_with_extras($postuser->username);
                    if ($user->suspended) {
                        continue;
                    }
                    $this->myconnectpostusers[$postid][$user->id] = $user->id;
                    $this->users[$user->id] = $user;

                    // Add mentors too!
                    if (isset($user->mentorusers)) {
                        foreach ($user->mentorusers as $mentor) {
                            if ($mentor->suspended) {
                                continue;
                            }
                            if (!isset($this->myconnectpostmentees[$postid][$mentor->id])) {
                                $this->myconnectpostmentees[$postid][$mentor->id] = array();
                            }
                            $this->myconnectpostmentees[$postid][$mentor->id][] = $user->id;
                            $this->users[$mentor->id] = $mentor;
                        }
                    }

                }

                
            }
        }

    }


    /**
     * Fill the cache of recipients.
     */
    protected function fill_userposts_cache() {

        // Announcements.
        foreach ($this->postusers as $postid => $users) {
            foreach ($users as $userid) {
                $this->userposts[$userid][] = $postid;
            }
        }

        // MyConnect - direct.
        foreach ($this->myconnectpostusers as $postid => $users) {
            foreach ($users as $userid) {
                $this->myconnectuserposts[$userid][] = $postid;
            }
        }

        // MyConnect - mentees.
        foreach ($this->myconnectpostmentees as $postid => $mentorusers) {
            foreach ($mentorusers as $mentorid => $mentees) {
                if (!isset($this->myconnectmenteeposts[$mentorid])) {
                    $this->myconnectmenteeposts[$mentorid] = array();
                }
                foreach ($mentees as $menteeid) {
                    if (!isset($this->myconnectmenteeposts[$mentorid][$menteeid])) {
                        $this->myconnectmenteeposts[$mentorid][$menteeid] = array();
                    }
                    $this->myconnectmenteeposts[$mentorid][$menteeid][] = $postid;
                }
            }
        }
    }

    /**
     * Queue the user tasks.
     */
    protected function queue_user_tasks() {
        global $DB;

        $config = get_config('local_announcements');

        $timenow = time();
        $sitetimezone = \core_date::get_server_timezone();

        $pertask = isset($config->digestbatchnum) ? $config->digestbatchnum : '1';
        $numusers = count($this->users);
        $this->log("Processing " . $numusers . " users, in lots of " . $pertask, 1);

        $i = 1;
        $ui = 1;
        $batch = array();
        foreach ($this->users as $user) {
            // Custom data structure.
            $batch[$user->id] = array(
                'announcements' => array(),
                'myconnectposts' => array(),
            );

            // Announcements.
            $digestposts = $this->fetch_posts_for_user($user);
            if (!empty($digestposts)) {
                $batch[$user->id]['announcements'] = $digestposts;
            }

            // MyConnect posts - direct.
            if (isset($this->myconnectuserposts[$user->id])) {
                $batch[$user->id]['myconnectposts'] = $this->myconnectuserposts[$user->id];
            }

            // MyConnect posts - mentees.
            if (isset($this->myconnectmenteeposts[$user->id])) {
                $batch[$user->id]['myconnectmenteeposts'] = $this->myconnectmenteeposts[$user->id];
            }           

            // Stats.
            $this->log(sprintf("Found %d announcements, %d direct myconnect posts, %d mentee myconnect posts, for %s (%d)",
                count($digestposts),
                isset($this->myconnectuserposts[$user->id]) ? count($this->myconnectuserposts[$user->id]) : 0,
                isset($this->myconnectmenteeposts[$user->id]) ? count(array_merge(...$this->myconnectmenteeposts[$user->id])) : 0,
                $user->username,
                $user->id,
            ), 2);

            // If we have reached the per task limit, or have processed all users, create the next adhoc batch.
            if ( $i == $pertask || $ui == $numusers) {
                $task = new \local_announcements\task\send_user_digests();
                $task->set_custom_data($batch);
                $task->set_component('local_announcements');
                \core\task\manager::queue_adhoc_task($task);

                // Reset batch.
                $i = 0;
                $batch = array();
            }

            $i++;
            $ui++;
        }

    }        

    /**
     * Fetch posts for this user.
     *
     * @param   \stdClass   $user The user to fetch posts for.
     */
    protected function fetch_posts_for_user($user) {
        $digestposts = [];
        if (isset($this->userposts[$user->id])) {
            foreach ($this->userposts[$user->id] as $postid) {
                $digestposts[] = $postid;
            }   
        }
        return $digestposts;
    }

}