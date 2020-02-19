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
 * Audience type for user profile fields
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_announcements\providers\audiences;

defined('MOODLE_INTERNAL') || die();

use local_announcements\providers\privileges;
use context_user;
use context_course;

class audience_mdlprofile extends \local_announcements\providers\audience_provider {

    const PROVIDER = 'mdlprofile';

    const ROLES = array('Users', 'Staff', 'Mentors');

    /**
    * Implementation. For profile fields there are no related audience codes.
    *
    * @param string $code
    * @return array Array of audience codes
    */
    public static function get_related_audience_codes($code) {
        // Return the code itself.
        return array('provider' => 'mdlprofile', 'code' => $code);
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
    * Gets the list of audience targets for the audience selector.
    *
    * @param array $type. The selected audience type.
    * @return array. List of audiences to display.
    */
    public static function get_selector_user_audience_associations($type) {
        global $USER, $DB;

        $audiences = array();

        // Load the audience type.
        $audiencetype = get_audiencetype($type);

        // Param is required for profile audiences as this identifies which field to look at.
        if (empty($audiencetype->scope)) {
            return [];
        }

        $items = preg_split('/\r\n|\r|\n/', $audiencetype->itemsoverride);

        // If items are not pre defined through audiencesettings.php.
        if (empty($items)) {
            // Extract a list of possible targets from the profile field.
            $sql = "SELECT DISTINCT d.id, d.data
                               FROM {user_info_data} d
                         INNER JOIN {user_info_field} f on f.id = d.fieldid
                              WHERE f.shortname = ?";
            $res = $DB->get_records_sql($sql, [$audiencetype->scope]);
            // Generate an array of unique audiences.
            $items = array();
            foreach ($res as $seq => $field) {
                $vals = array_filter(explode(',', $field->data));
                $items = array_unique(array_merge($items, $vals));
            }
        }

        // Exclude codes.
        if ($audiencetype->excludecodes) {
            $excludecodes = explode(',', $audiencetype->excludecodes);
            $items = array_diff($items, $excludecodes);
        }

        // Prepare the items for the selector.
        foreach ($items as $code) {
            if (empty($code)) {
                continue;
            }

            // Check if user is allowed to post to this code.
            if (!static::can_user_post_to_audience($type, $code)) {
                continue;
            }
            
            $audienceout = array(
                'id' => $code,
                'code' => $code,
                'name' => $code,
            );
            if ($audiencetype->grouped && $audiencetype->groupdelimiter) {
                $parts = explode($audiencetype->groupdelimiter, $code);
                if (count($parts) == 2) {
                    $audienceout['groupbyname'] = $parts[0];
                    $audienceout['groupbykey'] = $parts[0];
                    $audienceout['groupitemname'] = $parts[1];
                    $audienceout['name'] = $parts[0] . ' ' . $parts[1];
                }
            }
            $audiences[] = $audienceout;
        }

        if ($audiencetype->grouped) {
            $audiences = parent::list_to_tree($audiences);
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
    * Implementation. Checks whether current user can post to a specific profile field.
    *
    * @param array $type. The selected audience type.
    * @param array $code. The selected audience code.
    * @return boolean.
    */
    public static function can_user_post_to_audience($type, $code) {
        global $DB, $USER;

        // Announcement admins can always post
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
        // There is nothing to say what the "name" of a profile field value should be, other than itself.
        return $code;
    }

    /**
    * Implementation. Gets the audience url by code.
    *
    * @param string $code. The audience code.
    * @return string. The url of the audience.
    */
    public static function get_audience_url($code) {
        // There is nothing to say what the "url" of a profile field value should be.
        return '';
    }

    /**
    * Implementation. Gets the usernames for the audience
    *
    * @param string $code. The audience code. E.g. Senior School:Staff
    * @param string $type. The audience type. E.g. CampusRoles
    * @param array $roles. The roles to extract. The roles to extract. E.g. students,parentsof
    * @param boolean $iscombo. Whether this call is coming from a combination audience in which case the type is the scope.
    * @return array. The usernames of the users in the audience.
    */
    public static function get_audience_usernames($code, $type = '', $roles = array(), $iscombo = false) {
        global $DB;

        $usernames = array();
        $roles = array_flip($roles);

        // Get users that have this profile field value.
        list($profusernames, $userids) = static::get_profilefield_userinfo($code, $type, $iscombo);

        // If targeting the profile user.
        if (array_key_exists('Users', $roles)) {
             $usernames = array_merge($usernames, $profusernames);
        }
        
        // If targeting parents of users.
        if (array_key_exists('Mentors', $roles)) {
            // Need to get mentors.
            $mentorrole = $DB->get_record('role', array('shortname' => 'parent'));
            if (!empty($mentorrole)) {
                foreach ($userids as $userid) {
                    $sql = "SELECT ra.userid
                                FROM {role_assignments} ra
                                INNER JOIN {user} u
                                ON ra.userid = u.id
                                WHERE ra.roleid = ? 
                                AND ra.contextid IN (SELECT c.id
                                    FROM {context} c
                                    WHERE c.contextlevel = ?
                                    AND c.instanceid = ?)";
                    $mentors = $DB->get_records_sql($sql, array($mentorrole->id, CONTEXT_USER, $userid));
                    foreach ($mentors as $mentor) {
                        $usernames[] = $DB->get_field('user', 'username', array('id'=>$mentor->userid));
                    }
                }
            }
        }

        // If targeting staff of students in the audience.
        if (array_key_exists('Staff', $roles)) {
            // Get a list of staff from courses that the users in this audience are enrolled in.
            list($insql, $inparams) = $DB->get_in_or_equal($userids);
            $now = time();
            $params = array_merge([CONTEXT_COURSE], $inparams, [$now, $now]);
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
                                 AND ue2.userid $insql
                            WHERE ue.status = 0
                              AND ue.timestart <= ? 
                              AND (ue.timeend = 0 OR ue.timeend > ? )
                       )
                       AND r.shortname IN ('manager','coursecreator','editingteacher','teacher')";

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
    * Implementation. Gets the usernames for the audience
    *
    * @param string $code. The audience code. E.g. @PC
    * @param string $type. The audience type. E.g. ConstitCodes
    * @param boolean $iscombo. Whether this call is coming from a combination audience in which case the type is the scope.
    * @return array. The usernames of the users in the audience.
    */
    public static function get_profilefield_userinfo($code, $type = '', $iscombo = false) {
        global $DB;

        $scope = $type;
        if (!$iscombo) {
            // Load the audience type.
            $audiencetype = get_audiencetype($type);
            // Param is required for profile audiences as this identifies which field to look at.
            if (empty($audiencetype->scope)) {
                return array([],[]);
            }
            $scope = $audiencetype->scope;
        }
        
        $usernames = array();
        $userids = array();

        // Get a list of users that have the specified code in the profile field.
        $sql = "SELECT DISTINCT u.username, u.id
                           FROM {user_info_data} d
                     INNER JOIN {user_info_field} f on f.id = d.fieldid
                     INNER JOIN {user} u on d.userid = u.id
                          WHERE f.shortname = ?
                            AND d.data LIKE ?";
        $params = array();
        $params[] = $scope;
        $params[] = '%'.$DB->sql_like_escape($code).'%';

        // Load in usernames.
        $rows = $DB->get_records_sql($sql, $params);
        foreach ($rows as $userinfo) {
            $usernames[] = $userinfo->username;
            $userids[] = $userinfo->id;
        }

        $usernames = array_unique($usernames);
        $userids = array_unique($userids);

        return array($usernames, $userids);
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
