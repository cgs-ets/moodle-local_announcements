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
 * Provides {@link local_announcements\external\list_exporter} class.
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_announcements\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/announcements/locallib.php');
use renderer_base;
use core\external\exporter;

/**
 * Exports the list of announcements
 */
class list_exporter extends exporter {

    /**
    * Return the list of additional properties.
    *
    * Calculated values or properties generated on the fly based on standard properties and related data.
    *
    * @return array
    */
    protected static function define_other_properties() {
        return [
            'announcements' => [
                'type' => announcement_exporter::read_properties_definition(),
                'multiple' => true,
                'optional' => false,
            ],
            'possiblemore' => [
                'type' => PARAM_BOOL,
                'multiple' => false,
                'optional' => false,
            ],
            'nextpagelink'=> [
                'type' => PARAM_RAW,
                'multiple' => false,
                'optional' => false,
            ],
        ];
    }

    /**
    * Returns a list of objects that are related.
    *
    * Data needed to generate "other" properties.
    *
    * @return array
    */
    protected static function define_related() {
        return [
            'context' => 'context',
            'announcements' => 'stdClass[]',
            'page' => 'int',
        ];
    }

    /**
     * Get the additional values to inject while exporting.
     *
     * @param renderer_base $output The renderer.
     * @return array Keys are the property names, values are their values.
     */
    protected function get_other_values(renderer_base $output) {
        global $PAGE;

        $announcements = [];
        // Export each announcement in the list
        foreach ($this->related['announcements'] as $announcement) {
            $announcementexporter = new announcement_exporter($announcement->persistent, [
                'context' => $this->related['context'],
                'audiences' => $announcement->audiences,
            ]);
            $announcements[] = $announcementexporter->export($output);
        }

        //$possiblemore = ( count($announcements) >= get_per_page() );
        // Keeping it simple, there is always possibly more.
        $possiblemore = true;

        // Pagination.
        // Arbitrarily large total count. To minimise load time, we do not attempt to figure out how many announcements this user actually has, given the complexity of the sql to determine audiences number of announcement. Instead we produce a large number of pagination links that don't actually have any posts, but the infinite scroll is smart enough to continue loading the next set of announcements until there is nothing left to load. 
        $totalcount = 999999999; 
        $perpage = get_per_page();
        $pagingbar = new \paging_bar($totalcount, $this->related['page'], $perpage, 'index.php', 'page');
        $pagingbar->prepare($output, $PAGE, '');
        $nextpagelink = $pagingbar->nextlink;

        return [
            'announcements' => $announcements,
            'possiblemore' => $possiblemore,
            'nextpagelink' => $nextpagelink,
        ];
    }


}