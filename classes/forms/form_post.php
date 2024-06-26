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
 * File containing the form definition for posting and editing announcements.
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_announcements\forms;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/repository/lib.php');


class form_post extends \moodleform {

    /**
     * Returns the options array to use in filemanager for announcement attachments
     *
     * @return array
     */
    public static function attachment_options() {
        global $CFG;
        
        $config = get_config('local_announcements');
        $maxbytes = get_max_upload_file_size($CFG->maxbytes, 0, $config->maxbytes);

        return array(
            'subdirs' => 0,
            'maxbytes' => $maxbytes,
            'maxfiles' => $config->maxattachments,
            'accepted_types' => '*', //array('document', 'image', 'video', 'audio', 'archive', 'spreadsheet', 'presentation'),
            'return_types' => FILE_INTERNAL | FILE_CONTROLLED_LINK
        );
    }

    /**
     * Returns the options array to use in announcement text editor
     *
     * @param context_module $context
     * @param int $postid post id, use null when adding new post
     * @return array
     */
    public static function editor_options($postid) {
        global $CFG;

        $config = get_config('local_announcements');
        $maxbytes = get_max_upload_file_size($CFG->maxbytes, 0, $config->maxbytes);

        return array(
            'maxfiles' => $config->maxeditorfiles,
            'maxbytes' => $maxbytes,
            'trusttext'=> true,
            'noclean' => true,
            'return_types'=> FILE_INTERNAL | FILE_EXTERNAL,
            'subdirs' => 0
        );
    }

