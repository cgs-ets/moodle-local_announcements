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
require_once('lib.php');
use local_announcements\persistents\announcement;
use local_announcements\providers\audience_loader;
use local_announcements\providers\moderation;

// Gather form data.
$page = optional_param('page', 1, PARAM_INT);

// Set context.
$context = context_system::instance();

// Set up page parameters.
$PAGE->set_context($context);
$pageurl = new moodle_url('/local/announcements/debugger.php', array(
    'page' => $page,
));
$PAGE->set_url($pageurl);
$title = get_string('pluginname', 'local_announcements');
$PAGE->set_heading($title);
$PAGE->set_title($SITE->fullname . ': ' . $title);
$PAGE->navbar->add($title);
// Add css
$PAGE->requires->css(new moodle_url($CFG->wwwroot . '/local/announcements/styles.css', array('nocache' => rand().rand())));

// Check user is logged in.
require_login();

// Build page output
$output = '';
$output .= $OUTPUT->header();


echo "<pre>";
$api = new local_announcements\external\api;
$mod = $api->get_moderation_for_audiences('[{"type":"union","uid":1581982541563,"audiences":[{"audienceprovider":"mdlcourse","audiencetype":"community","audiencenamesingular":"Community","audiencenameplural":"Communities","selecteditems":[{"code":"Community","name":"CGS Community"}],"selectedroles":[{"code":"Students","name":"Participants"},{"code":"Mentors","name":"Parents"},{"code":"Staff","name":"Leaders"}]}]}]');
var_export($mod);
exit;

/*
echo "<pre>";
$api = new local_announcements\external\api;
$items = $api->get_ccgroups_for_audiences('[{"type":"union","uid":1581467893792,"audiences":[{"audienceprovider":"mdlcourse","audiencetype":"course","audiencenamesingular":"Course","audiencenameplural":"Courses","selecteditems":[{"code":"Staff","name":"Staff"}],"selectedroles":[{"code":"Students","name":"Students"}]}]}]');
var_export($items);
exit;
*/

/*
echo "<pre>";
$api = new local_announcements\external\api;
$items = $api->get_audienceselector_users('[{"type":"union","uid":1581467893792,"audiences":[{"audienceprovider":"mdlcourse","audiencetype":"course","audiencenamesingular":"Course","audiencenameplural":"Courses","selecteditems":[{"code":"Staff","name":"Staff"}],"selectedroles":[{"code":"Students","name":"Students"}]}]}]');
var_export($items);
exit;
*/

/*
echo "hello";
$items = \local_announcements\providers\audiences\audience_mdlcourse::get_audience_usernames('Mathematics-7-2020', 'course', array('Mentors'));
var_export($items);
exit;
*/

/*
$auds = \local_announcements\providers\audiences\audience_combination::get_selector_user_audience_associations('campus');
var_export($auds);
exit;
*/

/*
$items = \local_announcements\providers\audiences\audience_combination::get_audience_usernames('mdlprofile|CampusRoles=Students|All Students|Whole School', 'campuz');
var_export($items);
exit;
*/

/*
$t = announcement::get_all_by_audience(null, 'course', 'Mathematics-7-2020');
echo "<pre>"; var_export($t); exit;
*/

/*
$auds = \local_announcements\providers\audiences\audience_combination::get_selector_user_audience_associations('year');
var_export($auds);
exit;
*/

/*
echo "<pre>hello <br>";
$items = \local_announcements\providers\audiences\audience_combination::get_audience_usernames('mdlgroup|5877|Primary School||Students', 'staffcampus', []);
var_export($items);
exit;
*/


/*
echo "<pre>";
$api = new local_announcements\external\api;
$items = $api->get_audienceselector_users('[{"type":"union","uid":1576721655630,"audiences":[{"audienceprovider":"combination","audiencetype":"role","audiencenamesingular":"Role","audiencenameplural":"Roles","selecteditems":[{"code":"mdlcourse|MA10A|Maths Year 10|Courses|staff,students","name":"Courses Maths Year 10"}]}]}]');
var_export($items);
exit;
*/

/*
$items = \local_announcements\providers\audiences\audience_combination::get_audience_usernames('mdlgroup|5149|Year 9', 'year', ['Students']);
var_export($items);
exit;
*/

/*
echo "<pre>";
$api = new local_announcements\external\api;
$items = $api->get_audienceselector_users('[{"type":"union","uid":1578460044875,"audiences":[{"audienceprovider":"mdlcourse","audiencetype":"course","audiencenamesingular":"Course","audiencenameplural":"Courses","selecteditems":[{"code":"Mathematics-7-2020","name":"Mathematics Year 7 2020"}],"selectedroles":[{"code":"Staff","name":"Staff"}]}]}]');
var_export($items);
exit;
*/



/*
moderation::mod_reject(28, 'test');
exit;
*/


