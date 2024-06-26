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
		'message_provider_local_announcements_digests_loggedin' => 'none',
		'message_provider_local_announcements_digests_loggedoff' => 'none',
		'message_provider_local_announcements_notifications_loggedin' => 'none',
		'message_provider_local_announcements_notifications_loggedoff' => 'none',
		'message_provider_local_announcements_notificationsmobile_loggedin' => 'none',
		'message_provider_local_announcements_notificationsmobile_loggedoff' => 'none',
	);

	if (isset($_POST['dailydigests'])) {
		$preferences['message_provider_local_announcements_digests_loggedin'] = 'email';
		$preferences['message_provider_local_announcements_digests_loggedoff'] = 'email';
	}
	if (isset($_POST['bellalerts'])) {
		$preferences['message_provider_local_announcements_notifications_loggedin'] = 'popup';
		$preferences['message_provider_local_announcements_notifications_loggedoff'] = 'popup';
	}
	if (isset($_POST['instantemails'])) {
		if (!empty($preferences['message_provider_local_announcements_notifications_loggedin'])){
			$preferences['message_provider_local_announcements_notifications_loggedin'] .= ',';
			$preferences['message_provider_local_announcements_notifications_loggedoff'] .= ',';
		}
		$preferences['message_provider_local_announcements_notifications_loggedin'] .= 'email';
		$preferences['message_provider_local_announcements_notifications_loggedoff'] .= 'email';
	}
	if (isset($_POST['pushnotifications'])) {
		$preferences['message_provider_local_announcements_notificationsmobile_loggedin'] = 'airnotifier';
		$preferences['message_provider_local_announcements_notificationsmobile_loggedoff'] = 'airnotifier';
	}

	foreach ($preferences as $key => $value) {
		$sql = "SELECT *
          	      FROM {user_preferences}
                 WHERE userid = ?
                   AND name = ?";
        $params = array($USER->id,$key);
	    $record = $DB->get_record_sql($sql, $params);
	    if ($record) {
	    	$DB->update_record('user_preferences', array('id'=>$record->id, 'name'=>$key, 'value'=>$value, 'userid'=>$USER->id));
	    } else {
	    	$DB->insert_record('user_preferences', array('name'=>$key, 'value'=>$value, 'userid'=>$USER->id));
	    }
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
          FROM {user_preferences} p 
         WHERE p.userid = ?
           AND p.name LIKE 'message_provider_local_announcements%'";
	$params = array($USER->id);
	$records = array_values($DB->get_records_sql($sql, $params));
	$preferences = array();
	$preferences['dailydigests'] = true;
	$preferences['instantemails'] = false;
	$preferences['pushnotifications'] = true;
	$preferences['bellalerts'] = true;

	// Load existing preferences.
	foreach ($records as $preference) {
		// Digest preference.
		if ($preference->name == 'message_provider_local_announcements_digests_loggedin') {
			if (strpos($preference->value, 'email') !== false) {
				$preferences['dailydigests'] = true;
			} else {
				$preferences['dailydigests'] = false;
			}
		}

		// Notifications.
		if ($preference->name == 'message_provider_local_announcements_notifications_loggedin') {
			if (strpos($preference->value, 'email') !== false) {
				$preferences['instantemails'] = true;
			}

			if (strpos($preference->value, 'popup') !== false) {
				$preferences['bellalerts'] = true;
			} else {
				$preferences['bellalerts'] = false;
			}
		}

		// Mobile notifications.
		if ($preference->name == 'message_provider_local_announcements_notificationsmobile_loggedin') {
			if (strpos($preference->value, 'airnotifier') !== false) {
				$preferences['pushnotifications'] = true;
			} else {
				$preferences['pushnotifications'] = false;
			}
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