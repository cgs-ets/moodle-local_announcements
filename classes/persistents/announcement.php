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
 * Provides the {@link local_announcements\announcement} class.
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_announcements\persistents;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/announcements/lib.php');
use \core\persistent;
use local_announcements\providers\audience_loader;
use local_announcements\providers\moderation;
use \local_announcements\forms\form_post;
use \core_user;
use \context_user;
use \context_course;

/**
 * Persistent model representing a single announcement post.
 */
class announcement extends persistent {

    /** Table to store this persistent model instances. */
    const TABLE = 'ann_posts';

    /** Related tables. */
    const TABLE_POSTS_USERS = 'ann_posts_users';
    const TABLE_POSTS_USERS_AUDIENCES = 'ann_posts_users_audiences';
    const TABLE_POSTS_AUDIENCES = 'ann_posts_audiences';
    const TABLE_POSTS_AUDIENCES_CONDITIONS = 'ann_posts_audiences_cond';
    const TABLE_AUDIENCE_TYPES = 'ann_audience_types';

    /**
     * Return the definition of the properties of this model.
     *
     * @return array
     */
    protected static function define_properties() {
        return [
            "authorusername" => [
                'type' => PARAM_RAW,
            ],
            "mailed" => [
                'type' => PARAM_BOOL,
                'default' => 0,
            ],
            "notified" => [
                'type' => PARAM_BOOL,
                'default' => 0,
            ],
            "forcesend" => [
                'type' => PARAM_BOOL,
                'default' => 0,
            ],
            "subject" => [
                'type' => PARAM_RAW,
            ],
            "message" => [
                'type' => PARAM_RAW,
            ],
            "messageformat" => [
                'type' => PARAM_INT,
            ],
            "messagetrust" => [
                'type' => PARAM_BOOL,
                'default' => 0,
            ],
            "attachment" => [
                'type' => PARAM_RAW,
                'default' => 0,
            ],
            "deleted" => [
                'type' => PARAM_BOOL,
                'default' => 0,
            ],
            "timestart" => [
                'type' => PARAM_INT,
                'default' => 0,
            ],
            "timeend" => [
                'type' => PARAM_INT,
                'default' => 0,
            ],
            "audiencesjson" => [
                'type' => PARAM_RAW,
                'default' => 0,
            ],
            "pinned" => [
                'type' => PARAM_BOOL,
                'default' => 0,
            ],
            "modrequired" => [
                'type' => PARAM_INT,
                'default' => 0,
            ],
            "modstatus" => [
                'type' => PARAM_INT,
                'default' => 0,
            ],
            "savecomplete" => [
                'type' => PARAM_BOOL,
                'default' => 0,
            ],
            "timeedited" => [
                'type' => PARAM_INT,
                'default' => 0,
            ],
        ];
    }

    /**
     * Get announcements for a user.
     *
     * @param int $page.
     * @param int $perpage.
     * @return array.
     */
    public static function get_all($page = 0, $perpage = 0) {
        global $DB, $USER;

        // Determine paging. 
        $from = 0;
        if (!$perpage) {
            $perpage = get_per_page();
            $from=$perpage*$page;
        }

        // Get the next set of announcements for user.
        $params = array();
        $sql = "SELECT p.*
                  FROM {" . static::TABLE . "} p
                 WHERE 1 = 1 ";

        // Add standard post availability clauses.
        list ($availsql, $availparams) = static::append_standard_availability_clauses($USER, false);
        $sql .= $availsql;
        $params = array_merge($params, $availparams);

        // Order by.
        $sql .= "ORDER BY p.timemodified DESC ";

        // Create array of announcement persistents.
        $records = $DB->get_records_sql($sql, $params, $from, $perpage);
        $posts = array();
        foreach ($records as $postid => $record) {
            $out = static::get_for_user_with_audiences(null, $postid, true);
            if (!empty($out->audiences)) {
                $posts[$postid] = $out;
            }
        }

        return $posts;
    }

