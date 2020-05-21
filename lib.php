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
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/** Include required files */
require_once($CFG->libdir.'/filelib.php');
use \local_announcements\providers\audience_loader;
use \local_announcements\persistents\announcement;
use \local_announcements\providers\moderation;


/// CONSTANTS ///////////////////////////////////////////////////////////
define('ANN_MAILED_PENDING', 0);
define('ANN_MAILED_SUCCESS', 1);
define('ANN_MAILED_ERROR', 2);

define('ANN_NOTIFIED_PENDING', 0);
define('ANN_NOTIFIED_SUCCESS', 1);
define('ANN_NOTIFIED_ERROR', 2);

define('DEFAULT_ANN_PERPAGE', 50);
define('DEFAULT_SHORTPOST', 300);

// For [mdl_ann_posts].[modrequired].
define('ANN_MOD_REQUIRED_NO', 0);
define('ANN_MOD_REQUIRED_YES', 1);
// For [ann_posts_moderation].[mailed].
define('ANN_MOD_MAIL_PENDING', 0);
define('ANN_MOD_MAIL_SENT', 1);
// For [ann_posts_moderation].[status].
define('ANN_MOD_STATUS_PENDING', 0);
define('ANN_MOD_STATUS_APPROVED', 1);
define('ANN_MOD_STATUS_REJECTED', 2);
define('ANN_MOD_STATUS_DEFERRED', 3);
define('ANN_MOD_STATUS_CODES', array('pending', 'approved', 'rejected', 'reassigned'));

/// STANDARD FUNCTIONS ///////////////////////////////////////////////////////////

/**
 * Serves the plugin attachments.
 *
 * @package  local_announcements
 * @category files
 * @param stdClass $course course object
 * @param stdClass $cm course module object
 * @param stdClass $context context object
 * @param string $filearea file area
 * @param array $args extra arguments
 * @param bool $forcedownload whether or not force download
 * @param array $options additional options affecting the file serving
 * @return bool false if file not found, does not return if found - justsend the file
 */
function local_announcements_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = array()) {
    global $CFG, $DB, $USER;

    if ($context->contextlevel != CONTEXT_SYSTEM) {
        return false;
    }

    $areas = array(
        'attachment' => get_string('postform:areaattachment', 'local_announcements'),
        'announcement' => get_string('postform:areaannouncement', 'local_announcements'),
    );

    // filearea must contain a real area
    if (!isset($areas[$filearea])) {
        return false;
    }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/local_announcements/$filearea/$relativepath";
    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        return false;
    }

    $postid = (int)array_shift($args);

    // finally send the file
    send_stored_file($file, 0, 0, true, $options); // download MUST be forced - security!
}

/**
 * Implements callback user_preferences.
 *
 * Used in {@see core_user::fill_preferences_cache()}
 *
 * @return array
 */
function local_announcements_user_preferences() {
    $preferences = array();
    $preferences['local_announcements_auditingmode'] = array(
        'type' => PARAM_INT,
        'null' => NULL_NOT_ALLOWED,
        'default' => 0,
        'choices' => array(0, 1),
        'permissioncallback' => function($user, $preferencename) {
            global $USER;
            return $user->id == $USER->id;
        }
    );
    return $preferences;
}