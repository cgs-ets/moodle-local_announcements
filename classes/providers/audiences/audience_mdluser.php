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
 * User audience type
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_announcements\providers\audiences;

defined('MOODLE_INTERNAL') || die();

use local_announcements\providers\privileges;
use context_user;

class audience_mdluser extends \local_announcements\providers\audience_provider {

    const PROVIDER = 'mdluser';

    const ROLES = array('Users', 'Staff', 'Mentors');

    /**
    * Implementation. For users there are no related audience params.
    *
    * @param string $code
    * @return array Array with the audience code.
    */
    public static function get_related_audience_codes($code) {
        // Return the code itself.
        return array('provider' => 'mdluser', 'code' => $code);
    }

    /**
    * Gets the list of audiences types to display as buttons on the selector.
    *
    * @return array.
    */
    public static function get_audience_types() {
        return parent::get_provider_audience_types(static::PROVIDER, static::ROLES);
    }

    /**
    * Gets the list of users for the audience selector.
    *
    * @param array $type. The selected audience type.
    * @return array. List of audiences to display.
    */
    public static function get_selector_user_audience_associations($type) {
        global $USER, $DB;

        // Load the audience type.
        $audiencetype = get_audiencetype($type);

        if (!static::can_user_post_to_audiencetype($type)) {
            return [];
        }

        $audiences = array();

        $users = $DB->get_records('user', array(
            'confirmed' => 1, 
            'deleted' => 0, 
            'suspended' => 0, 
        ), 'firstname, lastname');

        foreach ($users as $user) {
            // Exclude codes.
            if ($audiencetype->excludecodes) {
                $excludecodes = explode(',', $audiencetype->excludecodes);
                if (in_array($user->username, $excludecodes)) {
                    continue;
                }
            }

            $audiences[] = [
                'id' => $user->id,
                'code' => $user->username,
                'name' => $user->firstname . ' ' . $user->lastname . ' (' . $user->username . ')',
            ];
        }

        return $audiences;

    }

    /**
    * Implementation. Checks whether current user can post to the audience type
    *
    * @param array $type. The selected audience type.
    * @return boolean.
    */
    public static function can_user_post_to_audiencetype($type) {
        global $DB, $USER;

        // Announcement admins can always post
        if (is_user_admin()) { 
            return true;
        }

        $checks = privileges::get_checks($type);
        foreach ($checks as $checktype => $checkvalues) {
            foreach ($checkvalues as $checkvalue) {
                switch ($checktype) {
                    case "usercapability":
                        if(privileges::check_usercapability($checkvalue)) { 
                            return true;
                        }
                        break;
                    case "username":
                        if (privileges::check_username($checkvalue)) {
                            return true;
                        }
                        break;
                    case "profilefield":
                        if (privileges::check_profilefield($checkvalue)) {
                            return true;
                        }
                        break;
                }
            }
        }

        return false;
    }

