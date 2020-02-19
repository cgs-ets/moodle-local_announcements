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
 * Group audience type
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

class audience_mdlgroup extends \local_announcements\providers\audience_provider {

    const PROVIDER = 'mdlgroup';

    /**
    * Implementation. For groups there are no related audience codes.
    *
    * @param string $code
    * @return array Array of audience codes
    */
    public static function get_related_audience_codes($code) {
        // Return the code itself.
        return array('provider' => 'mdlgroup', 'code' => $code);
    }

    /**
    * Convert moodle course roles to announcement role codes. Need a way to configure this.
    *
    * @param string $username.
    * @return string role.
    */
    public static function transform_role($role) {
        $role = strtolower($role);
        switch($role){
            case "manager":         $code = "staff";break;
            case "coursecreator":   $code = "staff";break;
            case "editingteacher":  $code = "staff";break;
            case "teacher":         $code = "staff";break;
            case "student":         $code = "students";break;
            case "parent":         $code = "parents";break;
            default:                $code = "";
        }
        return $code;
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
    * Gets the audience type of the given group
    *
    * @param array $code. 
    * @return string.
    */
    public static function get_audience_type($code) {
        global $DB;

        // Get all the audience types from the mdlgroups provider.
        $allaudiencetypes = static::get_audience_types();
        if (count($allaudiencetypes) == 1) {
            $aud = array_pop($allaudiencetypes);
            return $aud->type;
        }
        // Load up the course category.
        // Groups can have duplicate idnumber so we can't depend on that here.
        // Get the course based on the code (group id).
        $sql = "SELECT c.*
                FROM {course} c
                INNER JOIN {groups} g on g.courseid = c.id
                WHERE g.id = ?";
        $course = $DB->get_records_sql($sql, array($code));
        $coursecategory = $DB->get_record('course_categories', array('id' => $course->category));
        $coursecategories = array($coursecategory->idnumber);
        // Load the parent categories too.
        $parent = $coursecategory->parent;
        while ($parent) {
            $parentcategory = $DB->get_record('course_categories', array('id' => $parent));
            $parent = $parentcategory->parent;
            $coursecategories[] = $parentcategory->idnumber;
        }
        // Determine which audience type the course belongs to based on the categories.
        foreach ($allaudiencetypes as $audiencetype) {
            $allowedcategories = array_map('trim', explode(',', $audiencetype->scope));
            if (array_intersect($allowedcategories, $coursecategories)) {
                return $audiencetype->type;
            }
        }

        return '';
    }

    /**
    * Gets the list of groups for the audience selector.
    *
    * @param array $type. The selected audience type.
    * @return array. List of audiences to display.
    */
    public static function get_selector_user_audience_associations($type) {
        global $USER, $DB;

        $audiences = array();

        // Get the audience type.
        $audiencetype = get_audiencetype($type);

        // Get all courses.
        $allcourses = get_courses();
        // Extract the idnumber of the courses.
        $courseidnumbers = array_column($allcourses, 'idnumber');
        // Run array filter to remove blank idnumbers.
        $courseidnumbers = array_filter($courseidnumbers);
        // If nothing to load return now.
        if (empty($courseidnumbers)) {
            return array();
        }
        // Load the courses.
        list($insql, $inparams) = $DB->get_in_or_equal($courseidnumbers);
        $sql = "SELECT * FROM {course} WHERE idnumber $insql";
        $courses = $DB->get_records_sql($sql, $inparams);

        // Process courses and pull out groups user can post to.
        foreach ($courses as $course) {
            // Course is not visible.
            if (!$course->visible) {
                continue;
            }
            // Course has an enddate in the past.
            if ($course->enddate && time() > $course->enddate) {
                continue;
            }
            // If we are matching against a scope then the course must have a category.
            if ($audiencetype->scope && !$course->category) {
                continue;
            }

            if ($audiencetype->scope) {
                // Only include courses with the specific category for this audience type.
                $coursecategory = $DB->get_record('course_categories', array('id' => $course->category));
                $allowedcategories = array_map('trim', explode(',', $audiencetype->scope));
                if (!in_array($coursecategory->idnumber, $allowedcategories)) {
                    // Check if a parent category matches.
                    $parent = $coursecategory->parent;
                    $found = 0;
                    while ($parent && !$found) {
                        $parentcategory = $DB->get_record('course_categories', array('id' => $parent));
                        $parent = $parentcategory->parent;
                        if (in_array($parentcategory->idnumber, $allowedcategories)) {
                            $found = true;
                        }
                    }
                    if (!$found) {
                        continue;
                    }
                }
            }

            // Get the groups in this course.
            $groups = $DB->get_records('groups', array('courseid' => $course->id), 'name');
            foreach ($groups as $group) {
                // Check if user is allowed to post to this group.
                if (!static::can_user_post_to_audience($type, $group->id)) {
                    continue;
                }

                // Exclude codes. Format: <courseidnumber>:<groupidnumber>
                if ($audiencetype->excludecodes) {
                    $excludecodes = explode(',', $audiencetype->excludecodes);
                    if (in_array($course->idnumber . ':' . $group->idnumber, $excludecodes)) {
                        continue;
                    }
                }

                $audienceout = array(
                    'code' => $group->id,
                    'groupbyname' => $course->fullname,
                    'groupbykey' => $course->idnumber,
                    'name' => $group->name,
                    'groupitemname' => $group->name,
                );
                if (!$audiencetype->grouped) {
                    $audienceout['name'] = $course->fullname . ' (' . $group->name . ')';
                    $audienceout['id'] = $group->id;
                }
                $audiences[] = $audienceout;
            }
        }

        if ($audiencetype->grouped) {
            $audiences = parent::list_to_tree($audiences);
        }

        // Sort audiences alphabetically.
        usort($audiences, function ($a, $b) {
            return $a['groupbyname'] <=> $b['groupbyname'];
        });

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
                    case "coursecapability":
                        if (privileges::check_coursecapability($checkvalue)) {
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
    * Implementation. Checks whether current user can post to a specific course
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
        global $DB;
        switch ($checktype) {
            case "usercapability":
                if(privileges::check_usercapability($checkvalue)) { 
                    return true;
                }
                break;
            case "coursecapability":
                $courseid = $DB->get_field('groups', 'courseid', array('id' => $code));
                $courseidnumber = $DB->get_field('course', 'idnumber', array('id' => $courseid));
                if ($courseidnumber && privileges::check_coursecapability($checkvalue, $courseidnumber)) {
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

        $name = $DB->get_field('groups', 'name', array('id' => $code));

        return $name;
    }

    /**
    * Implementation. Gets the audience url by code.
    *
    * @param string $code. The audience code.
    * @return string. The url of the audience.
    */
    public static function get_audience_url($code) {
        global $DB;

        $id = $DB->get_field('groups', 'courseid', array('id' => $code));
        $url = new \moodle_url('/course/view.php', array('id' => $id));

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

        $usernames = array();

        // Get the course based on the code (groupid).
        $courseid = $DB->get_field('groups', 'courseid', array('id' => $code));
        if ( !$courseid ) {
            return;
        }

        // Get the role assignments for the group members.
        $sql = "SELECT u.id
                  FROM {user} u, {groups} g, {groups_members} gm
                 WHERE u.id = gm.userid 
                   AND gm.groupid = g.id
                   AND g.id = ?
              ORDER BY lastname ASC";
        $members = $DB->get_records_sql($sql, array($code));
        $members = array_keys($members);
        $coursecontext = context_course::instance($courseid);
        $courseuserroles = array();
        $records = $DB->get_recordset('role_assignments', array('contextid' => $coursecontext->id));
        foreach ($records as $record) {
            if (in_array($record->userid, $members)) {
                if (isset($courseuserroles[$record->userid]) === false) {
                    $courseuserroles[$record->userid] = array();
                }
                $courseuserroles[$record->userid][$record->roleid] = $record;
            }
        }
        $records->close();

        $roles = array_flip($roles);

        // Look at mentor relationships.
        if (array_key_exists('Mentors', $roles)) {
            $mentorrole = $DB->get_record('role', array('shortname' => 'parent'));
            if (!empty($mentorrole)) {
                foreach ($courseuserroles as $userid => $userroles) {
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

        // Process enrolments.
        foreach ($courseuserroles as $userid => $userroles) {
            foreach ($userroles as $userrole) {
                $audiencerole = '';
                $rolename = $DB->get_record('role_names', array('roleid'=>$userrole->roleid, 'contextid'=>$coursecontext->id));
                if ($rolename) {
                    // Role has been renamed, use that instead.
                    $audiencerole = static::transform_role($rolename->name);
                } 
                if (empty($audiencerole)) {
                    $role = $DB->get_record('role', array('id'=>$userrole->roleid));
                    $audiencerole = static::transform_role($role->shortname);
                }

                if ($audiencerole == 'parents' && array_key_exists('Mentors', $roles)) {
                    $usernames[] = $DB->get_field('user', 'username', array('id'=>$userid));
                    unset($courseuserroles[$userid]);
                }

                if ($audiencerole == 'students' && array_key_exists('Students', $roles)) {
                    $usernames[] = $DB->get_field('user', 'username', array('id'=>$userid));
                    unset($courseuserroles[$userid]);
                }

                if ($audiencerole == 'staff' && array_key_exists('Staff', $roles)) {
                    $usernames[] = $DB->get_field('user', 'username', array('id'=>$userid));
                    unset($courseuserroles[$userid]);
                }
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
