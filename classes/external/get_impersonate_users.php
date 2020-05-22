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
 * Provides {@link local_announcements\external\get_impersonate_users} trait.
 *
 * @package   local_announcements
 * @category  external
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

namespace local_announcements\external;

defined('MOODLE_INTERNAL') || die();

use \local_announcements\persistents\announcement;
use context_user;
use external_function_parameters;
use external_value;
use invalid_parameter_exception;
use external_multiple_structure;
use external_single_structure;

require_once($CFG->libdir.'/externallib.php');

/**
 * Trait implementing the external function local_announcements_get_impersonate_users.
 */
trait get_impersonate_users {

    /**
     * Describes the structure of parameters for the function.
     *
     * @return external_function_parameters
     */
    public static function get_impersonate_users_parameters() {
        return new external_function_parameters([
            'query' => new external_value(PARAM_RAW, 'The search query')
        ]);
    }

    /**
     * Gets a list of announcement users
     *
     * @param int $query The search query
     */
    public static function get_impersonate_users($query) {
        self::validate_parameters(self::get_impersonate_users_parameters(), compact('query'));
        
        $results = self::search_impersonate_users($query);

        $users = array();
        foreach ($results as $user) {
            $userphoto = new \moodle_url('/user/pix.php/'.$user->id.'/f2.jpg');
            $userurl = new \moodle_url('/user/profile.php', array('id' => $user->id));
            $users[] = array(
                'username' => $user->username,
                'fullname' => fullname($user),
                'photourl' => $userphoto->out(false),
            );
        }
        return $users;
    }

    /**
     * Describes the structure of the function return value.
     *
     * @return external_single_structure
     */
    public static function get_impersonate_users_returns() {
        return new external_multiple_structure(
            new external_single_structure(
                array(
                    'username' => new external_value(PARAM_RAW, 'The user\'s username'),
                    'fullname' => new external_value(PARAM_RAW, 'The user\'s full name'),
                    'photourl' => new external_value(PARAM_RAW, 'The user\'s photo src'),
                )
            )
        );
    }

    /*
    * Search for staff by full name.
    *
    * @param string $query. The search query.
    * @return array of user objects.
    */
    private static function search_impersonate_users($query) {
        global $DB, $USER;

        $sql = "SELECT u.*
                FROM {user} u
                INNER JOIN {user_info_data} d ON d.userid = u.id
                INNER JOIN {user_info_field} f ON d.fieldid = f.id
                WHERE f.shortname = 'CampusRoles'
                AND LOWER(d.data) LIKE ?
                AND (LOWER(u.firstname) LIKE ? OR LOWER(u.lastname) LIKE ?)";
        $params = array(
            '%staff%',
            '%'.$DB->sql_like_escape(strtolower($query)).'%',
            '%'.$DB->sql_like_escape(strtolower($query)).'%',
        );

        return $DB->get_records_sql($sql, $params, 0, 15);
    }
}