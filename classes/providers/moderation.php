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
 * Provides the {@link local_announcements\providers\moderation} class.
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_announcements\providers;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/announcements/lib.php');
use local_announcements\persistents\announcement;
use local_announcements\providers\privileges;
use \context_user;
use \core_user;

/**
 * Moderation functions
 */
class moderation {

    /** Related tables. */
    const TABLE_POSTS = 'ann_posts';
    const TABLE_POSTS_MODERATION = 'ann_posts_moderation';
    const TABLE_PRIVILEGES = 'ann_privileges';
    const TABLE_MODERATION_ASSISTANTS = 'ann_moderator_assistants';

    /**
     * Check if the user can moderate the post.
     *
     * @param string $postid. Id of the announcement.
     * @param bool $pendingonly. Only look at pending posts.
     * @param string $username. Username otherwise current user is checked.
     * @return bool.
     */
    public static function can_user_moderate_post($postid, $pendingonly = false, $username = '') {
        global $DB, $USER;

        if (empty($username)) {
            $username = $USER->username;
        }

        $sql = "SELECT m.*
                  FROM {" . static::TABLE_POSTS . "} p
            INNER JOIN {" . static::TABLE_POSTS_MODERATION . "} m
                    ON p.id = m.postid 
                 WHERE p.id = ?
                   AND p.deleted = 0
                   AND m.active = 1
                   AND (m.modusername = ? OR m.modusername IN (
                       SELECT modusername
                         FROM {" . static::TABLE_MODERATION_ASSISTANTS . "} a 
                        WHERE assistantusername = ? ))";

        if ($pendingonly) {
            $sql .= " AND m.status = " . ANN_MOD_STATUS_PENDING;
        }
        $params = array();
        $params[] = $postid;
        $params[] = $username;
        $params[] = $username;

        // Check if record exists.
        if ($DB->record_exists_sql($sql, $params)) {
            return true;
        }

        return false;
    }

    /**
     * Reject an announcement for moderation.
     *
     * @param int $postid. Id of the announcement.
     * @param string $comment.
     * @param int $modid. Id of the moderation record.
     * @return int|bool.
     */
    public static function mod_reject($postid, $comment, $modid = 0) {
        global $DB, $USER;

        if (static::can_user_moderate_post($postid)) {
            $conditions = array('postid' => $postid, 'status' => ANN_MOD_STATUS_PENDING, 'active' => 1);
            if ($modid) {
                $conditions['id'] = $modid;
            }
            $moderation = $DB->get_record(
                static::TABLE_POSTS_MODERATION,
                $conditions,
                '*',
                IGNORE_MISSING
            );

            // If no moderation row found something went wrong in the creation process.
            if (!empty($moderation->id)) {
                // Get the relevant announcement.
                $announcement = new announcement($postid);
                // If the announcement has been edited since this mod record, do not continue.
                if ($announcement->get('timemodified') > $moderation->timecreated) {
                    return false;
                }
                // Update record in ann_posts_moderation.
                $moderation->actionedusername = $USER->username;
                $moderation->status = ANN_MOD_STATUS_REJECTED;
                $moderation->comment = $comment;
                $moderation->timemoderated = time();
                $DB->update_record(static::TABLE_POSTS_MODERATION, $moderation);
                // Update announcement moderation details.
                $announcement->set('modstatus', ANN_MOD_STATUS_REJECTED);
                $announcement->update();

                // Create an adhoc task to send notification to author.
                $task = new \local_announcements\task\send_moderation_info();
                $user = core_user::get_user_by_username($announcement->get('authorusername'));
                $task->set_userid($user->id);
                $data = array(
                    'postsmoderationid' => $moderation->id,
                    'messagetype' => 'REJECTED',
                );
                $task->set_custom_data($data);
                $task->set_component('local_announcements');
                \core\task\manager::queue_adhoc_task($task);

                return $postid;
            }  
        }

        return false;
    }

