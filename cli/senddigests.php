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
 * CLI script to process the digest queue and send emails/notifications.
 *
 * @package   local_announcements
 * @copyright 2026 Michael Vangelovski
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

// Now get cli options.
list($options, $unrecognized) = cli_get_params(array('verbose' => false, 'help' => false),
                                               array('v' => 'verbose', 'h' => 'help'));

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    $help = "Send digests to users. Processes the digest queue.

Options:
-v, --verbose         Echo output to stdout
-h, --help            Print out this help

How to run this script:
\$ /path/to/php /path/to/local/announcements/cli/senddigests.php

Examples:
\$ C:/php/php.exe C:/inetpub/wwwroot/moodle/local/announcements/cli/senddigests.php --verbose
\$ sudo -u www-data /usr/bin/php local/announcements/cli/senddigests.php
";

    echo $help;
    die;
}

$logger = new \local_announcements\task\digest_logger($options['verbose']);
$senddigests = new \local_announcements\task\custom_send_digests($logger);
$senddigests->execute();
$logger->close();
