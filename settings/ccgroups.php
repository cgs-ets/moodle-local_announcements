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
 * Admin settings page for ccgroups
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

// Include required files and classes.
require_once('../../../config.php');
require_once('../locallib.php');
use \local_announcements\forms\form_settings_ccgroups;

// Set context.
$context = context_system::instance();

// Set up page parameters.
$settingsurl = new moodle_url('/local/announcements/settings/ccgroups.php');
$PAGE->set_context($context);
$PAGE->set_url($settingsurl->out());
$title = get_string('settings_ccgroups:heading', 'local_announcements');
$PAGE->set_heading($title);
$PAGE->set_title($SITE->fullname . ': ' . $title);
$PAGE->navbar->add($title, $settingsurl);

// Ensure user is logged in.
require_login();
require_capability('moodle/site:config', $context, $USER->id); 

$redirectdefault = new moodle_url('/local/announcements/index.php');

// Load the form.
$form = new form_settings_ccgroups('ccgroups.php');

// Get the ccgroup records and load them into the textarea.
$table = 'ann_audience_ccgroups';
$records = array_values($DB->get_records($table));
$ccgroups = array();
$ccgroupscsv = '';
foreach ($records as $record) {
    // Remove new lines from descriptions
    $record->description = str_replace(PHP_EOL, '', $record->description);
    $id = $record->id;
    unset($record->id);
    $ccgroupshash = sha1(implode('__', (array) $record));
    $ccgroups[$ccgroupshash] = $id;
    $ccgroupscsv .= implode('|', (array) $record) . '&#13;&#10;';
}
$form->set_data(array('ccgroups' => $ccgroupscsv));

// Form submitted.
if ($data = $form->get_data()) {
    // Save the data.
    $lines = preg_split('/\r\n|[\r\n]/', strtolower($data->ccgroups));
    $newccgroups = array();
    foreach ($lines as $line) {
        $arr = explode('|', $line);
        if (count($arr) < 6) {
            //Missing a field.
            continue;
        }
        $newccgroups[] = array(
            'audiencetype' => trim($arr[0]),
            'code' => trim($arr[1]),
            'role' => trim($arr[2]),
            'forcesend' => trim($arr[3]),
            'description' => trim($arr[4]),
            'ccgroupid' => trim($arr[5]),
        );
    }

    // Sync records.
    foreach ($newccgroups as $new) {
        $hash = sha1(implode('__', $new));

        // Unset if record already exists.
        if (isset($ccgroups[$hash])) {
            unset($ccgroups[$hash]);
            continue;
        }

        // Add new if record doesn't exist yet. Use execute due to reserved keywords...
        $sql = "INSERT INTO {" . $table . "} (audiencetype,code,role,forcesend,description,ccgroupid) 
                     VALUES (?,?,?,?,?,?)";
        $DB->execute($sql, $new);
    }

    // Delete left overs.
    foreach ($ccgroups as $delete) {
        $DB->delete_records($table, array('id' => $delete));
    }

    $message = get_string("settings_ccgroups:savesuccess", "local_announcements");
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