/*
echo "<pre>";var_export(privileges::check_profilefield('CampusRoles|*Staff'));
exit;
*/

/*
echo "<pre>";
var_export(moderation::get_alternate_moderators(6));
exit;
*/

/*
echo "<pre>";
$api = new local_announcements\external\api;
$mod = $api->get_moderation_for_audiences('[{"type":"union","uid":1575418382139,"audiences":[{"audienceprovider":"mdlcourse","audiencetype":"course","audiencenamesingular":"Course","audiencenameplural":"Courses","selecteditems":[{"code":"MA10A","name":"Mathematics Year 10"}],"selectedroles":[{"code":"staff","name":"Staff"}]}]}]');
var_export($mod);
exit;
*/

/*

// ANNOUNCEMENT PREFERENCES
        $processors = get_message_processors();
        $providers = message_get_providers_from_db('local_announcements');
        $preferences = \core_message\api::get_all_message_preferences($processors, $providers, $USER);
        $notificationlistoutput = new \core_message\output\preferences\notification_list($processors, $providers,
            $preferences, $USER);
        $data = $notificationlistoutput->export_for_template($OUTPUT);
        array_shift($data['components']); // remove System from the top
        $output .= $OUTPUT->render_from_template('local_announcements/message_preferences',
            $data);
*/

/*
echo "<pre>";
$api = new local_announcements\external\api;
$items = $api->get_announcement_users(5);
var_export($items);
exit;
*/

/*
echo "<pre>";
$auds = \local_announcements\providers\audiences\audience_mdlcourse::get_selector_user_audience_associations('course');
var_export($auds);
exit;
*/

/*
echo "<pre>";
$auds = get_audienceselector_audience_types(); //\local_announcements\providers\audiences\audience_mdlcourse::get_audience_types();
var_export($auds);
exit;
*/




/*
$items = \local_announcements\providers\audiences\audience_mdlprofile::get_selector_user_audience_associations('campusroles');
var_export($items);
exit;
*/

/*
$auds = \local_announcements\providers\audiences\audience_mdlgroup::get_selector_user_audience_associations('group');
$output .= $OUTPUT->render_from_template('local_announcements/audiencelisttree', array('audiencelist' => $auds));
echo "<pre>";
var_export($auds);
exit;
*/

/*
$auds = \local_announcements\providers\audiences\audience_mdlgroup::get_selector_user_audience_associations('communitygroup');
$output .= $OUTPUT->render_from_template('local_announcements/audience_list_tree', array('audiencelist' => $auds));
echo "<pre>";
var_export($auds);
exit;
*/

/*echo "<pre>";
$api = new local_announcements\external\api;
$items = $api->get_audience_items('course');
$output .= $OUTPUT->render_from_template('local_announcements/audiencelisttree', $items);
var_export($items);
exit;*/

/*
echo "<pre>";
$api = new local_announcements\external\api;
$items = $api->get_audience_items('role');
var_export($items);
exit;
*/


/*
echo "<pre>";
$usernames = \local_announcements\providers\audiences\audience_mdlprofile::get_audience_usernames('test1', 'campus', ['staff']);
var_export($usernames);
exit;
*/



/*
echo "<pre>";
$usernames = \local_announcements\providers\audiences\audience_mdlcourse::get_audience_usernames('MA10A', null, ['staff', 'students', 'parents']);
var_export($usernames);
exit;
*/

/*
echo "<pre>";
$users = \local_announcements\providers\audiences\audience_mdlgroup::get_audience_usernames('1', ['staff']);
var_export($users);
exit;
*/
//$auds = \local_announcements\providers\audiences\audience_mdlcourse::get_audience_type(2);
//var_export($auds);
//exit;


/*var_export(announcement::can_user_view_post('studentx', 1));
exit;
*/

/*
$cron = new \local_announcements\task\cron_task_digests();
$cron->execute();
exit;
*/

// Get list of current mentor relationships.
/*echo "<pre>";
$config = get_config('tool_mentordatabase');
var_export($config);
$roleid = $config->role;

        $sql = "SELECT ra.userid as mentor, c.instanceid as student, ra.id as contextid 
                FROM {role_assignments} ra 
                INNER JOIN {context} c ON ra.contextid = c.id
                WHERE ra.roleid = ? 
                AND c.contextlevel = ".CONTEXT_USER;
        $mentorrecords = $DB->get_recordset_sql($sql, array($roleid));

        // Index the parent to student relationships using an associative array        
        $relationships = array();
        foreach ($mentorrecords as $record) {
            $key = $record->mentor . "_" . $record->student;
            $relationships[$key] = $record;
        }
        $mentorrecords->close();

        echo "<pre>";
        var_export($relationships);
        exit;*/




/*
echo "<pre>";
$api = new local_announcements\external\api;
$items = $api->get_audience_items('user');
var_export($items);
exit;
*/










// Final outputs
$output .= $OUTPUT->footer();
echo $output;



