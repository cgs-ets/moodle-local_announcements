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
 * Admin settings page for audience providers
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

// Include required files and classes.
require_once('../../../config.php');
require_once('../locallib.php');
use \local_announcements\forms\form_settings_audiencetypes;

// Set context.
$context = context_system::instance();

// Set up page parameters.
$audiencesettingsurl = new moodle_url('/local/announcements/settings/audiencetypes.php');
$PAGE->set_context($context);
$PAGE->set_url($audiencesettingsurl->out());
$title = get_string('audiencesettings:heading', 'local_announcements');
$PAGE->set_heading($title);
$PAGE->set_title($SITE->fullname . ': ' . $title);
$PAGE->navbar->add($title, $audiencesettingsurl);

// Ensure user is logged in.
require_login();
require_capability('moodle/site:config', $context, $USER->id); 

$redirectdefault = new moodle_url('/local/announcements/index.php');

// Load the post form with the data.
$repeatno = $DB->count_records('ann_audience_types');
$form = new form_settings_audiencetypes('audiencetypes.php', array(
    'repeatno' => $repeatno,
));

// Get the audience type records and load them into the form.
$table = 'ann_audience_types';
$audiencetypes = array_values($DB->get_records($table));
$data = array();
$i = 0;
while ($i < $repeatno) {
    $data["id[$i]"] = $audiencetypes[$i]->id;
    $data["type[$i]"] = $audiencetypes[$i]->type;
    $data["namesingular[$i]"] = $audiencetypes[$i]->namesingular;
    $data["nameplural[$i]"] = $audiencetypes[$i]->nameplural;
    $data["provider[$i]"] = $audiencetypes[$i]->provider;
    $data["active[$i]"] = $audiencetypes[$i]->active;
    $data["filterable[$i]"] = $audiencetypes[$i]->filterable;
    $data["intersectable[$i]"] = $audiencetypes[$i]->intersectable;
    $data["grouped[$i]"] = $audiencetypes[$i]->grouped;
    $data["uisort[$i]"] = $audiencetypes[$i]->uisort;
    $data["roletypes[$i]"] = $audiencetypes[$i]->roletypes;
    $data["scope[$i]"] = $audiencetypes[$i]->scope;
    $data["description[$i]"] = $audiencetypes[$i]->description;
    $data["itemsoverride[$i]"] = $audiencetypes[$i]->itemsoverride;
    $data["groupdelimiter[$i]"] = $audiencetypes[$i]->groupdelimiter;
    $data["excludecodes[$i]"] = $audiencetypes[$i]->excludecodes;
    $i++;
}
$form->set_data($data);


// Form submitted.
if ($data = $form->get_data()) {
    // Save the data.
    foreach ($data->id as $row => $audtypeid) {
        $record = new stdClass();
        $record->type = $data->type[$row];
        $record->namesingular = $data->namesingular[$row];
        $record->nameplural = $data->nameplural[$row];
        $record->provider = $data->provider[$row];
        $record->active = $data->active[$row];
        $record->filterable = $data->filterable[$row];
        $record->intersectable = $data->intersectable[$row];
        $record->grouped = $data->grouped[$row];
        $record->uisort = $data->uisort[$row];
        $record->roletypes = $data->roletypes[$row];
        $record->scope = $data->scope[$row];
        $record->description = $data->description[$row];
        $record->itemsoverride = $data->itemsoverride[$row];
        $record->groupdelimiter = $data->groupdelimiter[$row];
        $record->excludecodes = $data->excludecodes[$row];
        if ($audtypeid) {
            // Update the record.
            $record->id = $audtypeid;
            $DB->update_record($table, $record);
        } else {
            // Insert the record.
            $DB->insert_record($table, $record);
        }
    }
    $message = get_string("audiencesettings:savesuccess", "local_announcements");
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
