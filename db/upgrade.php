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

    if ($oldversion < 2020022500) {
        $table = new xmldb_table('ann_posts');
        $savecomplete = new xmldb_field('savecomplete', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, 0, null, 'timemodified');

        if (!$dbman->field_exists($table, $savecomplete)) {
            $dbman->add_field($table, $savecomplete);
        }
    }

    if ($oldversion < 2020031600) {
        $table = new xmldb_table('ann_posts');
        $timeedited = new xmldb_field('timeedited', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0, null, 'timecreated');

        if (!$dbman->field_exists($table, $timeedited)) {
            $dbman->add_field($table, $timeedited);
        }
    }

    if ($oldversion < 2020051900) {
        $table = new xmldb_table('ann_posts');
        $impersonate = new xmldb_field('impersonate', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null, null, 'timeedited');

        if (!$dbman->field_exists($table, $impersonate)) {
            $dbman->add_field($table, $impersonate);
        }
    }

    if ($oldversion < 2020052100) {
        $id = new xmldb_field('id', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $authorusername = new xmldb_field('authorusername', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null, null, 'id');
        $impersonateuser = new xmldb_field('impersonateuser', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null, null, 'authorusername');
        $primarykey = new xmldb_key('primary', XMLDB_KEY_PRIMARY, array('id'), null, null);

        $table = new xmldb_table('ann_impersonators');
        $table->addField($id);
        $table->addField($authorusername);
        $table->addField($impersonateuser);
        $table->addKey($primarykey);

        // Add a new table for audience cc's.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
    }

    if ($oldversion < 2020061701) {
        $table = new xmldb_table('ann_impersonators');
        $source = new xmldb_field('source', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null, null, 'impersonateuser');

        if (!$dbman->field_exists($table, $source)) {
            $dbman->add_field($table, $source);
        }
    }

    if ($oldversion < 2020092500) {
        $table = new xmldb_table('ann_posts');
        $sorttime = new xmldb_field('sorttime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0, null, 'impersonate');

        if (!$dbman->field_exists($table, $sorttime)) {
            $dbman->add_field($table, $sorttime);
        }
    }

    if ($oldversion < 2020103002) {

        // Immediately clean default notification settings and preferences.
        $sql = "update mdl_config_plugins
        set value = 'disallowed'
        where plugin = 'message'
        and name = 'airnotifier_provider_local_announcements_notifications_permitted'
        and value = 'permitted'";
        $DB->execute($sql);

        $sql = "update mdl_config_plugins
        set value = 'disallowed'
        where plugin = 'message'
        and name = 'airnotifier_provider_local_announcements_forced_permitted'
        and value = 'forced'";
        $DB->execute($sql);

        $sql = "update mdl_user_preferences 
        set value = 'popup,email'
        where name LIKE 'message_provider_local_announcements_notifications_%'
        and value = 'popup,email,airnotifier'";
        $DB->execute($sql);

        $sql = "update mdl_user_preferences 
        set value = 'popup'
        where name LIKE 'message_provider_local_announcements_notifications_%'
        and value = 'popup,airnotifier'";
        $DB->execute($sql);

        $sql = "update mdl_user_preferences 
        set value = 'email'
        where name LIKE 'message_provider_local_announcements_notifications_%'
        and value = 'email,airnotifier'";
        $DB->execute($sql);

        $sql = "update mdl_user_preferences 
        set value = 'none'
        where name LIKE 'message_provider_local_announcements_notifications_%'
        and value = 'airnotifier'";
        $DB->execute($sql);

    }

    if ($oldversion < 2021020100) {
        $id = new xmldb_field('id', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $username = new xmldb_field('username', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null, null, 'id');
        $draftaudience = new xmldb_field('draftaudience', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null, 'username');
        $primarykey = new xmldb_key('primary', XMLDB_KEY_PRIMARY, array('id'), null, null);

        $table = new xmldb_table('ann_draftaudiences');
        $table->addField($id);
        $table->addField($username);
        $table->addField($draftaudience);
        $table->addKey($primarykey);

        // Add a new table for audience cc's.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
    }

    if ($oldversion < 2021020103) {

        // Define field moderatorjson to be added to ann_posts.
        $table = new xmldb_table('ann_posts');
        $field = new xmldb_field('moderatorjson', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null, 'audiencesjson');

        // Conditionally launch add field moderatorjson.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

         // Changing precision of field modusername on table ann_privileges to (100).
         $table = new xmldb_table('ann_privileges');
         $field = new xmldb_field('modusername', XMLDB_TYPE_CHAR, '500', null, XMLDB_NOTNULL, null, null, 'modthreshold');
 
         // Launch change of precision for field modusername.
         $dbman->change_field_precision($table, $field);

        // Announcements savepoint reached.
        upgrade_plugin_savepoint(true, 2021020103, 'local', 'announcements');
    }


    return true;

}