    /**
     * Return announcements all announcements for an audience
     *
     * @param string $provider. 
     * @param string $type. 
     * @param string $code. 
     * @param int $page. For pagination
     * @return array.
     */
    public static function get_all_by_audience($provider = '', $type = '', $code, $page = 0, $perpage = 0) {
        global $DB, $USER;

        // Determine paging. 
        $from = 0;
        if (!$perpage) {
            $perpage = get_per_page();
            $from=$perpage*$page;
        }

        // Determine the audience provider.
        $provider = get_provider($provider, $type);
        if(!isset($provider)) {
            return [];
        }

        // Get the next set of announcements for user.
        $params = array();
        $sql = "SELECT p.*
                  FROM {" . static::TABLE . "} p
                 WHERE 1 = 1 ";

        // Add standard post availability clauses.
        list ($availsql, $availparams) = static::append_standard_availability_clauses($USER, false);
        $sql .= $availsql;
        $params = array_merge($params, $availparams);


        // Include announcements in the specified audience.
        $sql .= "AND (
                p.id IN (SELECT pac.postid
                           FROM {ann_posts_audiences_cond} pac
                          WHERE 0 = 1";

        // Get additional audience params to look for.
        $relateds = $provider::get_related_audience_codes($code);
        foreach ($relateds as $related) {
            $code = $related['code'];
            $provider = get_provider($related['provider']);
            if(!isset($provider)) {
                continue;
            }
            $types = $provider::get_audience_types();
            $types = array_column($types, 'type');
            list($typesql, $typeparams) = $DB->get_in_or_equal($types);
            $sql .= " OR ((pac.code = ?) AND (pac.type " . $typesql . "))";
            $params[] = $code;
            $params = array_merge($params, $typeparams);
        }

        // Order by.
        $sql .= ") ) ORDER BY p.timemodified DESC ";

        //Debug sql
        //echo "<pre>";
        //foreach($params as $replace){$sql = preg_replace('/\?/i', '`'.$replace.'`', $sql, 1);}
        //$sql = preg_replace('/\{/i', 'mdl_', $sql);$sql = preg_replace('/\}/i', '', $sql);
        //var_export($sql);
        //exit;

        // Create array of announcement persistents.
        $records = $DB->get_records_sql($sql, $params, $from, $perpage);
        $posts = array();
        foreach ($records as $postid => $record) {
            $out = static::get_for_user_with_audiences(null, $postid, true);
            // Only include announcement if there are audiences. 
            // No audiences indicates something went wrong on creation.
            if (!empty($out->audiences)) {
                $posts[$postid] = $out;
            }
        }

        return $posts;
    }


    /**
     * Get announcements for a user.
     *
     * @param string $username
     * @param int $page.
     * @param int $perpage.
     * @param bool $strictavailability. Whether to include pending and rejected posts.
     * @return array.
     */
    public static function get_by_username($username, $page = 0, $perpage = 0, $strictavailability = true) {
        global $DB;

        // Load user object.
        $user = core_user::get_user_by_username($username);
        if (!$user) {
            return array();
        }

        // Determine paging. 
        $from = 0;
        if (!$perpage) {
            $perpage = get_per_page();
            $from=$perpage*$page;
        }

        // Get the next set of announcements for user.
        $params = array();
        $sql = "SELECT p.*
                  FROM {" . static::TABLE . "} p
                 WHERE 1 = 1 ";

        // Add standard post availability clauses.
        list ($availsql, $availparams) = static::append_standard_availability_clauses($user, $strictavailability);
        $sql .= $availsql;
        $params = array_merge($params, $availparams);

        // Include announcements the user is allowed to see.
        $sql .= " 
        AND ( p.authorusername = ? OR
              p.id IN ( SELECT pu.postid
                         FROM {ann_posts_users} pu
                        WHERE pu.username = ? )
        )
        ";

        $params[] = $user->username;
        $params[] = $user->username;

        // Order by.
        $sql .= "ORDER BY p.timemodified DESC ";

        // Debug sql.
        //echo "<pre>";
        //foreach($params as $replace){$sql = preg_replace('/\?/i', '`'.$replace.'`', $sql, 1);}
        //$sql = preg_replace('/\{/i', 'mdl_', $sql);$sql = preg_replace('/\}/i', '', $sql);
        //var_export($sql);
        //exit;

        // Create array of announcement persistents.
        $records = $DB->get_records_sql($sql, $params, $from, $perpage);
        $posts = array();
        foreach ($records as $postid => $record) {
            $out = static::get_for_user_with_audiences($username, $postid);
            // Only include announcement if there are audiences. 
            // No audiences indicates something went wrong on creation.
            if (!empty($out->audiences)) {
                $posts[$postid] = $out;
            }
        }

        return $posts;
    }

    /**
     * Return announcements for a user in an audience
     *
     * @param string $username
     * @param string $provider. 
     * @param string $type. 
     * @param string $code. 
     * @param int $page. For pagination
     * @param bool $strictavailability. Whether to include pending and rejected posts.
     * @return array.
     */
    public static function get_by_username_and_audience($username, $provider = '', $type = '', $code, $page = 0, $perpage = 0, $strictavailability = true) {
        global $DB;

        // Load user object.
        $user = core_user::get_user_by_username($username);
        if (!$user) {
            return array();
        }

        // Determine paging. 
        $from = 0;
        if (!$perpage) {
            $perpage = get_per_page();
            $from=$perpage*$page;
        }

        // Determine the audience provider.
        $provider = get_provider($provider, $type);
        if(!isset($provider)) {
            return [];
        }

        // Get the next set of announcements for user.
        $params = array();
        $sql = "SELECT p.*
                  FROM {" . static::TABLE . "} p
                 WHERE 1 = 1 ";

        // Add standard post availability clauses.
        list ($availsql, $availparams) = static::append_standard_availability_clauses($user, $strictavailability);
        $sql .= $availsql;
        $params = array_merge($params, $availparams);

        // Include announcements the user is author of, in audience.
        $sql .= "
             AND (( p.authorusername = ?
                    AND p.id IN (SELECT pac.postid
                                   FROM {ann_posts_audiences_cond} pac
                                  WHERE 0 = 1";

        // Get additional audience params to look for.
        $relateds = $provider::get_related_audience_codes($code);
        $combosql = '';
        $comboparams = array();
        foreach ($relateds as $related) {
            $code = $related['code'];
            $provider = get_provider($related['provider']);
            if(!isset($provider)) {
                continue;
            }
            $types = $provider::get_audience_types();
            $types = array_column($types, 'type');
            list($typesql, $typeparams) = $DB->get_in_or_equal($types);
            $combosql .= " OR ((pac.code = ?) AND (pac.type " . $typesql . "))";
            $comboparams[] = $code;
            $comboparams = array_merge($comboparams, $typeparams);
        }

        $sql .= $combosql . ")";
        $params[] = $user->username;
        $params = array_merge($params, $comboparams);

        // Include announcements the user is recipient of, in audience.
        $sql .= "
              OR ( p.id IN (SELECT pu.postid
                            FROM   {ann_posts_users} pu
                            WHERE  pu.username = ?)
                   AND p.id IN (SELECT pac.postid
                                  FROM {ann_posts_audiences_cond} pac
                                 WHERE 0 = 1";
        $sql .= $combosql;
        $params[] = $user->username;
        $params = array_merge($params, $comboparams);

        // Order by.
        $sql .= " ) ) ) ) ORDER BY p.timemodified DESC ";

        //Debug sql
        //echo "<pre>";
        //foreach($params as $replace){$sql = preg_replace('/\?/i', '`'.$replace.'`', $sql, 1);}
        //$sql = preg_replace('/\{/i', 'mdl_', $sql);$sql = preg_replace('/\}/i', '', $sql);
        //var_export($sql);
        //exit;

        // Create array of announcement persistents.
        $records = $DB->get_records_sql($sql, $params, $from, $perpage);
        $posts = array();
        foreach ($records as $postid => $record) {
            $out = static::get_for_user_with_audiences($username, $postid);
            // Only include announcement if there are audiences. 
            // No audiences indicates something went wrong on creation.
            if (!empty($out->audiences)) {
                $posts[$postid] = $out;
            }
        }

        return $posts;
    }

    /**
     * Get announcements from an array of ids.
     *
     * @param array $postids.
     * @param int $username.
     * @param bool $getall. Whether to get all audiences regardless of author.
     * @return array.
     */
    public static function get_by_ids_and_username($postids, $username, $getall = false) {
        global $DB;

        // Load user object.
        $user = core_user::get_user_by_username($username);
        if (!$user) {
            return array();
        }

        // Remove blanks.
        $postids = array_filter($postids);

        // Get the announcement data.
        list($idsql, $params) = $DB->get_in_or_equal($postids);
        $sql = "SELECT p.id
                FROM {ann_posts} p
                WHERE p.id $idsql";

        // Add standard post availability clauses.
        list ($availsql, $availparams) = static::append_standard_availability_clauses($user);
        $sql .= $availsql;
        $params = array_merge($params, $availparams);

        // Order by.
        $sql .= "ORDER BY p.timemodified DESC, p.id DESC";

        $records = $DB->get_records_sql($sql, $params);
        $posts = array();
        foreach ($records as $postid => $record) {
            $out = static::get_for_user_with_audiences($username, $postid, $getall);
            // Only include announcement if there are audiences. 
            // No audiences indicates something went wrong on creation.
            if (!empty($out->audiences)) {
                $posts[$postid] = $out;
            }
        }
 
        return $posts;
    }

    /**
     * Return the annoucement as a persistent with the post audiences.
     *
     * @param string $postid. Id of the announcement.
     * @param string $username. 
     * @param bool $getall. Whether to get all audiences regardless of author.
     * @return array.
     */
    public static function get_for_user_with_audiences($username, $postid, $getall = false) {
        $announcement = new static($postid);
        if ($announcement->get('authorusername') == $username || $getall) {
            // Get all audiences.
            $audiences = static::get_posts_audiences($announcement->get('id'));
        } else {
            // Get the audiences that are relevant to this user.
            $audiences = static::get_posts_users_audiences($announcement->get('id'), $username);
        }
        
        $out = new \stdClass();
        $out->persistent = $announcement;
        $out->audiences = $audiences;

        return $out;
    }

    /**
     * Return the annoucement as a persistent with all of the post audiences.
     *
     * @param string $postid. Id of the announcement.
     * @return array|bool.
     */
    public static function get_with_all_audiences($postid) {
        if (!static::record_exists($postid)) {
            return false;
        }

        // Load the announcement as an instance of the persistent for exporting later.
        $out = new \stdClass();
        $out->persistent = new static($postid);
        $out->audiences = static::get_posts_audiences($postid);

        return $out;
    }

    /**
    * Gets all of the posts users.
    *
    * @param int $postid.
    * @return array.
    */
    public static function get_post_users($postid) {
        global $DB;
        // Fetch posts_users records
        $sql = "SELECT pu.*
                  FROM {" . static::TABLE_POSTS_USERS . "} pu 
                 WHERE pu.postid = ?";
        $params = array($postid);
        $postusers = $DB->get_records_sql($sql, $params);
        // Convert to user records
        $users = array();
        foreach ($postusers as $postuser) {
            $users[] = $DB->get_record('user', array('username'=>$postuser->username));
        }
        return $users;
    }

    /**
    * Gets the users for tag selection
    *
    * @param string $audiencesjson.
    * @return array.
    */
    public static function get_audienceselector_users($audiencesjson) {
        global $DB;

        if (empty($audiencesjson)) {
            return array();
        }

        $tags = json_decode($audiencesjson);
        $postusers = array();
        $postsusersaudiences = array();
        foreach ($tags as $tag) {
            if ($tag->type == "union") {
                $audienceusers = static::get_union_tag_users($tag);
            } elseif ($tag->type == "intersection") {
                $audienceusers = static::get_intersection_tag_users($tag);
            }
            $postusers[] = $audienceusers;
        }
        $postusers = array_values(array_unique(array_merge(...$postusers)));

        // Get additional audience users, such as author, and CC groups.
        $additionalusers = static::get_additional_audience_users($tags);
        $postusers = array_unique(array_merge($postusers, $additionalusers));

        // Convert to user records
        $users = array();
        foreach ($postusers as $postuser) {
            $users[] = $DB->get_record('user', array('username'=>$postuser));
        }
        return $users;
    }

    /**
    * Gets additional audience users. This always includes the announcement author.
    * It also checks for CC groups.
    *
    * @param array $tags. Audiences.
    * @return array. Additional users.
    */
    public static function get_additional_audience_users($tags) {
        global $DB, $USER;

        $additionalusers = array();

        // We'll be using the mdlgroup provider to get cc users.
        $providers = audience_loader::get();
        $mdlgroup = $providers['mdlgroup'];

        // Check each audience selection for CCs.
        foreach ($tags as $tag) {
            foreach ($tag->audiences as $audience) {
                foreach ($audience->selecteditems as $item) {
                    $sql = "SELECT ccgroupid
                          FROM {ann_audience_ccgroups}
                         WHERE audiencetype = ?
                           AND (? LIKE code OR code = '*')";
                    $code = $providers[$audience->audienceprovider]::true_code($item->code);
                    $params = array($audience->audiencetype, $code);
                    $ccgrouprows = $DB->get_records_sql($sql, $params);
                    foreach ($ccgrouprows as $ccgrouprow) {
                        // This column allows for multiple groups in csv.
                        $groupids = explode(',', $ccgrouprow->ccgroupid);
                        foreach ($groupids as $groupid) {
                            // Use the mdl group provider to get the group users.
                            $usernames = $mdlgroup::get_audience_usernames($groupid, null, $mdlgroup::ROLES);
                            if (!empty($usernames)) {
                                $additionalusers = array_merge($additionalusers, $usernames);
                            }
                        }
                    }
                }
            }
        }

        // Finally, add the post author to the list of recipients.
        $additionalusers[] = $USER->username;
        
        return $additionalusers;
    }

    /**
    * Gets CC groups based on audience selections.
    *
    * @param array $tags. Audiences.
    * @return array. Matching CC groups and users.
    */
    public static function get_audience_ccgroup_descriptions($tags) {
        global $DB;

        $providers = audience_loader::get();
        $ccgroups = array();

        // Check each audience selection for CCs.
        foreach ($tags as $tag) {
            foreach ($tag->audiences as $audience) {
                foreach ($audience->selecteditems as $item) {
                    $sql = "SELECT description
                          FROM {ann_audience_ccgroups}
                         WHERE audiencetype = ?
                           AND (? LIKE code OR code = '*')";
                    $code = $providers[$audience->audienceprovider]::true_code($item->code);
                    $params = array($audience->audiencetype, $code);
                    $rows = $DB->get_records_sql($sql, $params);
                    foreach ($rows as $ccgroup) {
                        $ccgroups[] = $ccgroup->description;
                    }
                }
            }
        }

        return array_unique($ccgroups);

    }

    /**
    * Helper method to get users for a union tag
    *
    * @param stdClass $tag. 
    * @return array. The list of users for this tag.
    */
    private static function get_union_tag_users($tag) {
        // Unions have one audience, and one or more selected items.
        $audience = $tag->audiences[0];
        $itemusers = array();
        foreach ($audience->selecteditems as $item) {
            // Get the users for this audience item.
            $usernames = static::get_audience_usernames($audience, $item);
            $itemusers[] = $usernames;
        }
        // Merge the users for this tag.
        $audienceusers = array_values(array_unique(array_merge(...$itemusers)));

        return $audienceusers;
    }

    /**
    * Helper method to get users for an intersection tag
    *
    * @param stdClass $tag. 
    * @return void.
    */
    private static function get_intersection_tag_users($tag) {
        // Intersections have many audiences, each with a single selected item.      
        $itemusers = array();
        // Insert multiple condition records, one for each audience in this tag.
        foreach ($tag->audiences as $audience) {
            // Get the selected item.
            $item = $audience->selecteditems[0];
            // Get the list of users for this audience item.
            $usernames = static::get_audience_usernames($audience, $item);
            $itemusers[] = $usernames;
        }
        // Get the interection of the selected audiences.
        $audienceusers = array_intersect(...$itemusers);
        return $audienceusers;
    }

    /**
    * Gets all of the posts audiences.
    *
    * @param int $postid.
    * @return array.
    */
    public static function get_posts_audiences($postid) {
        global $DB;

        $sql = "SELECT pac.*, pa.conditiontype
                  FROM {ann_posts_audiences_cond} pac 
            INNER JOIN {ann_posts_audiences} pa on pa.id = pac.postsaudiencesid
                 WHERE pac.postid = ?";
        $params = array($postid);
        $records = $DB->get_records_sql($sql, $params);

        // Remove duplicate audiences (same code).
        $audiences = array();
        foreach ($records as $rec) {
            $audiences[] = $rec;
        }

        return $audiences;
    }

    /**
    * Gets the post audiences that are relevant to the user.
    *
    * @param int $postid.
    * @param string $username. 
    * @return array.
    */
    public static function get_posts_users_audiences($postid, $username) {
        global $DB;

        $sql = "SELECT pac.*, pa.conditiontype
                  FROM {ann_posts_audiences_cond} pac 
            INNER JOIN {ann_posts_audiences} pa on pa.id = pac.postsaudiencesid
                 WHERE pac.postid = ?
                   AND pac.postsaudiencesid IN (
                       SELECT pua.postsaudiencesid
                         FROM {ann_posts_users_audiences} pua
                        WHERE pua.postsusersid = (
                              SELECT pu.id
                                FROM {ann_posts_users} pu
                               WHERE pu.username = ?
                                 AND pu.postid = ?
                        )
                  )";
        $params = array($postid, $username, $postid);
        $records = $DB->get_records_sql($sql, $params);

        // Remove duplicate audiences (same code).
        $audiences = array();
        foreach ($records as $rec) {
            if (!in_array($rec->code, array_column($audiences,'code'))) {
                $audiences[] = $rec;
            }
        }

        // If audiences are empty, then the user is an additional cc.
        // Get all audiences.
        if (empty($audiences)) {
            $audiences = static::get_posts_audiences($postid);
        }

        return $audiences;
    }

    /**
    * Saves the record to the database.
    *
    * If this record has an ID, then the record is updated, otherwise it is created.
    *
    * @param int $id. 0 will create a new announcement.
    * @param stdClass $data. 
    * @return int|bool $id. ID of announcement or false if failed to create.
    */
    public static function save_from_data($id, $data) {
        global $DB, $USER;

        $edit = false;
        if ($id > 0) {
            // Make sure the record actually exists.
            if (!static::record_exists($id)) {
                return false;
            }
            $edit = true;
        }

        // Before creating anything, validate the audiences.
        $tags = json_decode($data->audiencesjson);
        if (!static::is_audiences_valid($tags)) {
            return false;
        }

        // Load or create new instance, depending on $id.
        $announcement = new static($id);

        if ($edit) {
            // Editing an announcement.
            // Should the announcement be resent in the next digest.
            if ($data->remail) {
                $announcement->set('mailed', 0);
            }
        } else {
            // New announcement, set author to current user.
            $announcement->set('authorusername', $USER->username);
        }

        // Set/update the data.
        $announcement->set('timeedited', time());
        $announcement->set('subject', $data->subject);
        $announcement->set('message', '');
        $announcement->set('messageformat', $data->messageformat);
        $announcement->set('messagetrust', $data->messagetrust);
        $announcement->set('timestart', $data->timestart);
        $announcement->set('timeend', $data->timeend);
        $announcement->set('audiencesjson', $data->audiencesjson);
        $announcement->set('forcesend', $data->forcesend);
        $announcement->set('attachment', 0);
        $announcement->set('notified', 0);
        // No moderation set initially. Moderation requirements processed below.
        $announcement->set('modrequired', ANN_MOD_REQUIRED_NO);
        $announcement->set('modstatus', ANN_MOD_STATUS_PENDING);
        // Set savecomplete flag to false until all audiences and users are saved so 
        // that the plugin does not attempt to mail the plugin until everything is saved.
        $announcement->set('savecomplete', 0);

        // Update the persistent with the added details.
        $announcement->save();
        $id = $announcement->get('id');

        // Store message files to a permanent file area.
        $context = \context_system::instance();
        $message = file_save_draft_area_files(
            $data->itemid, 
            $context->id, 
            'local_announcements', 
            'announcement', 
            $id, 
            form_post::editor_options(null), 
            $data->message
        );
        $announcement->set('message', $message);

        // Store attachments to a permanent file area.
        $info = file_get_draft_area_info($data->attachments);
        $attachment = ($info['filecount']>0) ? '1' : '';
        $announcement->set('attachment', $attachment);
        file_save_draft_area_files(
            $data->attachments, 
            $context->id, 
            'local_announcements', 
            'attachment', 
            $id, 
            form_post::attachment_options()
        );
        $announcement->update();

        // Determine whether announcement needs moderation.
        moderation::setup_moderation($id, $tags);

        // Save the audiences.
        static::save_audiences($id, $tags);

        // Finally, set savecomplete to true, indicating that all aspects of the 
        // announcement have been fully saved.
        // Update single field rather than using the persistent as the announcement
        // data could have been altered for moderation requirements.
        $DB->set_field('ann_posts', 'savecomplete', 1, array('id' => $id));

        return $id;
    }

    /**
    * Replaces/creates the audiences of an announcement to the database.
    *
    * If this record has an ID, then the record is updated, otherwise it is created.
    *
    * @param int $id.
    * @param array $tags. 
    * @return void
    */
    public static function save_audiences($postid, $tags) {
        global $DB;

        // Delete existing audiences before adding new ones.
        $DB->delete_records(static::TABLE_POSTS_USERS_AUDIENCES, array('postid' => $postid));
        $DB->delete_records(static::TABLE_POSTS_USERS, array('postid' => $postid));
        $DB->delete_records(static::TABLE_POSTS_AUDIENCES_CONDITIONS, array('postid' => $postid));
        $DB->delete_records(static::TABLE_POSTS_AUDIENCES, array('postid' => $postid));

        $postusers = array();
        $postsusersaudiences = array();
        foreach ($tags as $tag) {
            if ($tag->type == "union") {
                list($audienceusers, $postsusersaudiences) = static::create_union_tag($postid, $tag, $postsusersaudiences);
            } elseif ($tag->type == "intersection") {
                list($audienceusers, $postsusersaudiences) = static::create_intersection_tag($postid, $tag, $postsusersaudiences);
            }
            $postusers[] = $audienceusers;
        }
        $postusers = array_values(array_unique(array_merge(...$postusers)));

        $additionalusers = static::get_additional_audience_users($tags);
        $postusers = array_unique(array_merge($postusers, $additionalusers));

        if (!empty($postusers)) {
            // Insert post users and their relevant audiences.
            static::create_posts_users($postid, $postusers, $postsusersaudiences);
        }
    }

    /**
    * Helper method to handle the DB insertion of a "union" tag.
    *
    * @param int postid.
    * @param stdClass $tag. 
    * @param array $postsusersaudiences. 
    * @return array. The list of users for this tag.
    */
    private static function create_union_tag($postid, $tag, $postsusersaudiences) {
        // Unions have one audience, and one or more selected items.
        $audience = $tag->audiences[0];
        $itemusers = array();
        // Insert a posts_audiences record for each selected item.
        foreach ($audience->selecteditems as $item) {
            // Create the posts audiences record.
            $postsaudiencesid = static::create_posts_audiences($postid, $tag);
            // Create the posts_audiences_cond record.
            $postaudiencesconditionid = static::create_posts_audiences_conditions($postid, $postsaudiencesid, $audience, $item);
            // Get the users for this audience item.
            $usernames = static::get_audience_usernames($audience, $item);
            $itemusers[] = $usernames;
            if (!empty($usernames)) {
                // Cache this audience as "relevant" for the users.
                $postsusersaudiences = static::cache_relevant_user_audiences($usernames, $postsaudiencesid, $postsusersaudiences);
            }
        }
        // Merge the users for this tag.
        $audienceusers = array_values(array_unique(array_merge(...$itemusers)));

        return [$audienceusers, $postsusersaudiences];
    }

    /**
    * Helper method to handle the DB insertion of an "intersection" tag.
    *
    * @param int postid.
    * @param stdClass $tag. 
    * @param array $postsusersaudiences. 
    * @return void.
    */
    private static function create_intersection_tag($postid, $tag, $postsusersaudiences) {
        // Intersections have many audiences, each with a single selected item.
        // Insert a single posts_audiences record for the tag.          
        $postsaudiencesid = static::create_posts_audiences($postid, $tag);
        $itemusers = array();
        // Insert multiple condition records, one for each audience in this tag.
        foreach ($tag->audiences as $audience) {
            // Get the selected item.
            $item = $audience->selecteditems[0];
            // Create the conditions record.
            $postaudiencesconditionid = static::create_posts_audiences_conditions($postid, $postsaudiencesid, $audience, $item);
            // Get the list of users for this audience item.
            $usernames = static::get_audience_usernames($audience, $item);
            $itemusers[] = $usernames;
        }
        // Get the interection of the selected audiences.
        $audienceusers = array_intersect(...$itemusers);
        if (!empty($audienceusers)) {
            // Cache this audience as "relevant" for the users.
            $postsusersaudiences = static::cache_relevant_user_audiences($audienceusers, $postsaudiencesid, $postsusersaudiences);
        }

        return [$audienceusers, $postsusersaudiences];
    }

    /**
    * Helper method to insert post users and their relevant audiences.
    *
    * @param int postid.
    * @param array postusers.
    * @param array $postsusersaudiences. 
    * @return void.
    */
    private static function create_posts_users($postid, $postusers, $postsusersaudiences) {
        global $DB;
        foreach ($postusers as $username) {
            // Insert post users.
            $record = new \stdClass();
            $record->postid = $postid;
            $record->username = $username;
            $postsusersid = $DB->insert_record(static::TABLE_POSTS_USERS, $record);
            // Insert their relevant audiences.
            $relevantpostaudiences = array();
            if (isset($postsusersaudiences[$username])) {
                $relevantpostaudiences = $postsusersaudiences[$username];
            }
            foreach ($relevantpostaudiences as $postsaudiencesid) {
                $record = new \stdClass();
                $record->postsusersid = $postsusersid;
                $record->postsaudiencesid = $postsaudiencesid;
                $record->postid = $postid;
                $postsusersaudiencesid = $DB->insert_record(static::TABLE_POSTS_USERS_AUDIENCES, $record);
            }
        }
    }

    /**
    * Helper method to create the posts audiences record.
    *
    * @param int $postid.
    * @param stdClass $tag. 
    * @return $id. Id of the newly created record.
    */
    private static function create_posts_audiences($postid, $tag) {
        global $DB;

        $record = new \stdClass();
        $record->postid = $postid;
        $record->conditiontype = $tag->type;
        $id = $DB->insert_record(static::TABLE_POSTS_AUDIENCES, $record);

        return $id;
    }

    /**
    * Helper method to create the posts_audiences_cond record.
    *
    * @param int $postid.
    * @param int $postsaudiencesid.
    * @param stdClass $audience. 
    * @param stdClass $item. 
    * @return $id. Id of the newly created record.
    */
    private static function create_posts_audiences_conditions($postid, $postsaudiencesid, $audience, $item) {
        global $DB;

        $record = new \stdClass();
        $record->postid = $postid;
        $record->postsaudiencesid = $postsaudiencesid;
        $record->type = $audience->audiencetype;
        $record->code = $item->code;
        $record->roles = '';

        if (!empty($audience->selectedroles)) {
            $roles = array_column($audience->selectedroles, 'name');
            $record->roles = implode(', ', $roles);
        }

        $id = $DB->insert_record(static::TABLE_POSTS_AUDIENCES_CONDITIONS, $record);

        return $id;
    }

    /**
    * Helper method to get the list of users for an audience item.
    *
    * @param stdClass $audience. 
    * @param stdClass $item. 
    * @return array.
    */
    private static function get_audience_usernames($audience, $item) {
        // Load the providers.
        $providers = audience_loader::get();
        $provname = $audience->audienceprovider;

        // Pluck the selected roles.
        $roles = array();
        if (!empty($audience->selectedroles)) {
            $roles = array_column($audience->selectedroles, 'code');
        }

        // Get the list of users for this audience item.
        $usernames = array_unique($providers[$provname]::get_audience_usernames($item->code, $audience->audiencetype, $roles));

        return $usernames;
    }

    /**
    * Helper method to cache the post audience as relevant for the users.
    *
    * @param array $usernames. List of usersnames that the audience is relevant for.
    * @param array $postsaudiencesid. Id of the post audience record.
    * @param array $postsusersaudiences. The cache.
    * @return array.
    */
    private static function cache_relevant_user_audiences($usernames, $postsaudiencesid, $postsusersaudiences) {
        // Cache the relevant audiences for the users.
        foreach ($usernames as $username) {
            if (!array_key_exists($username, $postsusersaudiences)) {
                $postsusersaudiences[$username] = array();
            }
            $postsusersaudiences[$username][] = $postsaudiencesid;
        }
        return $postsusersaudiences;
    }
                    

    /**
     * Checks to see if the selected audience tags are valid and user has permission to post.
     *
     * @param array $tags.
     * @return boolean
     */
    public static function is_audiences_valid($tags) {
        // Load providers.
        $providers = audience_loader::get();

        // Check it has the required properties.
        foreach ($tags as $tag) {
            // Type must be union or intersection.
            if ($tag->type != "union" && $tag->type != "intersection") {
                return false;
            }

            // Audiences is required.
            if (empty($tag->audiences)) {
                return false;
            }

            foreach ($tag->audiences as $audience) {
                // Valid provider required.
                if(!isset($providers[$audience->audienceprovider])) {
                    return false;
                }

                // type is required.
                if (empty($audience->audiencetype)) {
                    return false;
                }

                // Selecteditems is required.
                if (empty($audience->selecteditems)) {
                    return false;
                }

                // 1 item for intersections.
                if ($tag->type == "intersection") {
                    if (count($audience->selecteditems) != 1) {
                        return false;
                    }
                }

                // Check if user can post to the selected items.
                foreach ($audience->selecteditems as $item) {
                    if (!static::can_user_post_to_audience($audience->audienceprovider, $audience->audiencetype, $item->code)) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    /**
     * Checks with the appropriate provider to determine if user is allowed to post to audience.
     *
     * @param string $provider.
     * @param string $type.
     * @param string $code. 
     * @return boolean
     */
    public static function can_user_post_to_audience($provider = '', $type = '', $code) {
        // Load the provider.
        $provider = get_provider($provider, $type);
        if(!isset($provider)) {
            return false;
        }
        // Ask the provider whether user can post to the given audience.
        return $provider::can_user_post_to_audience($type, $code);
    }

    /**
     * Checks with the appropriate provider to determine whether privilege matches.
     *
     * @param string $audiencetype.
     * @param string $checktype. The check type.
     * @param string $checkvalue. The check value.
     * @param string $code. The audience code.
     * @return boolean.
     */
    public static function check_privilege_for_code($audiencetype, $checktype, $checkvalue, $code) {
        // Load the provider.
        $provider = get_provider(null, $audiencetype);
        if(!isset($provider)) {
            return false;
        }
        // Ask the provider whether user can post to the given audience.
        return $provider::check_privilege_for_code($checktype, $checkvalue, $code);
    }


    /**
     * Tack on sql and params that check whether an announcement is available
     *
     * @param stdClass $user. User that is viewing.
     * @param bool $strict. Whether to exclude pending and rejected posts.
     * @return array Two-element array of SQL and a params array
     */
    public static function append_standard_availability_clauses($user, $strict = true) {
        $now = time();
        $params = array();

        // Exclude deleted posts.
        $sql = " AND p.deleted = 0 ";

        // Determine if viewing user is an admin or auditor.
        $role = false;
        $contextuser = \context_user::instance($user->id);
        if ( is_user_admin() || is_user_auditor() ) { 
            $role = 'admin';
        }

        // Exclude posts that need moderation, unless strict is false - used for admin and auditor.
        $sql .= " AND ( p.modrequired = 0 OR p.modstatus = " . ANN_MOD_STATUS_APPROVED . " OR p.authorusername = ?";
        $params[] = $user->username;
        if (!$strict) {
            $sql .= " OR 'admin' = ? ";
            $params[] = $role;
        }
        $sql .= " ) ";

        // Check time availabilty.
        $sql .= "
        AND ( 
            (p.timestart <= ? AND p.timeend > ?) OR
            (p.timestart <= ? AND p.timeend = 0) OR
            (p.timestart = 0  AND p.timeend > ?) OR
            (p.timestart = 0  AND p.timeend = 0) OR 
            (p.authorusername = ?)";
        $params[] = $now;
        $params[] = $now;
        $params[] = $now;
        $params[] = $now;
        $params[] = $user->username;

        // If the user is admin or auditor include time-based unavailable announcements.
        if (!$strict) {
            $sql .= " OR 'admin' = ? ";
            $params[] = $role;
        }

        $sql .= " ) ";

        return [$sql, $params];
    }

    /**
     * Checks whether user can see post. 
     * Author and audiences are allowed to view. 
     * Auditors and admins are allowed to view the post.
     * Moderator of post can view.
     * This method does not check post availability.
     * Used by search provider to determine whether user can view.
     *
     * @param int $postid.
     * @return boolean
     */
    public static function can_user_view_post($postid) {
        global $DB, $USER;

        // Is the user an author or recipient of the post.
        $sql = "SELECT p.*
                  FROM {" . static::TABLE . "} p 
            INNER JOIN {" . static::TABLE_POSTS_USERS . "} pu
                    ON p.id = pu.postid 
                 WHERE (pu.postid = ? AND pu.username = ?)
                    OR (p.id = ? AND p.authorusername = ?)";
        $params = array($postid, $USER->username, $postid, $USER->username);
        if ($DB->record_exists_sql($sql, $params)) {
            return true;
        }

        // Is the user an admin.
        if (is_user_admin()) {
            return true;
        }

        // Is the user an auditor.
        if (is_user_auditor()) {
            return true;
        }

        // Is the user a moderator of this post.
        if (moderation::can_user_moderate_post($postid)) {
            return true;
        }

        if (is_show_all_in_context()) {
            $combination = get_provider('combination');
            // Check if the user can view this post in context.
            $sql = "SELECT a.provider, c.code
                      FROM {ann_audience_types} a
                INNER JOIN {ann_posts_audiences_cond} c 
                        ON c.type = a.type
                     WHERE provider
                        IN ('mdlcourse', 'mdlgroup') 
                        OR code LIKE '%mdlcourse%' 
                        OR code LIKE '%mdlgroup%'
                       AND postid = ?";
            $params = array($postid);
            $rs = $DB->get_recordset_sql($sql, $params);
            foreach ($rs as $r) {
                $provider = $r->provider;
                $code = $r->code;

                if ($provider == 'combination' && strpos($r->code, 'mdlcourse') === true) {
                    $provider = 'mdlcourse';
                    $code = $combination::true_code($r->code);
                }
                if ($provider == 'combination' && strpos($r->code, 'mdlgroup') === true) {
                    $provider = 'mdlgroup';
                    $code = $combination::true_code($r->code);
                }

                if ($provider == 'mdlcourse') {
                    $courseid = $DB->get_field('course', 'id', array('idnumber' => $code));
                }
                if ($provider == 'mdlgroup') {
                    $courseid = $DB->get_field('groups', 'courseid', array('id' => $code));
                }

                if ( ! empty($courseid)) {
                    $coursecontext = context_course::instance($courseid);
                    if ($coursecontext && can_view_all_in_context($coursecontext)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Checks whether user can see post. 
     * Author and audiences are allowed to view. 
     * This method does not check post availability.
     *
     * @param int $postid.
     * @return boolean
     */
    public static function can_user_edit_post($postid) {
        global $DB;

        // Author, admins and moderators can edit post.
        if (static::is_author_admin_moderator($postid)) {
            return true;
        }

        return false;
    }

    /**
     * Returns a list of available unmailed posts.
     *
     * @param int $now. time to check availability.
     * @return array
     */
    public static function get_unmailed($now = null) {
        global $DB;

        if (empty($now)) {
            $now = time();
        }

        $sql = "SELECT p.*
        FROM {ann_posts} p
        WHERE p.mailed = :mailed
        AND p.savecomplete = 1
        AND ((p.timestart <= :now1 AND p.timeend > :now2) OR
             (p.timestart <= :now3 AND p.timeend = 0) OR
             (p.timestart = 0  AND p.timeend > :now4) OR
             (p.timestart = 0  AND p.timeend = 0))
        AND p.deleted = 0
        AND (p.modrequired = 0 OR p.modstatus = :modstatus)
        ORDER BY p.timemodified ASC";

        $params = array();
        $params['mailed'] = ANN_MAILED_PENDING;
        $params['now1'] = $now;
        $params['now2'] = $now;
        $params['now3'] = $now;
        $params['now4'] = $now;
        $params['modstatus'] = ANN_MOD_STATUS_APPROVED;

        // Create array of announcement persistents.
        $records = $DB->get_records_sql($sql, $params);
        $posts = array();
        foreach ($records as $postid => $record) {
            // Load the announcement as an instance of the persistent for exporting later.
            $announcement = new static(0, $record);
            $posts[$postid] = $announcement;
        }
        return $posts;
    }

    /**
     * Returns a list of available unsent (notifications) posts.
     *
     * @param int $now. time to check availability.
     * @return array
     */
    public static function get_unsent($now = null) {
        global $DB;

        if (empty($now)) {
            $now = time();
        }

        $sql = "SELECT p.*
        FROM {ann_posts} p
        WHERE p.notified = :notified
        AND p.savecomplete = 1
        AND ((p.timestart <= :now1 AND p.timeend > :now2) OR
             (p.timestart <= :now3 AND p.timeend = 0) OR
             (p.timestart = 0  AND p.timeend > :now4) OR
             (p.timestart = 0  AND p.timeend = 0))
        AND p.deleted = 0
        AND (p.modrequired = 0 OR p.modstatus = :modstatus)
        ORDER BY p.timemodified ASC";

        $params = array();
        $params['notified'] = ANN_NOTIFIED_PENDING;
        $params['now1'] = $now;
        $params['now2'] = $now;
        $params['now3'] = $now;
        $params['now4'] = $now;
        $params['modstatus'] = ANN_MOD_STATUS_APPROVED;

        // Create array of announcement persistents.
        $records = $DB->get_records_sql($sql, $params);
        $posts = array();
        foreach ($records as $postid => $record) {
            // Load the announcement as an instance of the persistent for exporting later.
            $announcement = new static(0, $record);
            $posts[$postid] = $announcement;
        }
        return $posts;
    }


    public static function is_author_or_admin($postid) {
        global $USER;

        // Attempt to get the user's announcement.
        if(announcement::get_record(['authorusername' => $USER->username, 'id' => $postid])) {
            return true;
        }

        // If the announcement is not found, this could be an administrator deleting someone elses announcement.
        if (is_user_admin()) {
            return true;
        }

        return false;
    }

    public static function is_author_admin_auditor_moderator($postid) {

        // Author/Admin check.
        if (static::is_author_admin_moderator($postid)) {
            return true;
        }

        // If the announcement is not found, this could be an administrator deleting someone elses announcement.
        if (is_user_auditor()) {
            return true;
        }

        return false;
    }


    public static function is_author_admin_moderator($postid) {
        // Author/Admin check.
        if (static::is_author_or_admin($postid)) {
            return true;
        }

        // Moderators check.
        if (moderation::can_user_moderate_post($postid)) {
            return true;
        }

        return false;
    }

    public static function soft_delete($id) {
        global $DB, $USER;

        // Load the announcement.
        $announcement = new static($id);

        // Soft delete the announcement.
        $announcement->set('deleted', 1);
        $announcement->update();

        // Hard delete the audiences.
        $DB->delete_records(static::TABLE_POSTS_USERS_AUDIENCES, array('postid' => $id));
        $DB->delete_records(static::TABLE_POSTS_USERS, array('postid' => $id));
        $DB->delete_records(static::TABLE_POSTS_AUDIENCES_CONDITIONS, array('postid' => $id));
        $DB->delete_records(static::TABLE_POSTS_AUDIENCES, array('postid' => $id));

        //Hard delete notifications.
        $customdata = '{"postid":"' . $id . '"%';
        $sql = "DELETE FROM {notifications}
                 WHERE component = 'local_announcements'
                   AND (eventtype = 'notifications' OR eventtype = 'moderationmail')
                   AND customdata LIKE '" . $customdata . "'";
        $DB->execute($sql);
        
        return $id;
    }


}
