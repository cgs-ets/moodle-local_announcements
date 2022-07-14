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
 * Course audience type
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
use core_course_category;

class audience_mdlcourse extends \local_announcements\providers\audience_provider {

    const PROVIDER = 'mdlcourse';

    /**
    * Implementation. Returns an array including the code and any meta course idnumbers.
    *
    * @param string $code
    * @return array Array of audience params
    */
    public static function get_related_audience_codes($code) {
        global $DB;

        // Get the course by the code (idnumber).
        $course = $DB->get_record('course', array('idnumber' => $code));
        if (empty($course)) {
            return [];
        }

        // Add the code itself.
        $params[] = array('provider' => 'mdlcourse', 'code' => $code);

        // Get any meta courses that this course is a child of.
        $enrols = $DB->get_records('enrol', array('customint1' => $course->id));
        if (!empty($enrols)) {
            foreach ($enrols as $enrol) {
                $metacourse = $DB->get_record('course', array('id' => $enrol->courseid));
                if (!empty($metacourse->idnumber)) {
                    $params[] = array('provider' => 'mdlcourse', 'code' => $metacourse->idnumber);
                }
            }
        }

        // Get groups in this course.
        $groups = $DB->get_records('groups', array('courseid' => $course->id), 'id');
        if (!empty($groups)) {
            foreach ($groups as $group) {
                $params[] = array('provider' => 'mdlgroup', 'code' => $group->id);
            }
        }

        // Get relevant combo codes based on relateds. E.g. year,mdlcourse|Year-4-2020|Year 4,Students.
        $combosql = "SELECT code
                       FROM {ann_posts_audiences_cond}
                      WHERE 0 = 1";
        $comboparams = array();
        foreach ($params as $related) {
            $combocode = "%" . $related['provider'] . "|" . $related['code'] . "%";
            $combosql .= " OR (code LIKE ?)";
            $comboparams[] = $combocode;
        }

        //Debug sql
        //echo "<pre>";
        //foreach($comboparams as $replace){$combosql = preg_replace('/\?/i', '`'.$replace.'`', $combosql, 1);}
        //$combosql = preg_replace('/\{/i', 'mdl_', $combosql);$combosql = preg_replace('/\}/i', '', $combosql);
        //var_export($combosql);
        //exit;

        $records = $DB->get_records_sql($combosql, $comboparams);
        foreach ($records as $record) {
            $params[] = array('provider' => 'combination', 'code' => $record->code);
        }

        return $params;
    }

