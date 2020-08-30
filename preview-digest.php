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
require_once($CFG->dirroot . '/local/announcements/locallib.php');
use local_announcements\persistents\announcement;
use local_announcements\external\announcement_exporter;

// Gather form data.
$page = optional_param('page', 1, PARAM_INT);

// Set context.
$context = context_system::instance();

// Set up page parameters.
$PAGE->set_context($context);
$pageurl = new moodle_url('/local/announcements/index.php', array(
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

// Ensure user is admin.

if (!is_user_admin() && !is_siteadmin()) {
    die;
}

// Build page output
$output = '';
$output .= $OUTPUT->header();


global $OUTPUT, $DB, $USER;

$sql = "SELECT an.id,
            an.timecreated,
            an.authorusername,
            an.forcesend
        FROM {ann_posts} an
        WHERE mailed = 0
        ORDER BY an.timecreated ASC";

        $posts = $DB->get_records_sql($sql);

        $exportedposts = array();

        $announcements = announcement::get_by_ids_and_username(array_keys($posts), $USER->username);
        $context = \context_system::instance();
        foreach ($announcements as $announcement) {
            $exporter = new announcement_exporter($announcement->persistent, [
                'context' => $context,
                'audiences' => $announcement->audiences,
            ]);
            $exportedposts[] = $exporter->export($OUTPUT);
        }

        $config = get_config('local_announcements');
        // Render the digest template with the posts
        $content = [
            'posts' => $exportedposts,
            'userprefs' => (new \moodle_url('/local/announcements/preferences.php'))->out(false),
            'digestheaderimage' => $config->digestheaderimage,
            'digestfooterimage' => $config->digestfooterimage,
            'digestfooterimageurl' => $config->digestfooterimageurl,
            'digestfootercredits' => $config->digestfootercredits,
        ];

        //echo "<pre>";
        //var_export($exportedposts);
        //exit;

        $notificationhtml = $OUTPUT->render_from_template('local_announcements/message_digest_html', $content);

        echo "<pre>";
        var_export($notificationhtml);
        exit;




