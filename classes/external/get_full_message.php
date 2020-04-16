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
 * Provides {@link local_announcements\external\get_full_message} trait.
 *
 * @package   local_announcements
 * @category  external
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

namespace local_announcements\external;

defined('MOODLE_INTERNAL') || die();

use local_announcements\persistents\announcement;
use local_announcements\external\announcement_exporter;
use external_function_parameters;
use external_value;
use invalid_parameter_exception;
use external_single_structure;

require_once($CFG->libdir.'/externallib.php');

/**
 * Trait implementing the external function local_announcements_get_full_message.
 */
trait get_full_message {

    /**
     * Describes the structure of parameters for the function.
     *
     * @return external_function_parameters
     */
    public static function get_full_message_parameters() {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'The announcement id')
        ]);
    }

    /**
     * Gets a list of announcement users
     *
     * @param int $id Announement id
     */
    public static function get_full_message($id) {
        global $USER, $PAGE;

        $context = \context_user::instance($USER->id);
        self::validate_context($context);
        //Parameters validation
        self::validate_parameters(self::get_full_message_parameters(), compact('id'));

        $fullmessage = null;

        // Check if user can view the post.
        if (announcement::can_user_view_post($id)) {
            // Load the announcement.
            $announcements = announcement::get_by_ids_and_username([$id], $USER->username, is_user_auditor(), false);
            $post = array_pop($announcements);

            // Export the announcement.
            $exporter = new announcement_exporter($post->persistent, array(
                'context' => \context_system::instance(),
                'audiences' => array(), // Audiences are irrelevant.
            ));
            $output = $PAGE->get_renderer('core');
            $post = $exporter->export($output);
            $fullmessage = $post->message;
        }

        return array(
            'fullmessage' => $fullmessage
        );
    }

    /**
     * Describes the structure of the function return value.
     *
     * @return external_single_structure
     */
    public static function get_full_message_returns() {
        return new external_single_structure(
            array(
                'fullmessage' => new external_value(PARAM_RAW, 'Announcement full message'),
            )
        );
    }
}