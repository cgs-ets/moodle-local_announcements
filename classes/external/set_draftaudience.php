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
 * Provides {@link local_announcements\external\set_draftaudience} trait.
 *
 * @package   local_announcements
 * @category  external
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

namespace local_announcements\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/announcements/locallib.php');
use external_function_parameters;
use external_value;
use invalid_parameter_exception;

require_once($CFG->libdir.'/externallib.php');

/**
 * Trait implementing the external function local_announcements_set_draftaudience.
 */
trait set_draftaudience {

    /**
     * Describes the structure of parameters for the function.
     *
     * @return external_function_parameters
     */
    public static function set_draftaudience_parameters() {
        return new external_function_parameters([
            'userids' => new external_value(PARAM_RAW, 'userids array in json')
        ]);

    }

    /**
     * Set a draftaudience for the user.
     *
     * @param int $id Id of the announcement
     */
    public static function set_draftaudience($userids) {
        self::validate_parameters(self::set_draftaudience_parameters(), compact('userids'));

        $audiencejson = convert_userids_to_audience(json_decode($userids));

        return set_draftaudience($audiencejson);
    }

    /**
     * Describes the structure of the function return value.
     *
     * @return external_single_structure
     */
    public static function set_draftaudience_returns() {
         return new external_value(PARAM_INT, 'ID of the new draftaudience.');
    }
}