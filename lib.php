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

    $forcedownload = true; // download MUST be forced - security!

    $postid = (int)array_shift($args);

    // Custom email preview file.
    if (!empty($options['preview'])) {
        if ($options['preview'] === 'email') {
            $imageinfo = $file->get_imageinfo();
            if ($imageinfo['width'] > 660) { // Only do if original > 660px.
                $previewfile = local_announcements_get_email_image_preview($file);
                // replace the file with its preview
                if ($previewfile) {
                    $file = $previewfile;
                    // preview images ignore forced download (they are generated by GD and therefore they are considered reasonably safe).
                    $forcedownload = false;
                }
            }
            // Custom preview has been handled, remove from $options so that send_stored_file does not fail.
            unset($options['preview']); 
        }
    }

    // finally send the file
    send_stored_file($file, 0, 0, $forcedownload, $options);
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

function local_announcements_get_email_image_preview($file) {
    global $CFG;
    require_once($CFG->libdir . '/gdlib.php');

    $context = context_system::instance();
    $fs = get_file_storage();
    $path = '/email/';
    $previewfile = $fs->get_file($context->id, 'core', 'preview', 0, $path, $file->get_contenthash());

    if (!$previewfile) {
        // Create the preview.
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

            // Rotate if necessary.
            // Extract directory and filename of the permanent file.
            $dir = str_replace('\\\\', '\\', $CFG->dataroot) . 
            '\filedir\\' . substr($file->get_contenthash(), 0, 2) . 
            '\\' . substr($file->get_contenthash(), 2, 2) . 
            '\\';
            $physicalpath = $dir . $file->get_contenthash();
            local_announcements_image_fix_orientation($original, $physicalpath, $imageinfo);

            // Generate the thumbnail.
            $preview = resize_image_from_image($original, $imageinfo, 660, null);
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
            'filename'  => $file->get_contenthash(), // Use the original files contenthash as the key.
        );

        $imageinfo = getimagesizefromstring($preview);
        if ($imageinfo) {
            $record['mimetype'] = $imageinfo['mime'];
        }
        $previewfile = $fs->create_file_from_string($record, $preview);
    }

    return $previewfile;
}

function local_announcements_image_fix_orientation(&$image, $filename, &$imageinfo) {
    $exif = exif_read_data($filename);

    if (empty($exif['Orientation'])) {
        return;
    }

    if (in_array($exif['Orientation'], [3, 4])) {
        $image = imagerotate($image, 180, 0);
    }
    if (in_array($exif['Orientation'], [5, 6])) {
        $image = imagerotate($image, -90, 0);
        $origw = $imageinfo[0];
        $origh = $imageinfo[1];
        $imageinfo[0] = $origh;
        $imageinfo[1] = $origw;
    }
    if (in_array($exif['Orientation'], [7, 8])) {
        $image = imagerotate($image, 90, 0);
        $origw = $imageinfo[0];
        $origh = $imageinfo[1];
        $imageinfo[0] = $origh;
        $imageinfo[1] = $origw;
    }
    if (in_array($exif['Orientation'], [2, 5, 7, 4])) {
        imageflip($image, IMG_FLIP_HORIZONTAL);
    }
}


