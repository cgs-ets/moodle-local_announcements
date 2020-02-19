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
 * Provides {@link local_announcements\external\audience_exporter} class.
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_announcements\external;

defined('MOODLE_INTERNAL') || die();

use core\external\exporter;
use renderer_base;
use local_announcements\providers\audience_loader;

require_once($CFG->dirroot . '/local/announcements/lib.php');

/**
 * Exporter of a single period
 */
class audience_exporter extends exporter {

    /**
	 * Return the list of properties.
	 *
	 * @return array
	 */
	protected static function define_properties() {
	    return [
	        'postsaudiencesid' => [
	            'type' => PARAM_INT
	        ],
	        'roles' => [
	            'type' => PARAM_RAW
	        ],
	        'conditiontype' => [
	            'type' => PARAM_RAW
	        ],
	    ];
	}

	/**
     * Returns a list of objects that are related.
     *
     * We need the context to be used when formatting the message field.
     *
     * @return array
     */
    protected static function define_related() {
        return [
            'iscreator' => 'int',
        ];
    }

     /**
	 * Return the list of additional properties.
	 * @return array
	 */
	protected static function define_other_properties() {
	    return [
	        'name' => [
	            'type' => PARAM_RAW,
	        ],
	        'url' => [
	            'type' => PARAM_RAW,
	        ],
	    ];
	}

	/**
	 * Get the additional values to inject while exporting.
	 *
	 * @param renderer_base $output The renderer.
	 * @return array Keys are the property names, values are their values.
	 */
	protected function get_other_values(renderer_base $output) {
		global $DB;

		//Get the audience type
		$audiencetype = get_audiencetype($this->data->type);
		// Load the name from the provider
        $providers = audience_loader::get();
        if (!empty($providers[$audiencetype->provider])) {
        	$name = $providers[$audiencetype->provider]::get_audience_name($this->data->code);
        	if ($this->related['iscreator']) {
        		if ($this->data->roles) {
        			$name .= ' (' . $this->data->roles . ')';
        		}
        	}
        	$url = $providers[$audiencetype->provider]::get_audience_url($this->data->code);
        }

	    return [
	        'name' => $name,
	        'url' => $url,
	    ];
	}


}