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
 * Admin settings page for impersonators
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

// Include required files and classes.
require_once('../../config.php');
require_once('locallib.php');
use \local_announcements\forms\form_impersonatorsettings;

// Set context.
$context = context_system::instance();

// Set up page parameters.
$impersonatorsettingsurl = new moodle_url('/local/announcements/impersonatorsettings.php');
$PAGE->set_context($context);
$PAGE->set_url($impersonatorsettingsurl->out());
$title = get_string('impersonatorsettings:heading', 'local_announcements');
$PAGE->set_heading($title);
$PAGE->set_title($SITE->fullname . ': ' . $title);
$PAGE->navbar->add($title, $impersonatorsettingsurl);

// Ensure user is logged in.
require_login();
require_capability('moodle/site:config', $context, $USER->id); 

$redirectdefault = new moodle_url('/local/announcements/index.php');

// Load the post form with the data.
$repeatno = $DB->count_records('ann_audience_types');
$form = new form_impersonatorsettings('impersonatorsettings.php', array(
    'repeatno' => $repeatno,
));

// Get the impersonator records and load them into the textarea.
$table = 'ann_impersonators';
$records = array_values($DB->get_records($table));
$impersonators = array();
$impersonatorsstr = '';
foreach ($records as $record) {
    $impersonatorsstr .= $record->authorusername . ',' . $record->impersonateuser . '&#13;&#10;';
    $impersonators[$record->authorusername . '_' . $record->impersonateuser] = $record->id;
}
$form->set_data(array('impersonators' => $impersonatorsstr));

// Form submitted.
if ($data = $form->get_data()) {
    // Save the data.
    $lines = preg_split('/\r\n|[\r\n]/', strtolower($data->impersonators));
    $newimpersonators = array();
    foreach ($lines as $line) {
        $arr = explode(',', $line);
        if (count($arr) < 2) {
            //Missing a field.
            continue;
        }
        $newimpersonators[] = array(
            'authorusername' => trim($arr[0]),
            'impersonateuser' => trim($arr[1]),
        );
    }

    // Sync records.
    foreach ($newimpersonators as $new) {
        $key = $new['authorusername'] . '_' . $new['impersonateuser'];
        // Unset if record already exists.
        if (isset($impersonators[$key])) {
            unset($impersonators[$key]);
            continue;
        }
        // Add new if record doesn't exist yet.
        $DB->insert_record($table, $new);
    }

    // Delete left overs.
    foreach ($impersonators as $delete) {
        $DB->delete_records($table, array('id' => $delete));
    }

    $message = get_string("impersonatorsettings:savesuccess", "local_announcements");
    redirect(
        $redirectdefault->out(),
        '<p>'.$message.'</p>',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
$form->display();
echo $OUTPUT->footer();
