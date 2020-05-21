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
 * View single announcement.
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

// Include required files and classes.
require_once(dirname(__FILE__) . '/../../config.php');
require_once('locallib.php');
use local_announcements\persistents\announcement;

// Gather form data.
$postid = optional_param('id', 0, PARAM_INT);

// Set context.
$context = context_system::instance();

// Set up page parameters.
$PAGE->set_context($context);
$pageurl = new moodle_url('/local/announcements/view.php', array(
	'id' => $postid
));
$PAGE->set_url($pageurl);
$title = get_string('pluginname', 'local_announcements');
$PAGE->set_heading($title);
$PAGE->set_title($SITE->fullname . ': ' . $title);

// Check user is logged in.
require_login();

// get the announcement and customise page props.
$announcements = announcement::get_by_ids_and_username([$postid], $USER->username, is_user_auditor(), (!is_user_auditor()));
$announcement = array_pop($announcements);

$PAGE->navbar->add($title, new moodle_url('/local/announcements/'));
$PAGE->set_heading($title);
$PAGE->set_title($SITE->fullname . ': ' . $title);

if(empty($announcement)) {
	\core\notification::error(get_string('list:announcementnotfound', 'local_announcements'));
	echo $OUTPUT->header();
	echo $OUTPUT->footer();
}

$subjecttext = html_to_text($announcement->persistent->get('subject'));
$PAGE->navbar->add($subjecttext);
$PAGE->requires->css(new moodle_url($CFG->wwwroot . '/local/announcements/styles.css', array('nocache' => rand().rand())));

// Build page output.
$output = '';
$output .= $OUTPUT->header();

$relateds = [
	'context' => $context,
	'announcements' => [$announcement],
	'page' => 0,
];
$list = new local_announcements\external\list_exporter(null, $relateds);

$data = array(
	'list' => $list->export($OUTPUT),
	'canpost' => can_user_post_announcement(),
);

// Render the announcement list.
$output .= $OUTPUT->render_from_template('local_announcements/view', $data);

// Add scripts.
$PAGE->requires->js_call_amd('local_announcements/list', 'init', array('rootselector' => '.local_announcements'));

// Final outputs.
$output .= $OUTPUT->footer();
echo $output;