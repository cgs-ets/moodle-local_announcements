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
 * Plugin external functions and services are defined here.
 *
 * @package   local_announcements
 * @category    external
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_announcements_get_audience_items' => [
        'classname'     => 'local_announcements\external\api',
        'methodname'    => 'get_audience_items',
        'classpath'     => '',
        'description'   => 'Gets a list of user\'s audience associations',
        'type'          => 'read',
        'loginrequired' => true,
        'ajax'          => true,
    ],
    'local_announcements_delete_announcement' => [
        'classname'     => 'local_announcements\external\api',
        'methodname'    => 'delete_announcement',
        'classpath'     => '',
        'description'   => 'Delete\'s an announcement',
        'type'          => 'write',
        'loginrequired' => true,
        'ajax'          => true,
    ],
    'local_announcements_get_announcement_users' => [
        'classname'     => 'local_announcements\external\api',
        'methodname'    => 'get_announcement_users',
        'classpath'     => '',
        'description'   => 'Get\'s a list of users for an announcement',
        'type'          => 'read',
        'loginrequired' => true,
        'ajax'          => true,
    ],
    'local_announcements_get_full_message' => [
        'classname'     => 'local_announcements\external\api',
        'methodname'    => 'get_full_message',
        'classpath'     => '',
        'description'   => 'Get\'s the full message for an announcement',
        'type'          => 'read',
        'loginrequired' => true,
        'ajax'          => true,
    ],
    'local_announcements_get_alternate_moderators' => [
        'classname'     => 'local_announcements\external\api',
        'methodname'    => 'get_alternate_moderators',
        'classpath'     => '',
        'description'   => 'Get a list of alternate moderators for a post.',
        'type'          => 'read',
        'loginrequired' => true,
        'ajax'          => true,
    ],
    'local_announcements_mod_approve' => [
        'classname'     => 'local_announcements\external\api',
        'methodname'    => 'mod_approve',
        'classpath'     => '',
        'description'   => 'Approve an announcement for moderation',
        'type'          => 'write',
        'loginrequired' => true,
        'ajax'          => true,
    ],
    'local_announcements_mod_reject' => [
        'classname'     => 'local_announcements\external\api',
        'methodname'    => 'mod_reject',
        'classpath'     => '',
        'description'   => 'Reject an announcement for moderation',
        'type'          => 'write',
        'loginrequired' => true,
        'ajax'          => true,
    ],
    'local_announcements_mod_defer' => [
        'classname'     => 'local_announcements\external\api',
        'methodname'    => 'mod_defer',
        'classpath'     => '',
        'description'   => 'Reassign an announcement for moderation',
        'type'          => 'write',
        'loginrequired' => true,
        'ajax'          => true,
    ],
    'local_announcements_get_moderation_for_audiences' => [
        'classname'     => 'local_announcements\external\api',
        'methodname'    => 'get_moderation_for_audiences',
        'classpath'     => '',
        'description'   => 'Get moderation based on audiences',
        'type'          => 'read',
        'loginrequired' => true,
        'ajax'          => true,
    ],
    'local_announcements_get_ccgroups_for_audiences' => [
        'classname'     => 'local_announcements\external\api',
        'methodname'    => 'get_ccgroups_for_audiences',
        'classpath'     => '',
        'description'   => 'Get cc groups based on audiences',
        'type'          => 'read',
        'loginrequired' => true,
        'ajax'          => true,
    ],
    'local_announcements_get_audienceselector_users' => [
        'classname'     => 'local_announcements\external\api',
        'methodname'    => 'get_audienceselector_users',
        'classpath'     => '',
        'description'   => 'Get\'s a list of users for tags in the audience selector',
        'type'          => 'read',
        'loginrequired' => true,
        'ajax'          => true,
    ],
    
];