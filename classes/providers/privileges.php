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
 * Provides the {@link local_announcements\providers\privileges} class.
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_announcements\providers;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/announcements/lib.php');
use \context_course;
use \context_user;
use \core_user;

/**
 * privileges functions
 */
class privileges {

    /** Related tables. */
    const TABLE_PRIVILEGES = 'ann_privileges';

    
    /**
     * Gets the relevent privilege checks for the given audiences
     *
     * @return array An array of checks that need be performed.
     */
    public static function get_checks($type, $code = false, $codes = false) {
        global $DB;

        $checks = array();
        if ($codes === false) {
            if ($code === false) {
                $sql = "SELECT id, checktype, checkvalue, checkorder
                          FROM {ann_privileges}
                         WHERE active = 1
                           AND audiencetype = ?
                      ORDER BY checkorder ASC";
                $params = array($type);
            } else {
                if ($code === '') {
                    return array();
                }
                $sql = "SELECT id, checktype, checkvalue, checkorder
                          FROM {ann_privileges}
                         WHERE active = 1
                           AND audiencetype = ?
                           AND (? LIKE code
                            OR code = '*')
                      ORDER BY checkorder ASC";
                $params = array($type, $code);
            }

            $privileges = $DB->get_records_sql($sql, $params);
            foreach ($privileges as $privilege) {
                if (!array_key_exists($privilege->checktype, $checks)) {
                    $checks[$privilege->checktype] = array();
                }
                if(in_array($privilege->checkvalue, $checks[$privilege->checktype])) {
                    continue;
                }
                $checks[$privilege->checktype][] = $privilege->checkvalue;
            }
        } else {
            $codesql = array();
            $codeparams = array();
            foreach ($codes as $code) {
                if ($code) {
                    $codesql[] = '? LIKE code';
                    $codeparams[] = $code;
                }
            }
            $sql = "SELECT id, checktype, checkvalue, checkorder, code as regcode
                      FROM {ann_privileges}
                     WHERE active = 1
                       AND audiencetype = ?
                       AND (" . implode(' OR ', $codesql) . " OR code = '*')
                  ORDER BY checkorder ASC";
            $params = array_merge([$type], $codeparams);

            $privileges = $DB->get_records_sql($sql, $params);
            foreach ($privileges as $privilege) {
                $regcode = $privilege->regcode;
                $checktype = $privilege->checktype;
                $checkvalue = $privilege->checkvalue;

                if (!array_key_exists($regcode, $checks)) {
                    $checks[$regcode] = array();
                }
                if (!array_key_exists($checktype, $checks[$regcode])) {
                    $checks[$regcode][$checktype] = array();
                }
                if(in_array($checkvalue, $checks[$regcode][$checktype])) {
                    continue;
                }
                $checks[$regcode][$checktype][] = $checkvalue;
            }

        }
        
        return $checks;
    }


    /**
     * Gets the relevent privileges for the given audience
     *
     * @param string $type Audience type.
     * @param string $code Audience code.
     * @return array An array of privileges.
     */
    public static function get_for_audience($type, $code) {
        global $DB;

        if (empty($code)) {
            return array();
        }

        // Get the provider and check if there is a translation for the code.
        // For combination audiences, the code comes in as a combo string so we need to
        // extract the code from it.
        $provider = get_provider('', $type);
        if(isset($provider)) {
            $code = $provider::true_code($code);
        }

        $sql = "SELECT *
                  FROM {ann_privileges}
                 WHERE active = 1
                   AND audiencetype = ?
                   AND (? LIKE code
                    OR code = '*')
              ORDER BY checkorder ASC";
        $params = array($type, $code);
        
        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Checks capability exists before testing it.
     *
     * @param string $capability the name of the capability to check.
     * @param context $context the context to check the capability in. You normally get this with instance method of a context class.
     * @param integer|stdClass $user A user id or object. By default (null) checks the permissions of the current user.
     * @param boolean $doanything If false, ignores effect of admin role assignment
     * @return boolean true if the user has this capability. Otherwise false.
     */
    public static function safely_check_has_cap($cap, $context, $user = null, $doanything = false) {
        if (get_capability_info($cap) && has_capability($cap, $context, $user, $doanything)) {
            return true;
        }
        return false;
    }


    /**
     * Helper for profilefield check
     *
     * @param string $checkvalue
     * @return boolean true if the user has this profile field value. Otherwise false.
     */
    public static function check_profilefield($checkvalue) {
        global $USER;
        // Format is "profilefieldname=valuetocheck".
        $profilecheck = explode('=', $checkvalue);
        if (isset($profilecheck[0]) && isset($profilecheck[1]) && isset($USER->profile[$profilecheck[0]])) {
            $profilefieldvalue = $USER->profile[$profilecheck[0]];
            // Profile field may contain csv values.
            $checkvalues = explode(',', $profilefieldvalue);
            $regex = $profilecheck[1] == '*' ? '(.*)' : str_replace('*', '(.*)', $profilecheck[1]);
            $regex = '/' . $regex . '/i';
            if ($profilecheck[1] == '*' || preg_grep($regex, $checkvalues)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Helper for usercapability check
     *
     * @param string $checkvalue
     * @return boolean true if the user has this capability. Otherwise false.
     */
    public static function check_usercapability($checkvalue) {
        global $USER;
        return static::safely_check_has_cap($checkvalue, context_user::instance($USER->id));
    }


    /**
     * Helper for username check
     *
     * @param string $checkvalue
     * @return boolean true if the username matches. Otherwise false.
     */
    public static function check_username($checkvalue) {
        global $USER;
        return ($USER->username == $checkvalue);
    }

    /**
     * Helper for coursecapability check
     *
     * @param string $checkvalue
     * @return boolean true if the user has capability in any course. Otherwise false.
     */
    public static function check_coursecapability($checkvalue, $code = '') {
        global $DB;

        if (empty($code)) {
            $courses = get_user_capability_course($checkvalue, null, false, "id");
            if (!empty($courses)) {
                return true;
            }
        } else {
            $courseid = $DB->get_field('course', 'id', array('idnumber' => $code));
            if ($courseid) {
                $coursecontext = context_course::instance($courseid);
                if(static::safely_check_has_cap($checkvalue, $coursecontext)) {
                    return true;
                }
            }
        }
        
        return false;
    }


}
