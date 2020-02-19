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
 * Post installation and migration code.
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_local_announcements_install() {
    global $DB;

    // Default audience types.
    $audiencetype = new stdClass();

    $audiencetype->type = 'course';
    $audiencetype->namesingular = 'Course';
    $audiencetype->nameplural = 'Courses';
    $audiencetype->provider = 'mdlcourse';
    $audiencetype->intersectable = 1;
    $audiencetype->grouped = 0;
    $audiencetype->uisort = '1';
    $audiencetype->roletypes = 'Students,Mentors,Staff';
    $audiencetype->scope = '';
    $audiencetype->description = '';
    $audiencetype->itemsoverride = '';
    $audiencetype->groupdelimiter = '';
    $audiencetype->excludecodes = '';
    $DB->insert_record("ann_audience_types", $audiencetype);

    $audiencetype->type = 'group';
    $audiencetype->namesingular = 'Group';
    $audiencetype->nameplural = 'Groups';
    $audiencetype->provider = 'mdlgroup';
    $audiencetype->intersectable = 1;
    $audiencetype->grouped = 1;
    $audiencetype->uisort = '2';
    $audiencetype->roletypes = 'Students,Mentors,Staff';
    $audiencetype->scope = '';
    $audiencetype->description = '';
    $audiencetype->itemsoverride = '';
    $audiencetype->groupdelimiter = '';
    $audiencetype->excludecodes = '';
    $DB->insert_record("ann_audience_types", $audiencetype);

    $audiencetype->type = 'user';
    $audiencetype->namesingular = 'User';
    $audiencetype->nameplural = 'Users';
    $audiencetype->provider = 'mdluser';
    $audiencetype->filterable = 1;
    $audiencetype->intersectable = 0;
    $audiencetype->grouped = 0;
    $audiencetype->uisort = '3';
    $audiencetype->roletypes = 'User,Mentors,Staff';
    $audiencetype->scope = '';
    $audiencetype->description = 'Announcements made directly to individual users';
    $audiencetype->itemsoverride = '';
    $audiencetype->groupdelimiter = '';
    $audiencetype->excludecodes = '';
    $DB->insert_record("ann_audience_types", $audiencetype);


}
