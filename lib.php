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
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/** Include required files */
require_once($CFG->libdir.'/filelib.php');

/**
 * Serves the plugin attachments.
 *
 * @package  local_announcements
 * @category files
 * @param stdClass $course course object
 * @param stdClass $cm course module object
 * @param stdClass $context context object
 * @param string $filearea file area
 * @param array $args extra arguments
 * @param bool $forcedownload whether or not force download
 * @param array $options additional options affecting the file serving
 * @return bool false if file not found, does not return if found - justsend the file
 */
function local_announcements_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = array()) {
    global $CFG, $DB, $USER;

    if ($context->contextlevel != CONTEXT_SYSTEM) {
        return false;
    }

    $areas = array(
        'attachment' => get_string('postform:areaattachment', 'local_announcements'),
        'announcement' => get_string('postform:areaannouncement', 'local_announcements'),
    );

    // filearea must contain a real area
    if (!isset($areas[$filearea])) {
        return false;
    }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/local_announcements/$filearea/$relativepath";
    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        return false;
    }

    $postid = (int)array_shift($args);

    // Custom email preview file.
    if (!empty($options['preview'])) {
        if ($options['preview'] === 'email') {
            $previewfile = local_announcements_get_email_image_preview($stored_file);
            // replace the file with its preview
            if ($previewfile) {
                $file = $previewfile;
            }
        }
    }

    // finally send the file
    send_stored_file($file, 0, 0, true, $options); // download MUST be forced - security!
}

/**
 * Implements callback user_preferences.
 *
 * Used in {@see core_user::fill_preferences_cache()}
 *
 * @return array
 */
function local_announcements_user_preferences() {
    $preferences = array();
    $preferences['local_announcements_auditingmode'] = array(
        'type' => PARAM_INT,
        'null' => NULL_NOT_ALLOWED,
        'default' => 0,
        'choices' => array(0, 1),
        'permissioncallback' => function($user, $preferencename) {
            global $USER;
            return $user->id == $USER->id;
        }
    );
    return $preferences;
}

function local_announcements_get_email_image_preview($stored_file) {
    $context = context_system::instance();
    $path = '/email/';
    $previewfile = $this->get_file($context->id, 'core', 'preview', 0, $path, $stored_file->get_contenthash());

    if (!$previewfile) {
        // Create the preview.
        $mimetype = $stored_file->get_mimetype();

        if ($mimetype === 'image/gif' or $mimetype === 'image/jpeg' or $mimetype === 'image/png') {
            // make a preview of the image
            $content = $stored_file->get_content();
            // Fetch the image information for this image.
            $imageinfo = @getimagesizefromstring($content);
            if (empty($imageinfo)) {
                return false;
            }

            // Create a new image from the file.
            $original = @imagecreatefromstring($content);

            // Generate the thumbnail.
            $preview =  generate_image_thumbnail_from_image($original, $imageinfo, 700, null); // Max 700px wide, scaled height.

        } else {
            // unable to create the preview of this mimetype yet
            return false;
        }

        if (empty($preview)) {
            return false;
        }

        $record = array(
            'contextid' => $context->id,
            'component' => 'core',
            'filearea'  => 'preview',
            'itemid'    => 0,
            'filepath'  => '/email/',
            'filename'  => $stored_file->get_contenthash(),
        ); // Use the original files contenthash as the key.

        $imageinfo = getimagesizefromstring($data);
        if ($imageinfo) {
            $record['mimetype'] = $imageinfo['mime'];
        }
        $fs = get_file_storage();
        $previewfile = $fs->create_file_from_string($record, $data);
    }

    return $previewfile;
}

/*
function local_announcements_create_email_image_previews($postid) {
    global $CFG;
    require_once($CFG->libdir.'/gdlib.php');

    $context = context_system::instance();
    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'local_announcements', 'attachment', $postid, "filename", false);
    if ($files) {
        foreach ($files as $file) {
            $preview = '';

            $mimetype = $file->get_mimetype();

                if ($mimetype === 'image/gif' or $mimetype === 'image/jpeg' or $mimetype === 'image/png') {
                    // make a preview of the image
                    $content = $file->get_content();
                    // Fetch the image information for this image.
                    $imageinfo = @getimagesizefromstring($content);
                    if (empty($imageinfo)) {
                        return false;
                    }

                    // Create a new image from the file.
                    $original = @imagecreatefromstring($content);

                    // Generate the thumbnail.
                    $preview =  generate_image_thumbnail_from_image($original, $imageinfo, 700, null); // Max 700px wide, scaled height.

                } else {
                    // unable to create the preview of this mimetype yet
                    return false;
                }

                if (empty($preview)) {
                    return false;
                }

                $record = array(
                    'contextid' => $context->id,
                    'component' => 'core',
                    'filearea'  => 'preview',
                    'itemid'    => 0,
                    'filepath'  => '/email/',
                    'filename'  => $file->get_contenthash(),
                ); // Use the original files contenthash as the key.

                $imageinfo = getimagesizefromstring($data);
                if ($imageinfo) {
                    $record['mimetype'] = $imageinfo['mime'];
                }

                return $fs->create_file_from_string($record, $data);

            }
        }
    }
}
*/