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
 * Singleton class defining available audience types
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_announcements\providers;

defined('MOODLE_INTERNAL') || die();


final class audience_loader {

    private $audiencefiles = '/local/announcements/classes/providers/audiences/*.php';
    private $audiencenamespace = 'local_announcements\\providers\\audiences\\';
	private $audiences = [];

     /**
     * Call this method to get singleton
     *
     * @return audience_types
     */
    public static function get()
    {
        static $inst = null;
        if ($inst === null) {
            $inst = new audience_loader();
        }
        return $inst->audiences;
    }

    /**
     * Private constructor so nobody else can instantiate it
     *
     */
    private function __construct()
    {
        global $CFG;
       
        // find available audience providers
        foreach (glob($CFG->dirroot.$this->audiencefiles) as $file) {
            // get the file name of the current file without the extension which is essentially the class name
            $classname = basename($file, '.php');
            $fqcn = $this->audiencenamespace.$classname;
            if (class_exists($fqcn))
            {
                $this->audiences[$fqcn::get_provider()] = $fqcn;
            }
        }
   }

}
