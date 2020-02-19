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
 * Provides {@link local_announcements\external\get_moderation_for_audiences} trait.
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
use \local_announcements\providers\moderation;
use external_function_parameters;
use external_value;
use invalid_parameter_exception;
use external_single_structure;

require_once($CFG->libdir.'/externallib.php');

/**
 * Trait implementing the external function local_announcements_get_moderation_for_audiences.
 */
trait get_moderation_for_audiences {

    /**
     * Describes the structure of parameters for the function.
     *
     * @return external_function_parameters
     */
    public static function get_moderation_for_audiences_parameters() {
        return new external_function_parameters([
            'audiencesjson' => new external_value(PARAM_RAW, 'Audiences JSON')
        ]);
    }

    /**
     * Get moderation for selected audiences.
     *
     * @param string $audiencesjson.
     */
    public static function get_moderation_for_audiences($audiencesjson) {
        self::validate_parameters(self::get_moderation_for_audiences_parameters(), compact('audiencesjson'));
        // Validate the audiences first.
        $tags = json_decode($audiencesjson);
        if (!announcement::is_audiences_valid($tags)) {
            return array();
        }
        // Only return values needed.
        $default = array(
            'required' => false,
            'modusername' => '',
            'modthreshold' => -1,
            'description' => '',
            'autoapprove' => false,
        );
        $moderation = moderation::get_moderation_for_audiences($tags);
        $moderation = array_merge($default, array_intersect_key($moderation, $default));
        $moderation['status'] = '';
        if ($moderation['autoapprove']) {
            $moderation['required'] = false;
        }
        if ($moderation['required'] && !$moderation['autoapprove']) {
            $user = \core_user::get_user_by_username($moderation['modusername']);
            $moderation['status'] = 'This announcement will be sent to ' . fullname($user) . ' for moderation.';
        }
        return $moderation;
    }

    /**
     * Describes the structure of the function return value.
     *
     * @return external_single_structure
     */
    public static function get_moderation_for_audiences_returns() {
        return new external_single_structure(
            array(
                'required' => new external_value(PARAM_BOOL, 'Moderation required'),
                'modusername' => new external_value(PARAM_RAW, 'Moderation username'),
                'modthreshold' => new external_value(PARAM_INT, 'Moderation threshold'),
                'status' => new external_value(PARAM_RAW, 'Status description'),
                'description' => new external_value(PARAM_RAW, 'Rule description'),
            )
        );
    }
}