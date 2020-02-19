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
 * Provides {@link local_announcements\external\get_alternate_moderators} trait.
 *
 * @package   local_announcements
 * @category  external
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

namespace local_announcements\external;

defined('MOODLE_INTERNAL') || die();

use \local_announcements\providers\moderation;
use external_function_parameters;
use external_value;
use invalid_parameter_exception;
use external_multiple_structure;
use external_single_structure;

require_once($CFG->libdir.'/externallib.php');

/**
 * Trait implementing the external function local_announcements_get_alternate_moderators.
 */
trait get_alternate_moderators {

    /**
     * Describes the structure of parameters for the function.
     *
     * @return external_function_parameters
     */
    public static function get_alternate_moderators_parameters() {
        return new external_function_parameters([
            'postid' => new external_value(PARAM_INT, 'ID of the announcement')
        ]);
    }

    /**
     * Soft delete the announcement
     *
     * @param int $postid Id of the announcement
     */
    public static function get_alternate_moderators($postid) {
        self::validate_parameters(self::get_alternate_moderators_parameters(), compact('postid'));
        $moderators = array(
            'moderators' => array(), //moderation::get_alternate_moderators($postid)
        );
        return $moderators;
    }

    /**
     * Describes the structure of the function return value.
     *
     * @return external_single_structure
     */
    public static function get_alternate_moderators_returns() {
        return new external_single_structure(
            array (
                'moderators' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'username' => new external_value(PARAM_RAW, 'The moderator\'s username'),
                            'fullname' => new external_value(PARAM_RAW, 'The moderator\'s full name'),
                        )
                    )
                )
            )
        );
    }
}