    /**
     * Approve an announcement.
     *
     * @param int $postid. Id of the announcement.
     * @param int $modid. optional id of the moderation record.
     * @return int|bool.
     */
    public static function mod_approve($postid, $modid = 0) {
        global $DB, $USER;

        if (static::can_user_moderate_post($postid)) {
            $conditions = array('postid' => $postid, 'status' => ANN_MOD_STATUS_PENDING, 'active' => 1);
            if ($modid) {
                $conditions['id'] = $modid;
            }
            $moderation = $DB->get_record(
                static::TABLE_POSTS_MODERATION,
                $conditions,
                '*',
                IGNORE_MISSING
            );

            // If no moderation row found something went wrong in the creation process.
            if (!empty($moderation->id)) {
                // Get the relevant announcement.
                $announcement = new announcement($postid);

                // Update record in ann_posts_moderation.
                $moderation->actionedusername = $USER->username;
                $moderation->status = ANN_MOD_STATUS_APPROVED;
                $moderation->timemoderated = time();
                $DB->update_record(static::TABLE_POSTS_MODERATION, $moderation);
                
                // Update announcement moderation details.
                $announcement->set('modstatus', ANN_MOD_STATUS_APPROVED);
                $announcement->update();

                // Create an adhoc task to send notification to author.
                $task = new \local_announcements\task\send_moderation_info();
                $user = core_user::get_user_by_username($announcement->get('authorusername'));
                $task->set_userid($user->id);
                $data = array(
                    'postsmoderationid' => $moderation->id,
                    'messagetype' => 'APPROVED',
                );
                $task->set_custom_data($data);
                $task->set_component('local_announcements');
                \core\task\manager::queue_adhoc_task($task);

                return $postid;
            }
        }

        return false;
    }

    /**
     * Set a moderation record to mailed.
     *
     * @param string $postsmoderationid.
     * @return void.
     */
    public static function mod_setmailed($postsmoderationid) {
        global $DB, $USER;
        
        $moderation = $DB->get_record(
            static::TABLE_POSTS_MODERATION,
            array('id' => $postsmoderationid),
            '*',
            IGNORE_MISSING
        );

        // If no moderation row found something went wrong.
        if (!empty($moderation)) {
            // Update record in ann_posts_moderation.
            $moderation->mailed = 1;
            $DB->update_record(static::TABLE_POSTS_MODERATION, $moderation);
        }
    
    }

    /**
     * Checks to see if moderation is needed and saves it to the database.
     *
     * @param int $postid.
     * @param array $tags.
     * @return void.
     */
    public static function setup_moderation($postid, $tags) {
        global $DB, $USER;

        // Deactivate any current moderations.
        static::deactivate_current_moderation($postid);

        // Check whether the user is an "unmoderated announcer".
        $usercontext = context_user::instance($USER->id);
        if (has_capability('local/announcements:unmoderatedannouncer', $usercontext, null, false)) {
            return;
        }

        // Load the announcement persistent.
        $announcement = new announcement($postid);

        // If the announcement is a force send check whether user has cap to send them without mod.
        if ($announcement->get('forcesend')) {
            if (has_capability('local/announcements:emergencyannouncer', $usercontext, null, false)) {
                // No moderation needed.
                return;
            }
        }

        // Determine moderation for the selected audiences.
        $modsettings = static::get_moderation_for_audiences($tags);

        // Save moderation to database.
        if ($modsettings['required']) {
            // Update announcement moderation details.
            $announcement->set('modrequired', ANN_MOD_REQUIRED_YES);
            $announcement->set('modstatus', ANN_MOD_STATUS_PENDING);

            // Add new record to ann_posts_moderation.
            $modrec = new \stdClass();
            $modrec->postid = $postid;
            $modrec->privilegeid = $modsettings['privilegeid'];
            $modrec->modusername = $modsettings['modusername'];
            $modrec->actionedusername = '';
            $modrec->status = ANN_MOD_STATUS_PENDING;
            $modrec->mailed = ANN_MOD_MAIL_PENDING;
            $modrec->comment = '';
            $modrec->timecreated = time();

            // Continue without moderation if the user is the moderator or assistant moderator.
            if ($modsettings['autoapprove']) {
                // Auto approve the announcement.
                $announcement->set('modstatus', ANN_MOD_STATUS_APPROVED);
                $modrec->actionedusername = $USER->username;
                $modrec->status = ANN_MOD_STATUS_APPROVED;
                $modrec->mailed = ANN_MOD_MAIL_SENT; // Mark as sent.
                $modrec->comment = 'Auto approved.';
            }
            
            // Update announcement and create new mod record.
            $announcement->update();
            $postsmoderationid = $DB->insert_record(static::TABLE_POSTS_MODERATION, $modrec);

            // Create an adhoc task to send notification to moderator.
            if (!$modrec->mailed) {
                $task = new \local_announcements\task\send_moderation_info();
                $user = core_user::get_user_by_username($modrec->modusername);
                $task->set_userid($user->id);
                $data = array(
                    'postsmoderationid' => $postsmoderationid,
                    'messagetype' => 'PENDING',
                );
                $task->set_custom_data($data);
                $task->set_component('local_announcements');
                \core\task\manager::queue_adhoc_task($task);
            }
        }
    }