    /**
    * Implementation. Checks whether current user can post to a specific user.
    *
    * @param array $type. The selected audience type.
    * @param array $code. The selected audience code.
    * @return boolean.
    */
    public static function can_user_post_to_audience($type, $code) {
        global $DB, $USER;

        // Announcement admins can always post.
        if (is_user_admin()) { 
            return true;
        }

        $checks = privileges::get_checks($type, $code);
        foreach ($checks as $checktype => $checkvalues) {
            foreach ($checkvalues as $checkvalue) {
                if(static::check_privilege_for_code($checktype, $checkvalue, $code)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
    * Implementation. Check the privilege for a given code.
    *
    * @param string $checktype. The check type.
    * @param string $checkvalue. The check value.
    * @param string $code. The audience code.
    * @return boolean.
    */
    public static function check_privilege_for_code($checktype, $checkvalue, $code = null) {
        switch ($checktype) {
            case "usercapability":
                if(privileges::check_usercapability($checkvalue)) { 
                    return true;
                }
                break;
            case "username":
                if (privileges::check_username($checkvalue)) {
                    return true;
                }
                break;
            case "profilefield":
                if (privileges::check_profilefield($checkvalue)) {
                    return true;
                }
                break;
            case "exclude":
                return false;
        }
        return false;
    }


    /**
    * Implementation. Gets the audience name by code.
    *
    * @param string $code. The audience code.
    * @return string. The name of the audience.
    */
    public static function get_audience_name($code) {
        global $DB;

        $audienceuser = $DB->get_record('user', array('username'=>$code));

        return $audienceuser->firstname . " " . $audienceuser->lastname;
    }

    /**
    * Implementation. Gets the audience url by code.
    *
    * @param string $code. The audience code.
    * @return string. The url of the audience.
    */
    public static function get_audience_url($code) {
        global $DB;

        $id = $DB->get_field('user', 'id', array('username'=>$code));
        $url = new \moodle_url('/user/profile.php', array('id' => $id));

        return $url->out(false);
    }

    /**
    * Implementation. Gets the usernames for the audience
    *
    * @param string $code. The audience code.
    * @param string $type. The audience type.
    * @param array $roles. The roles to extract.
    * @return array. The usernames of the users in the audience.
    */
    public static function get_audience_usernames($code, $type = '', $roles = array()) {
        global $DB;

        $usernames = [];

        $audienceuser = $DB->get_record('user', array('username'=>$code));
        if ( empty($audienceuser) ) {
            return;
        }

        // If roles is empty then return the selected user only.
        if (empty($roles)) {
            return array($audienceuser->username);
        }

        $roles = array_flip($roles);
        if (array_key_exists('Mentors', $roles)) {
            //get the parents of the user
            $mentorrole = $DB->get_record('role', array('shortname' => 'parent'));
            $sql = "SELECT ra.userid
                        FROM {role_assignments} ra
                        INNER JOIN {user} u
                        ON ra.userid = u.id
                        WHERE ra.roleid = ? 
                        AND ra.contextid IN (SELECT c.id
                            FROM {context} c
                            WHERE c.contextlevel = ?
                            AND c.instanceid = ?)";
            $mentors = $DB->get_records_sql($sql, array($mentorrole->id, CONTEXT_USER, $audienceuser->id));
            foreach ($mentors as $mentor) {
                $usernames[] = $DB->get_field('user', 'username', array('id'=>$mentor->userid));
            }
        }

        // If targeting the selected user only.
        if (array_key_exists('Users', $roles)) {
             $usernames[] = $audienceuser->username;
        }

        // If targeting teachers of this user.
        if (array_key_exists('Staff', $roles)) {
            // Get a list of staff from courses that the users in this audience are enrolled in.
            $sql = "SELECT ue.id, u.username, e.courseid, r.shortname
                      FROM {user_enrolments} ue 
                INNER JOIN {user} u ON u.id = ue.userid
                INNER JOIN {enrol} e ON e.id = ue.enrolid
                INNER JOIN {context} c 
                           ON c.instanceid = e.courseid 
                          AND c.contextlevel = ?
                INNER JOIN {role_assignments} ra 
                           ON ra.contextid = c.id 
                          AND ra.userid = ue.userid
                INNER JOIN {role} r ON r.id = ra.roleid
                     WHERE e.courseid IN (
                           SELECT DISTINCT e.courseid
                             FROM {enrol} e2
                             JOIN {user_enrolments} ue2
                                  ON ue2.enrolid = e2.id 
                                 AND ue2.userid = ?
                            WHERE ue.status = 0
                              AND ue.timestart <= ? 
                              AND (ue.timeend = 0 OR ue.timeend > ? )
                       )
                       AND r.shortname IN ('manager','coursecreator','editingteacher','teacher')";
            $now = time();
            $params = array_merge([CONTEXT_COURSE], [$audienceuser->id], [$now, $now]);

            // Debug sql.
            //echo "<pre>";
            //foreach($params as $replace){$sql = preg_replace('/\?/i', '`'.$replace.'`', $sql, 1);}
            //$sql = preg_replace('/\{/i', 'mdl_', $sql);$sql = preg_replace('/\}/i', '', $sql);
            //var_export($sql);
            //exit;

            $staffusers = $DB->get_records_sql($sql, $params);
            foreach ($staffusers as $staff) {
                $usernames[] = $staff->username;
            }
        }

        return array_unique($usernames);
    }

    /**
    * Implementation. Determines whether the provider has roles. 
    *
    * @return boolean.
    */
    public static function has_roles() {
        return true;
    }


}
