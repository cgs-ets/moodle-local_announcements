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
 * Defines the global settings of the block
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

if ($hassiteconfig) {

    $settings = new admin_settingpage('local_announcements', get_string('pluginname', 'local_announcements'));
    $ADMIN->add('localplugins', $settings);

    // Number of announcements per page
    $name = 'local_announcements/perpage';
    $title = get_string('config:perpage', 'local_announcements');
    $description = get_string('config:perpagedesc', 'local_announcements');
    $default = 50;
    $type = PARAM_INT;
    $setting = new admin_setting_configtext($name, $title, $description, $default, $type);
    $settings->add($setting);

    // Maximum upload bytes
    if (isset($CFG->maxbytes)) {
        $maxbytes = 0;
        $name = 'local_announcements/maxbytes';
        $title = get_string('config:maxattachmentsize', 'local_announcements');
        $description = get_string('config:maxattachmentsizedesc', 'local_announcements');
        $default = 512000;
        $options = get_max_upload_sizes($CFG->maxbytes, 0, 0, $maxbytes);
        $setting = new admin_setting_configselect($name, $title, $description, $default, $options);
        $settings->add($setting);
    }

    // Enable/Disable Digest.
    $name = 'local_announcements/globaldisable';
    $title = get_string('config:globaldisable', 'local_announcements');
    $description = get_string('config:globaldisabledesc', 'local_announcements');
    $setting = new admin_setting_configcheckbox($name, $title, $description, 0);
    $settings->add($setting);


    // Default number of editor files allowed per announcement
    $name = 'local_announcements/maxeditorfiles';
    $title = get_string('config:maxeditorfiles', 'local_announcements');
    $description = get_string('config:maxeditorfilesdesc', 'local_announcements');
    $default = 10;
    $type = PARAM_INT;
    $setting = new admin_setting_configtext($name, $title, $description, $default, $type);
    $settings->add($setting);
    
    // Default number of attachments allowed per announcement
    $name = 'local_announcements/maxattachments';
    $title = get_string('config:maxattachments', 'local_announcements');
    $description = get_string('config:maxattachmentsdesc', 'local_announcements');
    $default = 10;
    $type = PARAM_INT;
    $setting = new admin_setting_configtext($name, $title, $description, $default, $type);
    $settings->add($setting);

    // Number of characters to truncate message for short message
    $name = 'local_announcements/shortpost';
    $title = get_string('config:shortpost', 'local_announcements');
    $description = get_string('config:shortpostdesc', 'local_announcements');
    $default = 450;
    $type = PARAM_INT;
    $setting = new admin_setting_configtext($name, $title, $description, $default, $type);
    $settings->add($setting);

    // Enable/Disable Digest.
    $name = 'local_announcements/enablenotify';
    $title = get_string('config:enablenotify', 'local_announcements');
    $setting = new admin_setting_configcheckbox($name, $title, '', 0);
    $settings->add($setting);

    // Enable/Disable Digest.
    $name = 'local_announcements/enabledigest';
    $title = get_string('config:enabledigest', 'local_announcements');
    $setting = new admin_setting_configcheckbox($name, $title, '', 0);
    $settings->add($setting);

    // Digest header image
    $name = 'local_announcements/digestheaderimage';
    $title = get_string('config:digestheaderimage', 'local_announcements');
    $description = get_string('config:digestheaderimagedesc', 'local_announcements');
    $default = '';
    $setting = new admin_setting_configtext($name, $title, $description, $default);
    $settings->add($setting);

    // Digest footer image
    $name = 'local_announcements/digestfooterimage';
    $title = get_string('config:digestfooterimage', 'local_announcements');
    $description = get_string('config:digestfooterimagedesc', 'local_announcements');
    $default = '';
    $setting = new admin_setting_configtext($name, $title, $description, $default);
    $settings->add($setting);

    // Digest footer image link
    $name = 'local_announcements/digestfooterimageurl';
    $title = get_string('config:digestfooterimageurl', 'local_announcements');
    $description = get_string('config:digestfooterimageurldesc', 'local_announcements');
    $default = '';
    $setting = new admin_setting_configtext($name, $title, $description, $default);
    $settings->add($setting);

    // Digest header image
    $name = 'local_announcements/forcesendheaderimage';
    $title = get_string('config:forcesendheaderimage', 'local_announcements');
    $description = get_string('config:forcesendheaderimagedesc', 'local_announcements');
    $default = '';
    $setting = new admin_setting_configtext($name, $title, $description, $default);
    $settings->add($setting);

    // Can poster view all.
    $name = 'local_announcements/showposterallinctx';
    $title = get_string('config:showposterallinctx', 'local_announcements');
    $description = get_string('config:showposterallinctxdesc', 'local_announcements');
    $setting = new admin_setting_configcheckbox($name, $title, $description, 0);
    $settings->add($setting);

}

