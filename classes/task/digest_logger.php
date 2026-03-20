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
 * Standalone file-based logger for digest operations.
 *
 * @package   local_announcements
 * @copyright 2026 Michael Vangelovski
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_announcements\task;

defined('MOODLE_INTERNAL') || die();

class digest_logger {

    /** @var resource File handle for the log file. */
    protected $fh = null;

    /** @var bool Whether to also echo to stdout. */
    protected $verbose = false;

    /**
     * Constructor.
     *
     * @param bool $verbose If true, also echo to stdout via mtrace().
     * @param string $prefix Log file prefix (default: 'digest').
     */
    public function __construct($verbose = false, $prefix = 'digest') {
        global $CFG;

        $this->verbose = $verbose;

        $logdir = $CFG->dataroot . '/temp/local_announcements';
        if (!is_dir($logdir)) {
            mkdir($logdir, 0777, true);
        }

        $logfile = $logdir . '/' . $prefix . '_' . date('Ymd') . '.log';
        $this->fh = fopen($logfile, 'a');
    }

    /**
     * Log a message.
     *
     * @param string $message The message to log.
     * @param int $depth Indentation depth.
     */
    public function log($message, $depth = 0) {
        $indent = str_repeat('  ', $depth);
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $indent . $message;

        if ($this->fh) {
            fwrite($this->fh, $line . "\n");
        }

        if ($this->verbose) {
            mtrace($line);
        }
    }

    /**
     * Log the start of an operation.
     *
     * @param string $message The message to log.
     * @param int $depth Indentation depth.
     */
    public function log_start($message, $depth = 0) {
        $this->log($message, $depth);
    }

    /**
     * Log the end of an operation.
     *
     * @param string $message The message to log.
     * @param int $depth Indentation depth.
     */
    public function log_finish($message, $depth = 0) {
        $this->log($message, $depth);
    }

    /**
     * Close the log file handle.
     */
    public function close() {
        if ($this->fh) {
            fclose($this->fh);
            $this->fh = null;
        }
    }
}
