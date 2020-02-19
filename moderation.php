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
 * Page for moderators.
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

// Include required files and classes.
require_once('../../config.php');
require_once('lib.php');
use \local_announcements\providers\moderation;
use \local_announcements\external\announcement_exporter;

// Get URL params.
$postid = optional_param('id', 0, PARAM_INT);
$statusword = optional_param('status', 'pending', PARAM_TEXT);
$status = 0;

// Convert status term to number.
if (in_array($statusword, ANN_MOD_STATUS_CODES)) {
	$status = array_flip(ANN_MOD_STATUS_CODES)[$statusword];
}

// Set page context.
$context = context_system::instance();
$PAGE->set_context($context);

// Set up page parameters.
$pageurl = new moodle_url('/local/announcements/moderation.php', array(
    'id'  => $postid,
));
$PAGE->set_url($pageurl);
$roottitle = get_string('pluginname', 'local_announcements');
$pagetitle = get_string('moderation:heading', 'local_announcements');
$PAGE->set_heading($pagetitle);
$PAGE->set_title($SITE->fullname . ': ' . $pagetitle);
$PAGE->navbar->add($roottitle, new moodle_url('/local/announcements/index.php'));
$PAGE->navbar->add($pagetitle, new moodle_url('/local/announcements/moderation.php'));
$PAGE->navbar->add(ucfirst($statusword));

// Ensure user is logged in.
require_login();
require_can_user_moderate();

// Redirect urls.
$moderationhome = new moodle_url('/local/announcements/moderation.php');
$announcementshome = new moodle_url('/local/announcements/index.php');

// Add css.
$PAGE->requires->css(new moodle_url($CFG->wwwroot . '/local/announcements/moderation.css', array('nocache' => rand().rand())));

// Get the announcements for moderation.
$data = array();
if ($postid > 0) { 
	// Get announcement for moderator. Also checks if user is permitted.
	$announcement = moderation::get_post_for_moderation($postid);
    if (!$announcement) {
        redirect($moderationhome->out());
    }
    $announcements = array($announcement);
    $data['showactions'] = true;
    $PAGE->add_body_class('moderation-single');
} else {
    // Load moderator data.
    $announcements = moderation::get_posts_by_moderation_status($status);
    if ($status == ANN_MOD_STATUS_PENDING) {
    	$data['showactions'] = true;
    }
    $data['showstatusnav'] = true;
}

// Add page header.
$output = $OUTPUT->header();

// Export announcements for template.
$data['announcements'] = array();
foreach ($announcements as $announcement) {
	$exporter = new announcement_exporter($announcement->persistent, array(
	    'context' => $context,
	    'audiences' => $announcement->audiences,
	));
	$data['announcements'][] = $exporter->export($OUTPUT);
}

// Moderation status navigation.
$data['modstatuses'] = array_map(function($status) use ($statusword) {
	$url = new moodle_url('/local/announcements/moderation.php', array('status' => $status));
	return array(
		'name' => ucfirst($status),
		'url' => $url->out(false),
		'btnclass' => $status == $statusword ? 'primary' : 'secondary',
	);
}, ANN_MOD_STATUS_CODES);


// Render the moderation page.
$output .= $OUTPUT->render_from_template('local_announcements/moderation', $data);
// Add amd scripts.
$PAGE->requires->js_call_amd('local_announcements/moderation', 'init');
$output .= $OUTPUT->footer();

echo $output;
