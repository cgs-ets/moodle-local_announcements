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
 * File containing the form definition for editing impersonator settings.
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_announcements\forms;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

class form_settings_privileges extends \moodleform {

    /**
     * Form definition
     *
     * @return void
     */
    function definition() {
        global $CFG, $OUTPUT, $DB;

        $form =& $this->_form;

        $form->addElement('html', '<div class="form-group row fitem"><div class="col-md-3">Table columns</div><div class="col-md-9 form-inline felement"><strong>audiencetype, code, role, condition, forcesend, description, checktype, checkvalue, checkorder, modrequired, modthreshold, modusername, modpriority, active</strong></div></div>');

        $type = 'textarea'; 
        $name = 'privileges';
        $label = 'table rows (csv)'; 
        $options = 'rows="20" cols="100" style="white-space: pre;overflow-wrap: normal;overflow-x: scroll;line-height: 30px;"';
        $form->addElement($type, $name, ucfirst($label), $options);

        $this->add_action_buttons(false);

    }


}