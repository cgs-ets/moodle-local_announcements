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
 * Post or update an announcement
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

// Include required files and classes.
require_once('../../config.php');
require_once('lib.php');
use \local_announcements\forms\form_post;
use \local_announcements\persistents\announcement;

// Gather form data.
$edit = optional_param('edit', 0, PARAM_INT);

// Set context.
$context = context_system::instance();

// Set up page parameters.
$PAGE->set_context($context);
$pageurl = new moodle_url('/local/announcements/post.php', array(
    'edit'  => $edit,
));
$PAGE->set_url($pageurl);
$title = get_string('pluginname', 'local_announcements');
$PAGE->set_heading($title);
$PAGE->set_title($SITE->fullname . ': ' . $title);
$PAGE->navbar->add($title, new moodle_url('/local/announcements/index.php'));

// Ensure user is logged in.
require_login();
require_posting_globally_enabled();
require_can_user_post_announcement();

$redirectdefault = new moodle_url('/local/announcements/index.php');

// Initialise a default post.
$post = new stdClass();
$post->subject       = '';
$post->message       = '';
$post->messageformat = editors_get_preferred_format();
$post->messagetrust = 0;
$post->audiencesjson = '';

if (!empty($edit)) { 
    //Load the announcement data into the form.
    $exists = announcement::record_exists($edit);
    $canusereditpost = announcement::can_user_edit_post($edit);

    if ($exists && $canusereditpost) {
        $announcement         = new announcement($edit);
        $post->id             = $announcement->get('id');
        $post->subject        = $announcement->get('subject');
        $post->authorusername = $announcement->get('authorusername');
        $post->message        = $announcement->get('message');
        $post->messageformat  = editors_get_preferred_format();
        $post->messagetrust   = 0;
        $post->audiencesjson  = $announcement->get('audiencesjson');
        $post->timestart      = $announcement->get('timestart');
        $post->timeend        = $announcement->get('timeend');
        $post->mailed         = $announcement->get('mailed');
        $post = trusttext_pre_edit($post, 'message', $context);
    } else {
        redirect($redirectdefault->out());
    }
}

// Load the post form with the data.
$mformpost = new form_post('post.php', array(
	'post' => $post,
	'edit' => $edit,
), 'post', '', array('data-form' => 'lann-post'));

// Redirect to index if cancel was clicked.
if ($mformpost->is_cancelled()) {
    redirect($redirectdefault->out());
}

$postid = empty($post->id) ? null : $post->id;
$draftitemid = file_get_submitted_draft_itemid('attachments');
$attachoptions = form_post::attachment_options();
file_prepare_draft_area($draftitemid, $context->id, 'local_announcements', 
    'attachment', $postid, $attachoptions);

$draftideditor = file_get_submitted_draft_itemid('message');
$editoropts = form_post::editor_options($postid);
$currenttext = file_prepare_draft_area($draftideditor, $context->id, 'local_announcements', 
    'announcement', $postid, $editoropts, $post->message);

// This is what actually sets the data in the form.
$mformpost->set_data(
    array(
        'attachments' => $draftitemid,
        'general' => get_string('postform:yournewpost', 'local_announcements'),
        'subject' => $post->subject,
        'message' => array(
            'text' => $currenttext,
            'format' => empty($post->messageformat) ? editors_get_preferred_format() : $post->messageformat,
            'itemid' => $draftideditor
        ),
        'audiencesjson' => $post->audiencesjson,
        'forcesend' => !empty($post->forcesend),
    ) +

    array('edit' => $edit) +

    (isset($post->format) ? array('format' => $post->format) : array()) +

    (isset($post->timestart) ? array('timestart' => $post->timestart) : array()) +

    (isset($post->timeend) ? array('timeend' => $post->timeend) : array())

);

// Form submitted.
if ($formdata = $mformpost->get_data()) {
    // Draw out some vars.
    $formdata->forcesend = empty($formdata->forcesend) ? 0 : 1;
    $formdata->itemid = $formdata->message['itemid'];
    $formdata->messageformat = $formdata->message['format'];
    $formdata->message = $formdata->message['text'];
    $formdata->messagetrust = trusttext_trusted($context);

    // Clean message text.
    $formdata = trusttext_pre_edit($formdata, 'message', $context);

    // See if remail has been set.
    $formdata->remail = isset($formdata->remail) ? $formdata->remail : 0;

    // If edit is 0, this will create a new post.
    $result = announcement::save_from_data($edit, $formdata);

    // Set up result message and redirect.
    $message = get_string("postform:postupdatesuccess", "local_announcements");
    if ($formdata->edit == 0 ) { 
        $message = get_string("postform:postaddedsuccess", "local_announcements");
    }
    redirect(
        $redirectdefault->out(),
        '<p>'.$message.'</p>',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Add css.
$PAGE->requires->css(new moodle_url($CFG->wwwroot . '/local/announcements/styles.css', array('nocache' => rand().rand())));

echo $OUTPUT->header();

$mformpost->display();

echo $OUTPUT->render_from_template('local_announcements/loadingoverlay', array('class' => 'lann-post-overlay'));

// Add scripts.
$PAGE->requires->js_call_amd('local_announcements/post', 'init');

echo $OUTPUT->footer();
