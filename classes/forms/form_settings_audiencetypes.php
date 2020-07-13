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
 * File containing the form definition for editing audience settings.
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_announcements\forms;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');
use \local_announcements\providers\audience_loader;

class form_settings_audiencetypes extends \moodleform {

    /**
     * Form definition
     *
     * @return void
     */
    function definition() {
        global $CFG, $OUTPUT, $DB;

        $form =& $this->_form;

        $repeatno = $this->_customdata['repeatno']; 

        // Build the repeating array.
        $repeatarray = array();

        $type = 'hidden'; 
        $name = 'id'; 
        $repeatarray[] = &$form->createElement($type, $name);

        $type = 'text'; 
        $name = $label = 'type'; 
        $options = array('size' => '50');
        $repeatarray[] = &$form->createElement($type, $name, ucfirst($label), $options);

        $type = 'text'; 
        $name = $label = 'namesingular'; 
        $options = array('size' => '50');
        $repeatarray[] = &$form->createElement($type, $name, ucfirst($label), $options);

        $type = 'text'; 
        $name = $label = 'nameplural'; 
        $options = array('size' => '50');
        $repeatarray[] = &$form->createElement($type, $name, ucfirst($label), $options);

        $type = 'select'; 
        $name = $label = 'provider';
        $providers = array_keys(audience_loader::get());
        $options = array_combine($providers, $providers);
        $repeatarray[] = &$form->createElement($type, $name, ucfirst($label), $options);

        $type = 'advcheckbox';
        $name = $label = 'active';
        $options = array();
        $values = array(0, 1);
        $repeatarray[] = &$form->createElement($type, $name, ucfirst($label), '', $options, $values);

        $type = 'advcheckbox';
        $name = $label = 'filterable';
        $options = array();
        $values = array(0, 1);
        $repeatarray[] = &$form->createElement($type, $name, ucfirst($label), '', $options, $values);

        $type = 'advcheckbox';
        $name = $label = 'intersectable';
        $options = array();
        $values = array(0, 1);
        $repeatarray[] = &$form->createElement($type, $name, ucfirst($label), '', $options, $values);

        $type = 'advcheckbox';
        $name = $label = 'grouped';
        $options = array();
        $values = array(0, 1);
        $repeatarray[] = &$form->createElement($type, $name, ucfirst($label), '', $options, $values);

        $type = 'text';
        $name = $label = 'uisort';
        $options = array('size' => '10');
        $repeatarray[] = &$form->createElement($type, $name, ucfirst($label), $options);

        $type = 'text'; 
        $name = 'roletypes'; 
        $label = 'Active roles'; 
        $options = array('size' => '50');
        $repeatarray[] = &$form->createElement($type, $name, ucfirst($label), $options);

        $type = 'text'; 
        $name = $label = 'scope'; 
        $options = array('size' => '100');
        $repeatarray[] = &$form->createElement($type, $name, ucfirst($label), $options);

        $type = 'text'; 
        $name = $label = 'description'; 
        $options = array('size' => '100');
        $repeatarray[] = &$form->createElement($type, $name, ucfirst($label), $options);

        $type = 'textarea'; 
        $name = 'itemsoverride';
        $label = 'items override'; 
        $options = 'wrap="virtual" rows="5" cols="100"';
        $repeatarray[] = &$form->createElement($type, $name, ucfirst($label), $options);

        $type = 'text'; 
        $name = 'groupdelimiter';
        $label = 'Items delimiter'; 
        $options = array('size' => '10');
        $repeatarray[] = &$form->createElement($type, $name, ucfirst($label), $options);

        $type = 'textarea'; 
        $name = 'excludecodes';
        $label = 'exclude codes'; 
        $options = 'wrap="virtual" rows="3" cols="100"';
        $repeatarray[] = &$form->createElement($type, $name, ucfirst($label), $options);

        $type = 'html';
        $value = '<br/><hr><br/>';
        $repeatarray[] = &$form->createElement($type, $value); // Spacer.

        $repeatoptions = array();
        $repeatoptions['id']['type']             = PARAM_INT;
        $repeatoptions['type']['type']           = PARAM_TEXT;
        $repeatoptions['namesingular']['type']   = PARAM_TEXT;
        $repeatoptions['nameplural']['type']     = PARAM_TEXT;
        $repeatoptions['provider']['type']       = PARAM_TEXT;
        $repeatoptions['active']['type']         = PARAM_INT;
        $repeatoptions['filterable']['type']     = PARAM_INT;
        $repeatoptions['intersectable']['type']  = PARAM_INT;
        $repeatoptions['grouped']['type']        = PARAM_INT;
        $repeatoptions['uisort']['type']         = PARAM_INT;
        $repeatoptions['roletypes']['type']      = PARAM_TEXT;
        $repeatoptions['scope']['type']          = PARAM_TEXT;
        $repeatoptions['description']['type']    = PARAM_TEXT;
        $repeatoptions['itemsoverride']['type']  = PARAM_TEXT;
        $repeatoptions['groupdelimiter']['type'] = PARAM_TEXT;
        $repeatoptions['excludecodes']['type']  = PARAM_TEXT;

        // Rules
        //$repeatoptions['type']['rule']  = array(get_string('required'), 'required', null, 'server');
        $repeatoptions['uisort']['rule']  = array(get_string('err_numeric', 'form'),
            'numeric', null, 'client');

        $this->repeat_elements($repeatarray, $repeatno, $repeatoptions, 'audiencetype_repeats', 'audiencetype_add_fields',
            1, '+ add new', true);

        $this->add_action_buttons(false);

    }


}