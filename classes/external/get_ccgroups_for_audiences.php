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
 * Provides {@link local_announcements\external\get_ccgroups_for_audiences} trait.
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
use external_function_parameters;
use external_value;
use invalid_parameter_exception;
use external_multiple_structure;

require_once($CFG->libdir.'/externallib.php');

/**
 * Trait implementing the external function local_announcements_get_ccgroups_for_audiences.
 */
trait get_ccgroups_for_audiences {

    /**
     * Describes the structure of parameters for the function.
     *
     * @return external_function_parameters
     */
    public static function get_ccgroups_for_audiences_parameters() {
        return new external_function_parameters([
            'audiencesjson' => new external_value(PARAM_RAW, 'Audiences JSON')
        ]);
    }

    /**
     * Get ccgroups for selected audiences.
     *
     * @param string $audiencesjson.
     */
    public static function get_ccgroups_for_audiences($audiencesjson) {
        self::validate_parameters(self::get_ccgroups_for_audiences_parameters(), compact('audiencesjson'));
        // Validate the audiences first.
        $tags = json_decode($audiencesjson);
        if (!announcement::is_audiences_valid($tags)) {
            return array();
        }
        return announcement::get_audience_ccgroups($tags);
    }

    /**
     * Describes the structure of the function return value.
     *
     * @return external_multiple_structure
     */
    public static function get_ccgroups_for_audiences_returns() {
        return new external_multiple_structure(
            new external_value(PARAM_RAW, 'ccgroup description')
        );
    }
}