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
 * Admin settings page for privileges
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

// Include required files and classes.
require_once('../../../config.php');
require_once('../locallib.php');
use \local_announcements\forms\form_settings_privileges;

// Set context.
$context = context_system::instance();

// Set up page parameters.
$settingsurl = new moodle_url('/local/announcements/settings/privileges.php');
$PAGE->set_context($context);
$PAGE->set_url($settingsurl->out());
$title = get_string('settings_privileges:heading', 'local_announcements');
$PAGE->set_heading($title);
$PAGE->set_title($SITE->fullname . ': ' . $title);
$PAGE->navbar->add($title, $settingsurl);

// Ensure user is logged in.
require_login();
require_capability('moodle/site:config', $context, $USER->id); 

$redirectdefault = new moodle_url('/local/announcements/index.php');

// Load the form.
$form = new form_settings_privileges('privileges.php');

// Get the records and load them into the textarea.
$table = 'ann_privileges';
$records = array_values($DB->get_records($table));
$privileges = array();
$privilegescsv = '';
foreach ($records as $record) {
    // Remove new lines from descriptions
    $record->description = str_replace(PHP_EOL, '', $record->description);
    $id = $record->id;
    unset($record->id);
    $privilegeshash = sha1(implode('__', (array) $record));
    $privileges[$privilegeshash] = $id;
    $privilegescsv .= implode(',', (array) $record) . '&#13;&#10;';
}
$form->set_data(array('privileges' => $privilegescsv));

// Form submitted.
if ($data = $form->get_data()) {
    // Save the data.
    $lines = preg_split('/\r\n|[\r\n]/', $data->privileges);
    $newprivileges = array();
    foreach ($lines as $line) {
        $arr = explode(',', $line);
        if (count($arr) < 14) {
            //Missing a field.
            continue;
        }
        // Remove new lines from descriptions
        $newprivileges[] = array(
            'audiencetype' => trim($arr[0]),
            'code' => trim($arr[1]),
            'role' => trim($arr[2]),
            'condition' => trim($arr[3]),
            'forcesend' => trim($arr[4]),
            'description' => str_replace(PHP_EOL, '', trim($arr[5])),
            'checktype' => trim($arr[6]),
            'checkvalue' => trim($arr[7]),
            'checkorder' => trim($arr[8]),
            'modrequired' => trim($arr[9]),
            'modthreshold' => trim($arr[10]),
            'modusername' => trim($arr[11]),
            'modpriority' => trim($arr[12]),
            'active' => trim($arr[13]),
        );
    }

    // Sync records.
    foreach ($newprivileges as $new) {
        $hash = sha1(implode('__', $new));

        // Unset if record already exists.
        if (isset($privileges[$hash])) {
            unset($privileges[$hash]);
            continue;
        }

        // Add new if record doesn't exist yet. Use execute due to reserved keywords...
        $sql = 'INSERT INTO {ann_privileges} (`audiencetype`,`code`,`role`,`condition`,`forcesend`,`description`,`checktype`,
            `checkvalue`,`checkorder`,`modrequired`,`modthreshold`,`modusername`,`modpriority`,`active`) 
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
        $DB->execute($sql, $new);
    }

    // Delete left overs.
    foreach ($privileges as $delete) {
        $DB->delete_records($table, array('id' => $delete));
    }

    $message = get_string("settings_privileges:savesuccess", "local_announcements");
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