    /**
     * Form definition
     *
     * @return void
     */
    function definition() {
        global $CFG, $OUTPUT, $USER, $DB;

        $mform =& $this->_form;

        $post = $this->_customdata['post'];
        $edit = $this->_customdata['edit'];
        $draftaudiencejson = $this->_customdata['draftaudiencejson'];

        $mform->addElement('header', 'general', '');

        // Subject.
        $mform->addElement('text', 'subject', get_string('postform:subject', 'local_announcements'), 'size="48"');
        $mform->setType('subject', PARAM_TEXT);
        $mform->addRule('subject', get_string('required'), 'required', null, 'client');
        $mform->addRule('subject', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        // Message.
        $mform->addElement('editor', 'message', get_string('postform:message', 'local_announcements'), null, self::editor_options((empty($post->id) ? null : $post->id)));
        $mform->setType('message', PARAM_RAW);
        $mform->addRule('message', get_string('required'), 'required', null, 'client');

        // Attachments.
        $mform->addElement('filemanager', 'attachments', get_string('postform:attachment', 'local_announcements'), null,
            self::attachment_options());
        $mform->addHelpButton('attachments', 'postform:attachment', 'local_announcements');

        /*----------------------
         *   Audience selector.
         *----------------------*/
        $mform->addElement('header', 'selectaudience', get_string('audienceselector:heading', 'local_announcements'));
        $mform->addElement('html', '<p>'.get_string('audienceselector:info', 'local_announcements').'</p>');
        // The audiencesjson field is a text field hidden by css rather than a hidden field so that we can attach validation to it. 
        $mform->addElement('text', 'audiencesjson', 'AudiencesJSON');
        $mform->setType('audiencesjson', PARAM_RAW);
        $mform->addRule('audiencesjson', get_string('required'), 'required', null, 'client');
        //Get audience types from the database.
        $audiencedata = ['audiencetypes' => get_audienceselector_audience_types()];
        // Render the audience selector.
        $audienceselectorhtml = $OUTPUT->render_from_template('local_announcements/audience_selector', $audiencedata); 
        $mform->addElement('html', $audienceselectorhtml);
        $mform->setExpanded('selectaudience');

        /*----------------------
         *   Display settings.
         *----------------------*/
        $mform->addElement('header', 'displaysettings', get_string('postform:displaysettings', 'local_announcements'));
        // Impersonate.
        if (can_impersonate()) {
            $mform->addElement('hidden', 'impersonate');
            $mform->setType('impersonate', PARAM_RAW);
            // The type of field depends on level of impersonation allowed.
            $wildcard = false;
            $options = array();
            // Check if can impersonate any staff.
            if (can_impersonate_any()) {
                $wildcard = true;
            } else {
                $records = $DB->get_records('ann_impersonators', array('authorusername' => $USER->username));
                foreach ($records as $record) {
                    if ($record->impersonateuser == '*') {
                        continue;
                    }
                    $user = \core_user::get_user_by_username($record->impersonateuser);
                    $userphoto = new \moodle_url('/user/pix.php/'.$user->id.'/f2.jpg');
                    $userurl = new \moodle_url('/user/profile.php', array('id' => $user->id));
                    $options[] = array(
                        'username' => $user->username,
                        'fullname' => fullname($user),
                        'photourl' => $userphoto->out(false),
                    );
                }
            }
            // Get existing impersonation.
            $impersonate = null;
            if ($post->impersonate) {
                $user = \core_user::get_user_by_username($post->impersonate);
                $userphoto = new \moodle_url('/user/pix.php/'.$user->id.'/f2.jpg');
                $userurl = new \moodle_url('/user/profile.php', array('id' => $user->id));
                $impersonate = array(
                    'username' => $user->username,
                    'fullname' => fullname($user),
                    'photourl' => $userphoto->out(false),
                );
            }

            // Rendar a user select field.
            $impersonatefield = $OUTPUT->render_from_template('local_announcements/impersonate_selector', array(
                'impersonate' => $impersonate,
                'wildcard' => $wildcard,
                'users' => $options,
            )); 

            // If impersonating a user, convert the username to a json obj.
            if ($impersonate) {
                $post->impersonate = json_encode($impersonate);
            }

            $mform->addElement('html', $impersonatefield);
        }
        // Display period.
        $mform->addElement('date_time_selector', 'timestart', get_string('postform:displaystart', 'local_announcements'));
        $mform->addElement('date_time_selector', 'timeend', get_string('postform:displayend', 'local_announcements'),
            array('optional' => true));
        // Force send.
        $mform->addElement('checkbox', 'forcesend', get_string('postform:forcesend', 'local_announcements'), '<p style="color:red;">' . get_string('postform:forcesendnote', 'local_announcements') . '</p>');
        // Resend digest option if already mailed.
        if ($edit) {
            if ($post->mailed || ($post->forcesend && $post->notified)) {
                $mform->addElement('checkbox', 'remail', get_string('postform:remail', 'local_announcements'), '<p style="color:red;">' . get_string('postform:remailnote', 'local_announcements') . '</p>');
                $mform->hideIf('remail', 'forcesend', 'checked');
            }
        }
        $mform->setExpanded('displaysettings');







        /*----------------------
         *   Moderation
         *----------------------*/
        $mform->addElement('header', 'moderation', 'Moderation');
        $mform->setExpanded('moderation');
        $mform->addElement('select', 'moderator', 'Select a moderator', array());
        $mform->addElement('text', 'moderatorjson', 'ModeratorJSON');
        $mform->setType('moderatorjson', PARAM_RAW);




        /*----------------------
         *   Buttons.
         *----------------------*/
        if ($edit) {
            $submitstring = get_string('savechanges');
        } else {
            $submitstring = get_string('postform:post', 'local_announcements');
        }

        $this->add_action_buttons(true, $submitstring);

        // Hidden fields
        $mform->addElement('hidden', 'edit');
        $mform->setType('edit', PARAM_INT);
    }

    /**
     * Form validation
     *
     * @param array $data data from the form.
     * @param array $files files uploaded.
     * @return array of errors.
     */
    function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (($data['timeend']!=0) && ($data['timestart']!=0) && $data['timeend'] <= $data['timestart']) {
            $errors['timeend'] = get_string('postform:timestartenderror', 'local_announcements');
        }
        if (empty($data['message']['text'])) {
            $errors['message'] = get_string('postform:erroremptymessage', 'local_announcements');
        }
        if (empty($data['subject'])) {
            $errors['subject'] = get_string('postform:erroremptysubject', 'local_announcements');
        }
        if (empty($data['audiencesjson'])) {
            $errors['audiencesjson'] = get_string('postform:errornoaudienceselected', 'local_announcements');
        }
        if (empty($data['moderatorjson'])) {
            $errors['moderator'] = get_string('postform:erroremptymoderator', 'local_announcements');
        }
        return $errors;
    }

}