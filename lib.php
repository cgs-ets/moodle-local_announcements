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

function get_audienceselector_audience_types() {
    $audienceproviders = audience_loader::get();
    $audiencetypes = [];
    foreach ($audienceproviders as $audienceprovider) {
        $audiencetypes = array_merge($audiencetypes, $audienceprovider::get_audience_types());
    }
    // Sort the audience types.
    usort($audiencetypes, function($a, $b) {return strcmp($a->uisort, $b->uisort);});
    return $audiencetypes;
}

function get_per_page() {
    $config = get_config('local_announcements');

    // Set up paging
    $perpage = DEFAULT_ANN_PERPAGE;
    if ( isset($config->perpage) ) {
        $perpage = $config->perpage;
    }
    return $perpage;
}

function get_shortpost() {
    $config = get_config('local_announcements');

    // Set up paging
    $shortpost = DEFAULT_SHORTPOST;
    if ( isset($config->shortpost) ) {
        $shortpost = $config->shortpost;
    }
    return $shortpost;
}

function get_provider($provider = '', $type = '') {
    global $DB;
    // Determine the audience provider.
    if(empty($provider)) {
        if (empty($type)) {
            return null;
        }
        $provider = $DB->get_field('ann_audience_types', 'provider', array(
            'active' => 1,
            'type' => $type,
        ));
    }
    $providers = audience_loader::get();
    // If audience provider does not exist because provider is still empty or incorrect then return empty.
    if(!isset($providers[$provider])) {
        return null;
    }
    return $providers[$provider];
}

function get_audiencetype($type) {
    global $DB;
    $audiencetype = $DB->get_record('ann_audience_types', array(
        'type' => $type,
        'active' => 1
    ));
    if(empty($audiencetype)) {
        return null;
    }
    return $audiencetype;
}

/**
 * This function checks whether the user post an announcement
 *
 * @return bool
 */
function can_user_post_announcement() {
    global $USER;

    // Guest and not-logged-in users can not post.
    if (isguestuser() or !isloggedin()) {
        return false;
    }

    // Admins can always post.
    if ( is_user_admin() ) { 
        return true;
    }

    // Users that have selector audiences can post.
    $audienceproviders = audience_loader::get();
    foreach ($audienceproviders as $provider) {
        $audiencetypes = $provider::get_audience_types();
        foreach ($audiencetypes as $type) {
            if ($provider::can_user_post_to_audiencetype($type->type)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * A convenience function to turn audit mode on.
 *
 * @return void
 */
function turn_audit_mode_on() {
    if (is_user_auditor()) {
        set_user_preference('local_announcements_auditingmode', 1);
    }
}

/**
 * A convenience function to turn audit mode off.
 *
 * @return void
 */
function turn_audit_mode_off() {
    if (is_user_auditor()) {
        set_user_preference('local_announcements_auditingmode', 0);
    }
}

/**
 * A convenience function to check whether the user currently has auditing on in their preferences.
 *
 * @return bool
 */
function is_auditing_on() {
    if (is_user_auditor()) {
        return get_user_preferences('local_announcements_auditingmode');
    }
    return false;
}

/**
 * A custom convenience function to check whether user is an auditor.
 *
 * @return bool
 */
function is_user_auditor() {
    global $USER;
    return has_capability('local/announcements:auditor', context_user::instance($USER->id), null, false);
}

/**
 * A custom convenience function to check whether user is admin.
 *
 * @return bool
 */
function is_user_admin() {
    global $USER;
    return has_capability('local/announcements:administer', context_user::instance($USER->id), null, false);
}

/**
 * A custom convenience function that tests where a user can moderate, and displays an error if
 * the user cannot.
 *
 * @return void terminates with an error if the user does not have ability to post
 */
function require_can_user_moderate() {
    if (!moderation::can_user_moderate()) {
        throw new required_capability_exception(context_system::instance(), 'local/announcements:post', 'nopermissions', '');
    }
}

/**
 * A custom convenience function that tests can_user_post_announcement, and displays an error if
 * the user cannot post.
 *
 * @return void terminates with an error if the user does not have ability to post
 */
function require_can_user_post_announcement() {
    if (!can_user_post_announcement()) {
        throw new required_capability_exception(context_system::instance(), 'local/announcements:post', 'nopermissions', '');
    }
}

/**
 * Checks whether posting is enabled/disabled at the settings level
 *
 * @return void terminates with an error if not enabled
 */
function require_posting_globally_enabled() {
    $config = get_config('local_announcements');
    if ($config->globaldisable) {
        throw new moodle_exception('error:postingdisabled', 'local_announcements');
    }
}


function is_show_all_in_context() {
    $config = get_config('local_announcements');
    if ($config->showposterallinctx) {
        return true;
    }
    return false;
}

function can_view_all_in_context($context) {
    $config = get_config('local_announcements');
    if ($config->showposterallinctx) {
        return has_capability('local/announcements:post', $context, null, false);
    }
    return false;
}

function pr() {
    $args = func_get_args();
    echo "<pre>";
    var_export($args);
    echo "<br>";
}