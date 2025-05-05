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
 * Defines message providers (types of messages being sent)
 *
 * @package   local_announcements
 * @category  external
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


$messageproviders = array (
    // Ordinary single notifications.
    'notifications' => array(
        'defaults' => array(
            'popup' => MESSAGE_PERMITTED, // On by default
            'email' => MESSAGE_PERMITTED, // Permitted but off by default
            'airnotifier' => MESSAGE_DISALLOWED,
        ),
    ),

    // Notification to mobile. Can't use other notifications for mobile as payload too large.
    'notificationsmobile' => array(
        'defaults' => array(
            'popup' => MESSAGE_DISALLOWED,
            'email' => MESSAGE_DISALLOWED,
            'airnotifier' => MESSAGE_PERMITTED,
        ),
    ),

    // Forced single notifications.
    'forced' => array(
        'defaults' => array(
            'popup' => MESSAGE_FORCED,
            'email' => MESSAGE_FORCED,
            'airnotifier' => MESSAGE_DISALLOWED,
        ),
    ),

    // Forced single notifications for mobile. Can't use other notifications for mobile as payload too large.
    'forcedmobile' => array(
        'defaults' => array(
            'popup' => MESSAGE_DISALLOWED,
            'email' => MESSAGE_DISALLOWED,
            'airnotifier' => MESSAGE_FORCED,
        ),
    ),


    // digest messages.
    'digests' => array(
    	'defaults' => array(
            'popup' => MESSAGE_DISALLOWED,
            'email' => MESSAGE_PERMITTED,
            'airnotifier' => MESSAGE_DISALLOWED,
        ),
    ),

    // moderation messages.
    'moderationmail' => array(
        'defaults' => array(
            'popup' => MESSAGE_FORCED,
            'email' => MESSAGE_FORCED,
            'airnotifier' => MESSAGE_FORCED,
        ),
    ),
);