    /**
     * Checks to see if moderation is needed for the post based on the selected audiences.
     * If the post is an intersection, moderation is not required if moderation is not
     * required for at least one of the audiences within the intersection. If union the
     * post contains a union, moderation is required if moderation is required for any
     * of the specified audiences. If a check matches multiple rows where modrequired
     * is true, modprioirty is used to determine who should moderate the post.
     *
     * @param array $tags. The selected audiences.
     * @return void.
     */
    public static function get_moderation_for_audiences($tags) {
        global $USER;

        // Default.
        $moderation = array('required' => false, 'modpriority' => -999);

        // Check whether the user is an "unmoderated announcer".
        $usercontext = context_user::instance($USER->id);
        if (has_capability('local/announcements:unmoderatedannouncer', $usercontext, null, false)) {
            return $moderation;
        }

        // Keep track of moderation requirements as we process audiences.
        $modmatches = array();
        foreach ($tags as $tag) {
            $condition = $tag->type;
            // Keep track of intersections separately.
            $intersectionmodmatches = array();
            foreach ($tag->audiences as $audience) {
                $type = $audience->audiencetype;
                $roles = array();
                if (!empty($audience->selectedroles)) {
                    $roles = array_column($audience->selectedroles, 'code');
                }
                foreach ($audience->selecteditems as $item) {
                    $mods = static::is_mod_required_for_audience($type, $item->code, $roles, $condition);
                    foreach ($mods as $mod) {
                        if ($condition == 'intersection') {
                            if ($mod['required']) {
                                $intersectionmodmatches[] = $mod;
                            } else {
                                // Moderation is not required for this intersection.
                                $intersectionmodmatches = array();
                                // Skip entire tag as moderation is not required for this intersection.
                                break 3;
                            }
                        } else {
                            if ($mod['required']) {
                                $modmatches[] = $mod;
                            }
                        }
                    }
                }
            }

            // For interestections, get the lowest priority moderation.
            if ($intersectionmodmatches) {
                $intersectionmod = array('modpriority' => 9999999);
                foreach ($intersectionmodmatches as $mod) {
                    if ($mod['modpriority'] <= $intersectionmod['modpriority']) {
                        $intersectionmod = $mod;
                    }
                }
                // Merge union and intersection moderation requirements.
                $modmatches = array_merge($modmatches, array($intersectionmod));
            }

        }

        // Remove any moderation matches that do not meet thresholds.
        $removemods = array();
        foreach ($modmatches as $i => $mod) {
            $privid = $mod['privilegeid'];
            if (in_array($privid, $removemods)) {
                continue;
            }
            $threshold = $mod['modthreshold'];
            if ($threshold > 0) {
                $countmatches = count(array_filter($modmatches, function($m) use ($privid) {
                    return $m['privilegeid'] === $privid;
                }));
                if ($countmatches < $threshold) {
                    // Remove modmatches based on this privid.
                    $removemods[] = $privid;
                }
            }
        }
        $modmatches = array_filter($modmatches, function($m) use($removemods){
            return !in_array($m['privilegeid'], $removemods);
        });

        // Determine highest priority.
        foreach ($modmatches as $mod) {
            if ($mod['modpriority'] >= $moderation['modpriority']) {
                $moderation = $mod;
            }
        }

        // Check if the user is the moderator or assistant moderator.
        if ($moderation['required']) {
            $moderation['autoapprove'] = false;
            $assistants = static::get_moderator_assistants($moderation['modusername']);
            if ($moderation['modusername'] == $USER->username || array_key_exists($USER->username, $assistants)) {
                 $moderation['autoapprove'] = true;
            }
        }


        return $moderation;
    }


