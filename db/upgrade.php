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
 * Post installation and migration code.
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_local_announcements_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2020021201) {

        /**
         * xmldb_field 
         * @param string $name of field
         * @param int $type XMLDB_TYPE_INTEGER, XMLDB_TYPE_NUMBER, XMLDB_TYPE_CHAR, XMLDB_TYPE_TEXT, XMLDB_TYPE_BINARY
         * @param string $precision length for integers and chars, two-comma separated numbers for numbers
         * @param bool $unsigned XMLDB_UNSIGNED or null (or false)
         * @param bool $notnull XMLDB_NOTNULL or null (or false)
         * @param bool $sequence XMLDB_SEQUENCE or null (or false)
         * @param mixed $default meaningful default o null (or false)
         * @param xmldb_object $previous
         */
        $id = new xmldb_field('id', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $audiencetype = new xmldb_field('audiencetype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null, null, 'id');
        $code = new xmldb_field('code', XMLDB_TYPE_CHAR, '200', null, XMLDB_NOTNULL, null, '*', null, 'audiencetype');
        $role = new xmldb_field('role', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, '*', null, 'code');
        $forcesend = new xmldb_field('forcesend', XMLDB_TYPE_CHAR, '1', null, XMLDB_NOTNULL, '*', null, null, 'role');
        $description = new xmldb_field('description', XMLDB_TYPE_CHAR, '500', null, XMLDB_NOTNULL, null, null, null, 'forcesend');
        $ccgroupid = new xmldb_field('ccgroupid', XMLDB_TYPE_CHAR, '200', null, XMLDB_NOTNULL, null, null, null, 'description');
        $primarykey = new xmldb_key('primary', XMLDB_KEY_PRIMARY, array('id'), null, null);

        $table = new xmldb_table('ann_audience_ccgroups');
        $table->addField($id);
        $table->addField($audiencetype);
        $table->addField($code);
        $table->addField($role);
        $table->addField($forcesend);
        $table->addField($description);
        $table->addField($ccgroupid);
        $table->addKey($primarykey);

        // Add a new table for audience cc's.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

    }

    return true;

}
