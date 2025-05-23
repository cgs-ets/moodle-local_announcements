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
require_once(dirname(__FILE__) . '/../../config.php');
use local_announcements\persistents\announcement;
use local_announcements\providers\audience_loader;

// Set context.
$context = context_system::instance();

// Set up page parameters.
$PAGE->set_context($context);
$pageurl = new moodle_url('/local/announcements/preferences.php');
$PAGE->set_url($pageurl);
$title = get_string('pluginname', 'local_announcements');
$pagetitle = 'Preferences';
$fulltitle = $title . ' ' . $pagetitle;
$PAGE->set_heading($fulltitle);
$PAGE->set_title($SITE->fullname . ': ' . $fulltitle);
$PAGE->navbar->add($title, new moodle_url('/local/announcements/'));
$PAGE->navbar->add($pagetitle);

// Add css
$PAGE->requires->css(new moodle_url($CFG->wwwroot . '/local/announcements/styles.css', array('nocache' => rand().rand())));

// Check user is logged in.
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$preferences = array(
		'digests' => 0,
		'email' => 0,
		'notify' => 0,
	);

	if (isset($_POST['digests'])) {
		$preferences['digests'] = 1;
	}
	if (isset($_POST['email'])) {
		$preferences['email'] = 1;
	}
	if (isset($_POST['notify'])) {
		$preferences['notify'] = 1;
	}

    $sql = "SELECT *
            FROM {ann_user_preferences}
            WHERE username = ?";
    $params = array($USER->username);
    $record = $DB->get_record_sql($sql, $params);
    if ($record) {
        $DB->update_record('ann_user_preferences', array(
            'id'=>$record->id, 
            'digests'=>$preferences['digests'], 
            'email'=>$preferences['email'], 
            'notify'=>$preferences['notify']
        ));
    } else {
        $DB->insert_record('ann_user_preferences', array(
            'username'=>$USER->username, 
            'digests'=>$preferences['digests'], 
            'email'=>$preferences['email'], 
            'notify'=>$preferences['notify']
        ));
    }


	// Redirect to self with notification.
    redirect(
        $pageurl->out(),
        '<p>Preferences saved.</p>',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );

} else  {
	// Load preferences.
	$sql = "SELECT *
          FROM {ann_user_preferences} p 
         WHERE p.username = ?";
	$params = array($USER->username);
	$records = array_values($DB->get_records_sql($sql, $params));
	$preferences = array();
	$preferences['digests'] = 1;
	$preferences['email'] = 0;
	$preferences['notify'] = 1;

	foreach ($records as $preference) {
		$preferences['digests'] = 0;
		$preferences['email'] = 0;
		$preferences['notify'] = 0;

		if ($preference->digests == 1) {
			$preferences['digests'] = 1;
		}
		if ($preference->email == 1) {
			$preferences['email'] = 1;
		}
		if ($preference->notify == 1) {
			$preferences['notify'] = 1;
		}
	}

	// Build page output
	$output = '';
	$output .= $OUTPUT->header();

	$output .= $OUTPUT->render_from_template('local_announcements/preferences', $preferences);

	// Final outputs
	$output .= $OUTPUT->footer();
	echo $output;
}