    /**
    * Checks whether moderation is required for posting to a specific audience.
    *
    * @param array $type. The selected audience type.
    * @param array $code. The selected audience code.
    * @param array $roles. The selected audience roles.
    * @param string $condition. The audience condition.
    * @return array. Index 0 is always the main moderation check, additional indexes any threshold based checks.
    */
    public static function is_mod_required_for_audience($type, $code, $roles = array(), $condition = "*") {
        global $USER;

        // Default, mod not required.
        $mods = array();
        $mods[0] = array(
            'type' => $type,
            'code' => $code,
            'roles' => $roles,
            'condition' => $condition,
            'required' => false,
            'modusername' => null,
            'modpriority' => -1,
            'modthreshold' => -1,
            'privilegeid' => -1,
            'description' => '',
        );

        // Announcement admins always bypass moderation.
        if (is_user_admin()) { 
            return $mods;
        }

        // Get privileges for this audience.
        $privileges = privileges::get_for_audience($type, $code);
        // Initialise modpriority and process privileges one at a time.
        $modpriority  = -1;
        foreach ($privileges as $privilege) {
            // Skip if the privilege pertains to a specific role and the roles is not included in the selected audience.
            if ($privilege->role != "*" && !in_array($privilege->role, $roles)) {
                continue;
            }
            // Skip if the privilege does not pertain to this condition type.
            if ($privilege->condition != "*" && $privilege != $condition) {
                continue;
            }
            // Skip if the privilege does not pertain to this user/audience.
            if (!announcement::check_privilege_for_code($type, $privilege->checktype, $privilege->checkvalue, $code)) {
                continue;
            }
            // Moderation is not required, exit early.
            if (!$privilege->modrequired) {
                return $mods;
            }
            // Moderation required.
            if ($privilege->modthreshold == -1 && $privilege->modpriority >= $modpriority) {
                // New top mod priority.
                $modpriority = $privilege->modpriority;
                $mods[0]['required'] = true;
                $mods[0]['modusername'] = $privilege->modusername;
                $mods[0]['modpriority'] = $privilege->modpriority;
                $mods[0]['modthreshold'] = $privilege->modthreshold;
                $mods[0]['privilegeid'] = $privilege->id;
                $mods[0]['description'] = $privilege->description;
            }
            if ($privilege->modthreshold > 0) {
                // Append all threshold based check for processing later.
                $mods[] = array(
                    'type' => $type,
                    'code' => $code,
                    'roles' => $roles,
                    'condition' => $condition,
                    'required' => true,
                    'modusername' => $privilege->modusername,
                    'modpriority' => $privilege->modpriority,
                    'modthreshold' => $privilege->modthreshold,
                    'privilegeid' => $privilege->id,
                    'description' => $privilege->description,
                );
            }
        }

        return $mods;
    }

    /**
     * Check if the user can moderate the post and if so, return the persistent.
     *
     * @param string $postid. Id of the announcement.
     * @return array|bool.
     */
    public static function get_post_for_moderation($postid) {
        if (static::can_user_moderate_post($postid, true)) {
            return announcement::get_with_all_audiences($postid);
        }
        return false;
    }

    /**
     * Get any moderation info.
     *
     * @param string $postid. Id of the announcement.
     * @return array.
     */
    public static function get_mod_info($id) {
        global $DB, $USER;

        $modinfo = array();

        $record = $DB->get_record(static::TABLE_POSTS_MODERATION, array('postid' => $id, 'active' => 1));
        if ($record) {
            $moduser = $DB->get_record('user', array('username'=>$record->modusername));
            $moduserphoto = new \moodle_url('/user/pix.php/'.$moduser->id.'/f2.jpg');
            $actionedfn = '';
            $actionedphoto = '';
            if ($record->actionedusername) {
                $actioneduser = $DB->get_record('user', array('username'=>$record->actionedusername));
                $actionedfn = fullname($actioneduser);
                $actionedphoto = new \moodle_url('/user/pix.php/'.$actioneduser->id.'/f2.jpg');
                $actionedphoto = $actionedphoto->out(false);
            }
            $isaltmoderator = ($USER->username != $record->modusername);
            $isactionedbyaltmoderator = ($record->actionedusername != '' && $record->actionedusername != $record->modusername);
            // Only display comment if the post is rejected etc, otherwise mod comments will be visible on availble posts.
            $displaycomment = ($record->status == ANN_MOD_STATUS_REJECTED || $record->status == ANN_MOD_STATUS_DEFERRED);
            $modinfo = array(
                'moduserphoto' => $moduserphoto->out(false),
                'moduserfullname' => fullname($moduser),
                'modcomment' => $record->comment,
                'displaycomment' => $displaycomment,
                'isaltmoderator' => $isaltmoderator,
                'isactionedbyaltmoderator' => $isactionedbyaltmoderator,
                'actioneduserfullname' => $actionedfn,
                'actioneduserphoto' => $actionedphoto,
            );
        }

        return $modinfo;
    }

