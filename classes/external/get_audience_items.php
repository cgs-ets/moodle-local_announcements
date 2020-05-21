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
 * Provides {@link local_announcements\external\get_audience_items} trait.
 *
 * @package   local_announcements
 * @category  external
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

namespace local_announcements\external;

defined('MOODLE_INTERNAL') || die();

use context_user;
use external_function_parameters;
use external_value;
use external_multiple_structure;
use external_single_structure;
use local_announcements\providers\audience_loader;

require_once($CFG->libdir.'/externallib.php');
require_once($CFG->dirroot . '/local/announcements/lib.php');
require_once($CFG->dirroot . '/local/announcements/locallib.php');

/**
 * Trait implementing the external function local_announcements_get_audience_items.
 */
trait get_audience_items {

    /**
     * Describes the structure of parameters for the function.
     *
     * @return external_function_parameters
     */
    public static function get_audience_items_parameters() {
        return new external_function_parameters([
            'type' => new external_value(PARAM_ALPHANUMEXT, 'The selected audience type')
        ]);
    }

    /**
     * Gets a list of user's audiences
     *
     * @param string $todotext Item text
     */
    public static function get_audience_items($type) {
        global $DB;

        self::validate_parameters(self::get_audience_items_parameters(), compact('type'));

        //Get the audience type
        $audiencetype = get_audiencetype($type);

        // Get the list of items from the provider
        $audienceproviders = audience_loader::get();

        $out = array(
            'audiencelist' => array(),
            'audiencelistgrouped' => array(),
            'type' => $type,
            'typenamesingular' => $audiencetype->namesingular,
            'typenameplural' => $audiencetype->nameplural,
            'grouped' => $audiencetype->grouped,
        ); 

        $list = $audienceproviders[$audiencetype->provider]::get_selector_user_audience_associations($type);
        if ($audiencetype->grouped) {
            $out['audiencelistgrouped'] = $list;
        } else {
            $out['audiencelist'] = $list;
        }

        return $out;

    }

    /**
     * Describes the structure of the function return value.
     *
     * @return external_single_structure
     */
    public static function get_audience_items_returns() {
        return new external_single_structure(
            array (
                'audiencelist' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'id' => new external_value(PARAM_RAW, 'Audience ID number'),
                            'code' => new external_value(PARAM_RAW, 'Audience code'),
                            'name' => new external_value(PARAM_RAW, 'Audience name'),
                        )
                    )
                ),
                'audiencelistgrouped' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'groupbykey' => new external_value(PARAM_RAW, 'Group key'),
                            'groupbyname' => new external_value(PARAM_RAW, 'Group readable name'),
                            'items' => new external_multiple_structure(
                                new external_single_structure(
                                    array(
                                        'code' => new external_value(PARAM_RAW, 'Audience code'),
                                        'name' => new external_value(PARAM_RAW, 'Audience readable name'),
                                        'groupitemname' => new external_value(PARAM_RAW, 'Audience readable name displayed under group'),
                                    )
                                )
                            )
                        )
                    )
                ),
                'type' => new external_value(PARAM_RAW, 'Type code of audience'),
                'typenamesingular' => new external_value(PARAM_RAW, 'Singular name of audience type'),
                'typenameplural' => new external_value(PARAM_RAW, 'Plural name of audience type'),
                'grouped' => new external_value(PARAM_INT, 'Whether the audiences are grouped or not')
            )
        );
    }
}