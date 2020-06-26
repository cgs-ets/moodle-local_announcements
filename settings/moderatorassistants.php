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
 * Admin settings page for moderator assistants
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

// Include required files and classes.
require_once('../../../config.php');
require_once('../locallib.php');
use \local_announcements\forms\form_settings_moderatorassistants;

// Set context.
$context = context_system::instance();

// Set up page parameters.
$settingsurl = new moodle_url('/local/announcements/settings/moderatorassistants.php');
$PAGE->set_context($context);
$PAGE->set_url($settingsurl->out());
$title = get_string('settings_moderatorassistants:heading', 'local_announcements');
$PAGE->set_heading($title);
$PAGE->set_title($SITE->fullname . ': ' . $title);
$PAGE->navbar->add($title, $settingsurl);

// Ensure user is logged in.
require_login();
require_capability('moodle/site:config', $context, $USER->id); 

$redirectdefault = new moodle_url('/local/announcements/index.php');

// Load the form.
$form = new form_settings_moderatorassistants('moderatorassistants.php');

// Get the records and load them into the textarea.
$table = 'ann_moderator_assistants';
$records = array_values($DB->get_records($table));
$moderatorassistants = array();
$moderatorassistantsstr = '';
foreach ($records as $record) {
    $moderatorassistantsstr .= $record->modusername . ',' . $record->assistantusername . '&#13;&#10;';
    $moderatorassistants[$record->modusername . '_' . $record->assistantusername] = $record->id;
}
$form->set_data(array('moderatorassistants' => $moderatorassistantsstr));

// Form submitted.
if ($data = $form->get_data()) {
    // Save the data.
    $lines = preg_split('/\r\n|[\r\n]/', strtolower($data->moderatorassistants));
    $newmoderatorassistants = array();
    foreach ($lines as $line) {
        $arr = explode(',', $line);
        if (count($arr) < 2) {
            //Missing a field.
            continue;
        }
        $newmoderatorassistants[] = array(
            'modusername' => trim($arr[0]),
            'assistantusername' => trim($arr[1]),
        );
    }

    // Sync records.
    foreach ($newmoderatorassistants as $new) {
        $key = $new['modusername'] . '_' . $new['assistantusername'];
        // Unset if record already exists.
        if (isset($moderatorassistants[$key])) {
            unset($moderatorassistants[$key]);
            continue;
        }
        // Add new if record doesn't exist yet.
        $DB->insert_record($table, $new);
    }

    // Delete left overs.
    foreach ($moderatorassistants as $delete) {
        $DB->delete_records($table, array('id' => $delete));
    }

    $message = get_string("settings_moderatorassistants:savesuccess", "local_announcements");
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
