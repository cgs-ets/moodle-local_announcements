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
 * Entry point for token-based access to moderation.php.
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// Disable the use of sessions/cookies - we recreate $USER for every call.
define('NO_MOODLE_COOKIES', true);
// Disable debugging for this script.
define('NO_DEBUG_DISPLAY', true);

require_once('../../config.php');
require_once('lib.php');
use \local_announcements\providers\moderation;

$token = required_param('token', PARAM_ALPHANUM);
$action = required_param('action', PARAM_TEXT);
$postid = required_param('postid', PARAM_INT);
$modid = required_param('modid', PARAM_INT);

require_user_key_login('local_announcements', null, $token);

$result = get_string("tokenmod:errorurl", "local_announcements");

switch ($action) {
    case 'approve':
    	if (moderation::mod_approve($postid, $modid)) {
	        $result = get_string("tokenmod:approvesuccess", "local_announcements");
    	} else {
    		$result = get_string("tokenmod:approvefail", "local_announcements");
    	}
    	break;
}

echo $result;
echo '<script type="text/javascript">setTimeout(function(){window.close()}, 5000);</script>';