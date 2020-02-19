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
 * Provides {@link local_announcements\external\mod_reject} trait.
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

require_once($CFG->libdir.'/externallib.php');

/**
 * Trait implementing the external function local_announcements_mod_reject.
 */
trait mod_reject {

    /**
     * Describes the structure of parameters for the function.
     *
     * @return external_function_parameters
     */
    public static function mod_reject_parameters() {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'ID of the announcement'),
            'comment' => new external_value(PARAM_RAW, 'Comment')
        ]);
    }

    /**
     * Soft delete the announcement
     *
     * @param int $id Id of the announcement
     */
    public static function mod_reject($id, $comment) {
        self::validate_parameters(self::mod_reject_parameters(), compact('id', 'comment'));
        $rejected = moderation::mod_reject($id, $comment);
        if (!$rejected) {
            throw new invalid_parameter_exception('Unable to moderate announcement.');
        }
        return $rejected;
    }

    /**
     * Describes the structure of the function return value.
     *
     * @return external_single_structure
     */
    public static function mod_reject_returns() {
         return new external_value(PARAM_INT, 'ID of the rejected announcement');
    }
}