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
 *
 * @package   local_announcements
 * @copyright 2026 Michael Vangelovski
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_announcements\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/announcements/locallib.php');

use local_announcements\persistents\announcement;


class custom_task_digest_categorised {

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
     * @var Map of postid => parentusername => [childuserid, ...] capturing which
     * child caused each parent to be included in a post (ann_posts_users_mentees).
     */
    protected $postmentees = [];

    /**
     * @var Map of childuserid => full name, for rendering per-child digest headings.
     */
    protected $menteenames = [];

    /**
     * @var Map of childuserid => raw "year" profile value, for sorting children.
     */
    protected $menteeyears = [];

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
        return get_string('crontask_digests', 'local_announcements');
    }

    /**
     * Execute the scheduled task.
     * The process:
     * 1. Get unmailed posts (announcements and myconnect)
     * 2. Get the recipients of the posts. For myconnect posts also need to get the mentors of the recipients.
     * 3. Reverse the array, so that you have users and their posts.
     * 4. Queue one row per user into ann_digest_queue.
     */
    public function execute() {
        global $DB, $CFG;

        $config = get_config('local_announcements');

        $this->logger->log("Running Digest for " . date('Y-m-d') . ".");

        if (!$config->enabledigest) {
            $this->logger->log("Digest is not enabled in plugin configuration. Exiting.", 1);
            return;
        }

        $timenow = time();
        $this->logger->log("Fetching unmailed announcements that are available now ({$timenow}).", 1);
        if ($posts = announcement::get_unmailed($timenow)) {
            foreach ($posts as $id => $post) {
                $this->posts[$id] = new \stdClass();
                $this->posts[$id]->id = $post->get('id');
                // Category and timestamps are needed to section, recategorise
                // and dedup posts per user when building the digest.
                $this->posts[$id]->category = $post->get('category');
                $this->posts[$id]->sorttime = $post->get('sorttime');
                $this->posts[$id]->timecreated = $post->get('timecreated');
            }
        }

        $myconnectdir = '/local/myconnect/version.php';
        $cfgincludemyconnect = isset($config->myconnectdigest) ? $config->myconnectdigest : false;
        if ($cfgincludemyconnect && file_exists($CFG->dirroot.$myconnectdir)) {
            $this->includemyconnect = true;
            $this->logger->log("Fetching unmailed myconnect posts.", 1);
            $this->myconnectposts = \local_myconnect\persistents\post::get_unmailed();
        }

        if (empty($this->myconnectposts) && empty($this->posts)) {
            $this->logger->log("No unmailed announcements / myconnect posts found.", 1);
            return false;
        }

        // Please note, this order is intentional.
        $this->fill_postusers_cache();
        $this->fill_userposts_cache();
        $this->fill_postmentees_cache();
        $this->logger->log("Queueing user tasks.", 1);
        $this->queue_user_tasks();

        // Mark posts as mailed.
        if (count($this->posts)) {
            list($in, $params) = $DB->get_in_or_equal(array_keys($this->posts));
            $DB->set_field_select('ann_posts', 'mailed', 1, "id {$in}", $params);
        }
        if ($this->includemyconnect && count($this->myconnectposts)) {
            list($in, $params) = $DB->get_in_or_equal(array_keys($this->myconnectposts));
            $DB->set_field_select('myconnect_posts', 'mailed', 1, "id {$in}", $params);
        }
        $this->logger->log("Digests Queued.");
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
     * Fill the cache of announcement parent->child provenance.
     *
     * For each announcement in this run, loads which child (mentee) caused each
     * parent to be included (from ann_posts_users_mentees) so the parent digest
     * can be split per child. Also resolves each child's display name once.
     */
    protected function fill_postmentees_cache() {
        global $DB;

        if (empty($this->posts)) {
            return;
        }

        list($insql, $params) = $DB->get_in_or_equal(array_keys($this->posts));
        $sql = "SELECT id, postid, parentusername, menteeuserid, menteeusername
                  FROM {ann_posts_users_mentees}
                 WHERE postid {$insql}";
        $records = $DB->get_records_sql($sql, $params);

        $childids = array();
        foreach ($records as $record) {
            $this->postmentees[$record->postid][$record->parentusername][] = (int) $record->menteeuserid;
            $childids[$record->menteeuserid] = $record->menteeusername;
        }

        if (empty($childids)) {
            return;
        }

        // Resolve child display names once. Reuse already-loaded users where possible.
        $unresolved = array();
        foreach ($childids as $childid => $username) {
            if (isset($this->users[$childid])) {
                $this->menteenames[$childid] = fullname($this->users[$childid]);
            } else {
                $unresolved[] = $childid;
            }
        }
        if (!empty($unresolved)) {
            $childusers = $DB->get_records_list('user', 'id', $unresolved);
            foreach ($unresolved as $childid) {
                if (isset($childusers[$childid])) {
                    $this->menteenames[$childid] = fullname($childusers[$childid]);
                } else {
                    // Fall back to the stored username if the user record is gone.
                    $this->menteenames[$childid] = $childids[$childid];
                }
            }
        }
    }

    /**
     * Queue one row per user into ann_digest_queue.
     *
     * Each row's customdata holds the announcements sectioned by category, with
     * the categories recategorised for the recipient's role (see recategorise())
     * and MyConnect posts merged into the Academic section.
     */
    protected function queue_user_tasks() {
        global $DB;

        $numusers = count($this->users);
        $this->logger->log("Processing " . $numusers . " users.", 1);

        foreach ($this->users as $user) {
            // Determine the role of the digest recipient.
            $role = $this->get_user_role($user);

            // Bucket the user's announcements into sections keyed by their
            // (recategorised) leaf category. For parents, "Students > *" posts
            // caused by a specific child are bucketed per child instead.
            $isparent = ($role === 'parent');
            $buckets = array();
            $childbuckets = array();
            $digestposts = $this->fetch_posts_for_user($user);

            foreach ($digestposts as $postid) {
                if (!isset($this->posts[$postid])) {
                    continue;
                }
                $category = $this->recategorise($this->posts[$postid]->category, $role);
                if ($category === '' || $category === null) {
                    continue;
                }
                // For parents, split causing-child "Students > *" posts per child.
                $mentees = ($isparent && isset($this->postmentees[$postid][$user->username]))
                    ? array_unique($this->postmentees[$postid][$user->username]) : array();
                if (!empty($mentees) && strpos($category, 'Students > ') === 0) {
                    // Place a copy under each child that caused this post to be included.
                    foreach ($mentees as $childid) {
                        if (!isset($childbuckets[$childid][$category])) {
                            $childbuckets[$childid][$category] = array('announcements' => array());
                        }
                        $childbuckets[$childid][$category]['announcements'][] = $postid;
                    }
                    continue;
                }
                if (!isset($buckets[$category])) {
                    $buckets[$category] = array(
                        'announcements' => array(),
                    );
                }
                $buckets[$category]['announcements'][] = $postid;
            }

            // Dedup single-sender categories: if user error produced more than
            // one, keep only the latest (by sorttime, then timecreated).
            foreach (array('Newsletter link') as $dedupcat) {
                if (isset($buckets[$dedupcat]) && count($buckets[$dedupcat]['announcements']) > 1) {
                    $buckets[$dedupcat]['announcements'] = array($this->latest_post($buckets[$dedupcat]['announcements']));
                }
            }

            // Merge MyConnect posts into the Academic section (recategorised for
            // the recipient's role, e.g. Staff > Academic for staff).
            $myconnectcat = $this->recategorise('Students > Academic', $role);
            $directmyconnect = isset($this->myconnectuserposts[$user->id]) ? $this->myconnectuserposts[$user->id] : array();
            $menteemyconnect = isset($this->myconnectmenteeposts[$user->id]) ? $this->myconnectmenteeposts[$user->id] : array();
            // Direct MyConnect posts (the recipient's own) always go in the generic section.
            if (!empty($directmyconnect)) {
                if (!isset($buckets[$myconnectcat])) {
                    $buckets[$myconnectcat] = array('announcements' => array());
                }
                $buckets[$myconnectcat]['myconnectposts'] = array_values($directmyconnect);
            }
            // Mentee MyConnect posts are keyed by child. For parents, fold them
            // under the matching child; otherwise keep the generic grouping.
            if (!empty($menteemyconnect)) {
                if ($isparent) {
                    foreach ($menteemyconnect as $childid => $menteeposts) {
                        $childbuckets[$childid][$myconnectcat]['myconnectmenteeposts'][$childid] = array_values($menteeposts);
                        // Resolve the child's heading name if not already known from
                        // announcement provenance (MyConnect children live in $this->users).
                        if (!isset($this->menteenames[$childid]) && isset($this->users[$childid])) {
                            $this->menteenames[$childid] = fullname($this->users[$childid]);
                        }
                    }
                } else {
                    if (!isset($buckets[$myconnectcat])) {
                        $buckets[$myconnectcat] = array('announcements' => array());
                    }
                    $buckets[$myconnectcat]['myconnectmenteeposts'] = $menteemyconnect;
                }
            }

            // Emit sections in canonical category order, dropping empties.
            $sections = array();
            foreach ($this->category_order() as $category) {
                if (!isset($buckets[$category])) {
                    continue;
                }
                $bucket = $buckets[$category];
                if (empty($bucket['announcements']) && empty($bucket['myconnectposts']) && empty($bucket['myconnectmenteeposts'])) {
                    continue;
                }
                $section = array('category' => $category);
                $section['announcements'] = isset($bucket['announcements']) ? array_values($bucket['announcements']) : array();
                if (!empty($bucket['myconnectposts'])) {
                    $section['myconnectposts'] = $bucket['myconnectposts'];
                }
                if (!empty($bucket['myconnectmenteeposts'])) {
                    $section['myconnectmenteeposts'] = $bucket['myconnectmenteeposts'];
                }
                $sections[] = $section;
            }

            // Emit per-child sections (parents only), ordered by year level, each
            // with its own copy of the "Students > *" sub-sections in canonical order.
            $childsections = array();
            if (!empty($childbuckets)) {
                uksort($childbuckets, function($a, $b) {
                    $ra = $this->year_rank($this->child_year($a));
                    $rb = $this->year_rank($this->child_year($b));
                    if ($ra !== $rb) {
                        return $ra <=> $rb;
                    }
                    // Tiebreak: alphabetical by child name.
                    $na = isset($this->menteenames[$a]) ? $this->menteenames[$a] : '';
                    $nb = isset($this->menteenames[$b]) ? $this->menteenames[$b] : '';
                    return strcasecmp($na, $nb);
                });
                foreach ($childbuckets as $childid => $catbuckets) {
                    $secs = array();
                    foreach ($this->student_category_order() as $category) {
                        if (empty($catbuckets[$category])) {
                            continue;
                        }
                        $bucket = $catbuckets[$category];
                        $announcements = isset($bucket['announcements']) ? array_values($bucket['announcements']) : array();
                        if (empty($announcements) && empty($bucket['myconnectmenteeposts'])) {
                            continue;
                        }
                        $sec = array('category' => $category, 'announcements' => $announcements);
                        if (!empty($bucket['myconnectmenteeposts'])) {
                            $sec['myconnectmenteeposts'] = $bucket['myconnectmenteeposts'];
                        }
                        $secs[] = $sec;
                    }
                    if (!empty($secs)) {
                        $childsections[] = array(
                            'childid' => $childid,
                            'childname' => isset($this->menteenames[$childid]) ? $this->menteenames[$childid] : '',
                            'sections' => $secs,
                        );
                    }
                }
            }

            if (empty($sections) && empty($childsections)) {
                continue;
            }

            $userdata = array(
                'version' => 3,
                'role' => $role,
                'sections' => $sections,
            );
            if (!empty($childsections)) {
                $userdata['childsections'] = $childsections;
            }

            // Stats.
            $this->logger->log(sprintf("Built %d sections + %d child group(s) (role=%s) from %d announcements, %d direct myconnect posts, %d mentee myconnect posts, for %s (%d)",
                count($sections),
                count($childsections),
                $role,
                count($digestposts),
                count($directmyconnect),
                empty($menteemyconnect) ? 0 : count(array_merge(...array_values($menteemyconnect))),
                $user->username,
                $user->id,
            ), 2);

            // Insert into digest queue.
            $record = new \stdClass();
            $record->username = $user->username;
            $record->customdata = json_encode($userdata);
            $record->status = 0;
            $record->timecreated = time();
            $DB->insert_record('ann_digest_queue', $record);
        }
    }

    /**
     * Fetch post ids for this user.
     *
     * @param   \stdClass   $user The user to fetch posts for.
     * @return  int[]
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

    /**
     * Determine the digest recipient's role: 'staff', 'student' or 'parent'.
     *
     * Mirrors the precedence used by \local_myconnect\utils::get_user_with_extras:
     * parent, then student, then staff overrides. Defaults to 'student'.
     *
     * @param   \stdClass   $user
     * @return  string
     */
    protected function get_user_role($user) {
        global $CFG;

        // MyConnect recipients are already enriched with a usertype.
        if (!empty($user->usertype)) {
            return $user->usertype;
        }

        require_once($CFG->dirroot . '/user/profile/lib.php');
        if (!isset($user->profile)) {
            profile_load_custom_fields($user);
        }
        $campusroles = isset($user->profile['CampusRoles']) ? strtolower($user->profile['CampusRoles']) : '';

        $role = 'student';
        if (strpos($campusroles, 'parent') !== false) {
            $role = 'parent';
        }
        if (strpos($campusroles, 'student') !== false) {
            $role = 'student';
        }
        if (strpos($campusroles, 'staff') !== false) {
            $role = 'staff';
        }
        return $role;
    }

    /**
     * Recategorise a category for the recipient's role.
     *
     * Staff recipients see student-targeted content under the Staff side and
     * vice-versa. Ungrouped categories (From Head, Newsletter link, etc.) are
     * returned unchanged.
     *
     * @param   string   $category
     * @param   string   $role  'staff', 'student' or 'parent'
     * @return  string
     */
    protected function recategorise($category, $role) {
        if ($role === 'staff') {
            if (strpos($category, 'Students > ') === 0) {
                // Staff see student-targeted content under "Staff > Students *",
                // e.g. "Students > Academic" => "Staff > Students Academic".
                return 'Staff > Students ' . substr($category, strlen('Students > '));
            }
        } else {
            // Student and parent recipients. Staff-targeted content is moved to the
            // "Other" bucket, which sorts to the bottom of the digest.
            if ($category === 'Staff' || strpos($category, 'Staff > ') === 0) {
                return 'Other';
            }
        }
        return $category;
    }

    /**
     * The canonical category order, taken from announcement::CATEGORIES digestorder.
     *
     * @return  string[]  Ordered list of category titles.
     */
    protected function category_order() {
        $categories = announcement::CATEGORIES;
        usort($categories, function($a, $b) {
            return $a['digestorder'] <=> $b['digestorder'];
        });
        return array_column($categories, 'title');
    }

    /**
     * The canonical order of the "Students > *" leaf categories (Academic,
     * Co-curricular, House, Boarding), used for the per-child digest sub-sections.
     *
     * @return  string[]  Ordered list of "Students > *" category titles.
     */
    protected function student_category_order() {
        return array_values(array_filter($this->category_order(), function($category) {
            return strpos($category, 'Students > ') === 0;
        }));
    }

    /**
     * Resolve and cache a child's year level from the "year" custom profile field.
     *
     * Reuses an already-loaded user where possible (children may live in
     * $this->users or need a fresh load, mirroring fill_postmentees_cache()).
     *
     * @param   int     $childid
     * @return  string  The raw "year" value, or '' if unavailable.
     */
    protected function child_year($childid) {
        global $CFG;
        if (array_key_exists($childid, $this->menteeyears)) {
            return $this->menteeyears[$childid];
        }
        require_once($CFG->dirroot . '/user/profile/lib.php');
        $user = isset($this->users[$childid]) ? $this->users[$childid] : \core_user::get_user($childid);
        $year = '';
        if ($user) {
            if (!isset($user->profile)) {
                profile_load_custom_fields($user);
            }
            if (isset($user->profile['year'])) {
                $year = $user->profile['year'];
            }
        }
        $this->menteeyears[$childid] = $year;
        return $year;
    }

    /**
     * Rank a child's "year" value for digest ordering (youngest first).
     *
     * Year levels 0..12 sort ascending; the early-years codes (>= 100) sort before
     * year 0, with 200 before 100. Non-numeric/unknown years sort last.
     *
     * @param   string|int  $year
     * @return  int
     */
    protected function year_rank($year) {
        if (!is_numeric($year)) {
            return PHP_INT_MAX;
        }
        $y = (int) $year;
        // 100/200 are the youngest cohorts and must precede year 0; 200 before 100.
        return ($y >= 100) ? -$y : $y;
    }

    /**
     * Return the latest post id from a list (by sorttime, then timecreated).
     *
     * @param   int[]   $postids
     * @return  int
     */
    protected function latest_post($postids) {
        $latestid = reset($postids);
        foreach ($postids as $postid) {
            if (!isset($this->posts[$postid]) || !isset($this->posts[$latestid])) {
                continue;
            }
            $a = $this->posts[$postid];
            $b = $this->posts[$latestid];
            if ($a->sorttime > $b->sorttime
                    || ($a->sorttime == $b->sorttime && $a->timecreated > $b->timecreated)) {
                $latestid = $postid;
            }
        }
        return $latestid;
    }


}