    /**
    * Convert moodle course roles to announcement role codes.
    *
    * @param string $username.
    * @return string role.
    */
    public static function transform_role($role) {
        $role = strtolower($role);
        switch($role){
            case "manager":        $code = "staff";break;
            case "coursecreator":  $code = "staff";break;
            case "editingteacher": $code = "staff";break;
            case "teacher":        $code = "staff";break;
            case "student":        $code = "students";break;
            case "parent":         $code = "parents";break;
            default:               $code = "";
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
    * Gets the audience type of the given course
    *
    * @param array $courseid. 
    * @return string.
    */
    public static function get_audience_type($courseid) {
        global $DB;

        // Get all the audience types from the mdlcourses provider.
        $allaudiencetypes = static::get_audience_types();
        if (count($allaudiencetypes) == 1) {
            $aud = array_pop($allaudiencetypes);
            return $aud->type;
        }
        // Load up the course category.
        $course = $DB->get_record('course', array('id' => $courseid));
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

        // Return course by default.
        return 'course';

    }

    /**
    * Gets the list of courses for the audience selector. 
    * If an course does not appear in the list check that
    * the category is in scope, course is available, and has an idnumber.
    * 
    *
    * @param array $type. The selected audience type.
    * @return array. List of audiences to display.
    */
    public static function get_selector_user_audience_associations($type) {
        global $DB;

        $audiences = array();

        // Get the audience type.
        $audiencetype = get_audiencetype($type);

        // Get courses by category, or all if no scope.
        $courseidnumbers = array();
        if ($audiencetype->scope) {
            $allowedcategories = array_map('trim', explode(',', $audiencetype->scope));
            // Get the category ids based on the idnumber.
            list($insql, $inparams) = $DB->get_in_or_equal($allowedcategories);
            $sql = "SELECT id FROM {course_categories} WHERE idnumber $insql";
            $catids = array_keys($DB->get_records_sql($sql, $inparams));
            if (empty($catids)) {
                return [];
            }
            // Get child categories of allowed categories.
            list($insql, $inparams) = $DB->get_in_or_equal($catids);
            $sql = "SELECT id FROM {course_categories} WHERE parent $insql";
            $childcatids = array_keys($DB->get_records_sql($sql, $inparams));
            $catids = array_merge($catids, $childcatids);
            // Get the course idnumbers based on cat ids.
            list($insql, $inparams) = $DB->get_in_or_equal($catids);
            $sql = "SELECT id, idnumber FROM {course} WHERE category $insql";
            $courseidnumbers = array_unique(array_column($DB->get_records_sql($sql, $inparams), 'idnumber'));
        } else {
            $allcourses = get_courses();
            // Extract the idnumber of the courses.
            $courseidnumbers = array_column($allcourses, 'idnumber');
        }

        // Run array filter to remove blank idnumbers.
        $courseidnumbers = array_filter($courseidnumbers);
        // Extract only the courses that user can post to.
        $courseidnumbers = static::filter_courses_by_post_privileges($type, $courseidnumbers);

        if (empty($courseidnumbers)) {
            return array();
        }

        // Exclude codes.
        if ($audiencetype->excludecodes) {
            $excludecodes = explode(',', $audiencetype->excludecodes);
            $courseidnumbers = array_diff($courseidnumbers, $excludecodes);
        }

        // Load the courses.
        list($insql, $inparams) = $DB->get_in_or_equal($courseidnumbers);
        $sql = "SELECT * FROM {course} WHERE idnumber $insql";
        $courses = $DB->get_records_sql($sql, $inparams);

        // Process courses.
        foreach ($courses as $course) {
            // Course is not visible.
            if (!$course->visible) {
                continue;
            }
            // Course has an enddate in the past.
            if ($course->enddate && time() > $course->enddate) {
                continue;
            } 
            $audiences[] = array(
                'id' => $course->id,
                'code' => $course->idnumber,
                'name' => $course->fullname,
            );         
        }

        // Sort audiences alphabetically.
        usort($audiences, function ($a, $b) {
            return $a['name'] <=> $b['name'];
        });

        return $audiences;
    }

    /**
    * Implementation. Checks whether current user can post to the audience type.
    * It checks against records in ann_privileges table.
    * True is returned as soon as a valid match is found.
    *
    * @param array $type. The selected audience type.
    * @return boolean.
    */
    public static function can_user_post_to_audiencetype($type) {
        global $DB, $USER;

        // Announcement admins can always post.
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
                    case "enrol":
                        //get user's roles in course (editingteacher, teacher, etc) and compare with $checkvalue
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
    * Checks whether current user can post to a list of courses
    *
    * @param array $type. The selected audience type.
    * @param array $codes. Array of courses
    * @return array. Filtered array of courses user can post to.
    */
    public static function filter_courses_by_post_privileges($type, $codes) {
        global $DB, $USER;

        if (empty($codes)) {
            return [];
        }

        // Announcement admins can always post.
        if (is_user_admin()) { 
            return $codes;
        }

        $checks = privileges::get_checks($type, false, $codes);
        $filteredcodes = array();
        foreach ($codes as $code) {
            foreach ($checks as $regcode => $checktypes) {
                // Convert privilege code to a regex.
                $regcode = $regcode == '*' ? '(.*)' : str_replace('%', '(.*)', $regcode);
                // Perform the checks if the audience code matches the privilege code.
                if (preg_match('/^' . $regcode . '$/i', $code) !== 1) {
                    continue;
                }
                foreach ($checktypes as $checktype => $checkvalues) {
                    foreach ($checkvalues as $checkvalue) {
                        switch ($checktype) {
                            case "usercapability":
                                if(privileges::check_usercapability($checkvalue)) {
                                    $filteredcodes[] = $code;
                                }
                                break;
                            case "coursecapability":
                                if (privileges::check_coursecapability($checkvalue, $code)) {
                                    $filteredcodes[] = $code;
                                }
                                break;
                            case "username":
                                if (privileges::check_username($checkvalue)) {
                                    $filteredcodes[] = $code;
                                }
                                break;
                            case "profilefield":
                                if (privileges::check_profilefield($checkvalue)) {
                                    $filteredcodes[] = $code;
                                }
                                break;
                        }
                    }
                }
            }
        }
        return $filteredcodes;
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
            case "coursecapability":
                if (privileges::check_coursecapability($checkvalue, $code)) {
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

        $name = $DB->get_field('course', 'shortname', array('idnumber' => $code));

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

        $id = $DB->get_field('course', 'id', array('idnumber' => $code));
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

        $usernames = [];

        // If roles is empty then abort.
        if (empty($roles)) {
            return;
        }

        // Load the courseid.
        $courseid = $DB->get_field('course', 'id', array('idnumber' => $code));
        if ( !$courseid ) {
            return;
        }

        $coursecontext = context_course::instance($courseid);
        $courseuserroles = enrol_get_course_users_roles($courseid);

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
