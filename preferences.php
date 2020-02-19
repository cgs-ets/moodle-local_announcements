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

// Gather form data.
$page = optional_param('page', 1, PARAM_INT);

// Set context.
$context = context_system::instance();

// Set up page parameters.
$PAGE->set_context($context);
$pageurl = new moodle_url('/local/announcements/preferences.php', array(
    'page' => $page,
));
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

// Build page output
$output = '';
$output .= $OUTPUT->header();

$processors = get_message_processors();
$providers = message_get_providers_from_db('local_announcements');
$preferences = \core_message\api::get_all_message_preferences($processors, $providers, $USER);
$notificationlistoutput = new \core_message\output\preferences\notification_list($processors, $providers,
    $preferences, $USER);
$data = $notificationlistoutput->export_for_template($OUTPUT);

array_shift($data['components']); // remove System from the top
// Remove the displayname as we already know we are changing "Announcement" preferences from the context...
if (isset($data['components'][0]['displayname'])) {
    $data['components'][0]['displayname'] = '';
}
       
$output .= $OUTPUT->render_from_template('local_announcements/message_preferences',
    $data);

// Final outputs
$output .= $OUTPUT->footer();
echo $output;



