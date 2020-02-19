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
 * Display Announcements.
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

// Include required files and classes.
require_once('../../config.php');
require_once('lib.php');
use local_announcements\persistents\announcement;
use local_announcements\providers\audience_loader;
use local_announcements\providers\moderation;

// Get page params.
$page = optional_param('page', 0, PARAM_INT);
$provider = optional_param('provider', '', PARAM_ALPHA);
$type = optional_param('type', '', PARAM_ALPHA);
$code = optional_param('code', '', PARAM_ALPHANUMEXT);
$audit = optional_param('audit', '', PARAM_ALPHANUMEXT);
$viewas = optional_param('viewas', '', PARAM_ALPHANUMEXT);

// Determine if viewing as another user.
$viewason = false;
$viewastitle = '';
if (!empty($viewas)) {
	$viewason = true;
	$viewasuser = core_user::get_user_by_username($viewas);
	if ($viewasuser) {
		$viewastitle = get_string('list:viewastitle', 'local_announcements', fullname($viewasuser));
	}
}

// Determine if the list is being filtered.
$filtered = false;
if (($type != '' || $provider != '') && $code != '') {
	$filtered = true;
}

// Turn edit mode on or off if url param provided. Also turn off if viewing as a user.
if ($audit == "on") {
	turn_audit_mode_on();
}
if ($audit == "off" || $viewason) {
	turn_audit_mode_off();
}

// Set context.
$context = context_system::instance();
$coursecontext = null;

// Set up page parameters.
$PAGE->set_context($context);
$pageurl = new moodle_url('/local/announcements/index.php', array(
	'page' => $page,
	'provider' => $provider,
	'type' => $type,
	'code' => $code,
));
$PAGE->set_url($pageurl);
$title = get_string('pluginname', 'local_announcements');
$PAGE->set_heading($title);
$PAGE->set_title($SITE->fullname . ': ' . $title);

// Check user is logged in.
require_login();

// If unable to load provider, then do not attempt to filter.
$provider = get_provider($provider, $type);
if(!isset($provider)) {
    $filtered = false;
}

// Looking at announcements for a specific audience.
if($filtered) {
	$audiencename = $provider::get_audience_name($code);
	$PAGE->navbar->add($title, new moodle_url('/local/announcements/'));
	$title .= ' for: ' . $audiencename;
	$PAGE->set_heading($title);
	$PAGE->set_title($SITE->fullname . ': ' . $title);
	$title = $audiencename;

	// Attempt to get course context if there is one.
	if ($provider::PROVIDER == 'mdlcourse' || $provider::PROVIDER == 'mdlgroup') {
		$courseid = $DB->get_field('course', 'id', array('idnumber' => $code));
        if ($courseid) {
            $coursecontext = context_course::instance($courseid);
        }
	}
}
// Add title to nav.
$PAGE->navbar->add($title);

// Add required styles and scripts.
$PAGE->requires->css(new moodle_url($CFG->wwwroot . '/local/announcements/styles.css', array('nocache' => rand().rand())));
$PAGE->requires->js( new moodle_url($CFG->wwwroot . '/local/announcements/js/infinite-scroll.pkgd.min.js'), true );

// Build page output.
$output = $OUTPUT->header();

// Get the announcements, depending on audit mode and audiences.
$announcements = array();
if ($viewason && is_user_auditor()) {
	if ($viewasuser) {
		$announcements = announcement::get_by_username($viewasuser->username, $page);
	}
} elseif (is_auditing_on()) {
	if ($filtered) {
		$announcements = announcement::get_all_by_audience(null, $type, $code, $page);
	} else {
		$announcements = announcement::get_all($page);
	}
} else {
	if ($filtered) {
		if (isset($coursecontext) && can_view_all_in_context($coursecontext)) {
			$announcements = announcement::get_all_by_audience(null, $type, $code, $page);
		} else {
			$announcements = announcement::get_by_username_and_audience($USER->username, null, $type, $code, $page);
		}
	} else {
		$announcements = announcement::get_by_username($USER->username, $page);
	}
}

// Export the announcements list.
$relateds = [
	'context' => $context,
	'announcements' => $announcements,
	'page' => $page,
];
$list = new local_announcements\external\list_exporter(null, $relateds);
$data = array(
	'list' => $list->export($OUTPUT),
	'canpost' => can_user_post_announcement(),
	'isadmin' => is_user_admin(),
	'canmoderate' => moderation::can_user_moderate(),
	'canaudit' => is_user_auditor(),
	'auditingon' => is_auditing_on(),
	'viewason' => $viewason,
	'viewastitle' => $viewastitle,
);

// Render the announcement list.
$output .= $OUTPUT->render_from_template('local_announcements/index', $data);

// Add amd scripts.
$PAGE->requires->js_call_amd('local_announcements/list', 'init');

// Final outputs.
$output .= $OUTPUT->footer();
echo $output;