    /**
     * Get announcements for moderator by moderation status.
     *
     * @param string $status. The status code of the moderation record.
     * @return array. An array of announcements.
     */
    public static function get_posts_by_moderation_status($status) {
        global $DB, $USER;

        $announcements = array();

        $sql = "SELECT m.id, m.postid
                  FROM {" . static::TABLE_POSTS . "} p
            INNER JOIN {" . static::TABLE_POSTS_MODERATION . "} m
                    ON p.id = m.postid 
                 WHERE p.deleted = 0
                   AND m.status = ?
                   AND m.active = 1
                   AND ( m.modusername = ? OR m.modusername IN (
                       SELECT modusername
                         FROM {" . static::TABLE_MODERATION_ASSISTANTS . "} a 
                        WHERE assistantusername = ? ))
              ORDER BY p.timemodified DESC, p.id DESC";
        $params = array();
        $params[] = $status;
        $params[] = $USER->username;
        $params[] = $USER->username;

        // Load the announcements.
        $rows = $DB->get_records_sql($sql, $params);
        foreach ($rows as $row) {
            $announcements[] = announcement::get_with_all_audiences($row->postid);
        }

        return $announcements;
    }


    /**
     * Get a list of alternate moderators for an announcement.
     * Alternate moderators are ones with equal or higher modpriority.
     *
     * @param string $postid. Id of the announcement,
     * @return array.
     */
    public static function get_alternate_moderators($postid) {
        global $DB, $USER;

        $moderators = array();

        $sql = "SELECT *
                  FROM {" . static::TABLE_PRIVILEGES . "}
                  WHERE modpriority >= (
                    SELECT modpriority
                      FROM {" . static::TABLE_PRIVILEGES . "}
                     WHERE id = (
                        SELECT privilegeid
                          FROM {" . static::TABLE_POSTS_MODERATION . "}
                          WHERE postid = ?
                            AND status = 0
                            AND active = 1
                            AND modusername = ?
                        )
                    )";
        $params = array();
        $params[] = $postid;
        $params[] = $USER->username;

        // Load the announcements.
        $rows = $DB->get_records_sql($sql, $params);
        foreach ($rows as $row) {
            if ($row->modusername == $USER->username || array_key_exists($row->modusername, $moderators)) {
                continue;
            }
            $user = core_user::get_user_by_username($row->modusername);
            if ($user) {
                $moderators[$user->username] = array(
                    'username' => $user->username,
                    'fullname' => fullname($user),
                );
            }
        }

        return $moderators;

    }

    /**
     * Get a list of assistants for a primary moderator.
     *
     * @param string $postid. Id of the announcement,
     * @return array.
     */
    public static function get_moderator_assistants($username) {
        global $DB;

        $sql = "SELECT assistantusername
                  FROM {" . static::TABLE_MODERATION_ASSISTANTS . "}
                  WHERE modusername = ?";
        $params = array();
        $params[] = $username;

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * This function checks whether the user can moderate
     *
     * @return bool
     */
    public static function can_user_moderate() {
        global $DB, $USER;

        // Guest and not-logged-in users can not post
        if (isguestuser() or !isloggedin()) {
            return false;
        }

        // Admins bypass checks
        if (is_user_admin()) { 
            return true;
        }

        if ($DB->record_exists(static::TABLE_PRIVILEGES, array('modusername' => $USER->username, 'active' => 1))) {
            return true;
        }

        if ($DB->record_exists(static::TABLE_MODERATION_ASSISTANTS, array('assistantusername' => $USER->username))) {
            return true;
        }

        return false;
    }


    /**
     * Deactivate current moderation.
     *
     * @param string $postid. Id of the announcement.
     * @return array.
     */
    public static function deactivate_current_moderation($postid) {
        global $DB;

        $sql = "UPDATE {" . static::TABLE_POSTS_MODERATION . "}
                   SET active = 0
                 WHERE postid = ?
                   AND active = 1";
        $DB->execute($sql, array($postid));

    }

}
