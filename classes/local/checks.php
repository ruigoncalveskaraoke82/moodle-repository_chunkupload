<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace repository_chunkupload\local;

use file_exception;
use moodle_exception;

/**
 * Checks done before an upload starts and again when the file is added to the draft area.
 *
 * @package    repository_chunkupload
 * @copyright  2026 Rui Pratas
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class checks {
    /**
     * Everything that can be checked before the first byte is sent.
     *
     * @param \context $context
     * @param \stdClass|null $course
     * @param string $filename
     * @param int $filesize
     * @param array|string $types accepted types of the file picker
     * @param int $itemid draft item id
     * @param int $maxbytes limit asked by the form element (-1 for none)
     * @param int $areamaxbytes limit of the file area (-1 for none)
     */
    public static function before_upload(
        \context $context,
        ?\stdClass $course,
        string $filename,
        int $filesize,
        $types,
        int $itemid,
        int $maxbytes,
        int $areamaxbytes
    ): void {
        global $CFG, $USER;
        self::plugin_size($filename, $filesize);
        self::file_type($filename, $types);
        if (!settings::ignore_limits()) {
            $coursemaxbytes = empty($course->maxbytes) ? 0 : (int)$course->maxbytes;
            $maxbytes = get_user_max_upload_file_size($context, $CFG->maxbytes, $coursemaxbytes, $maxbytes);
            self::moodle_limits($filename, $filesize, $itemid, $maxbytes, $areamaxbytes);
        }
        if (file_is_draft_areas_limit_reached($USER->id)) {
            throw new file_exception('maxdraftitemids');
        }
    }

    /**
     * Maximum file size set in the repository settings.
     *
     * @param string $filename
     * @param int $filesize
     */
    public static function plugin_size(string $filename, int $filesize): void {
        $max = settings::max_file_size();
        if ($max > 0 && $filesize > $max) {
            throw new file_exception('maxbytesfile', (object)['file' => $filename, 'size' => display_size($max, 0)]);
        }
    }

    /**
     * Moodle limits: maximum file size and file area size.
     *
     * @param string $filename
     * @param int $filesize
     * @param int $itemid
     * @param int $maxbytes already combined with the site and course limits (-1 for none)
     * @param int $areamaxbytes
     */
    public static function moodle_limits(
        string $filename,
        int $filesize,
        int $itemid,
        int $maxbytes,
        int $areamaxbytes
    ): void {
        if ($maxbytes > 0 && $filesize > $maxbytes) {
            throw new file_exception('maxbytesfile', (object)['file' => $filename, 'size' => display_size($maxbytes, 0)]);
        }
        if (file_is_draft_area_limit_reached($itemid, $areamaxbytes, $filesize)) {
            throw new file_exception('maxareabytes');
        }
    }

    /**
     * Accepted file types of the file picker.
     *
     * Before the upload only the extension is known; afterwards the content is also used.
     *
     * @param string $filename
     * @param array|string $types
     * @param string|null $path file content, when available
     */
    public static function file_type(string $filename, $types, ?string $path = null): void {
        if ($types === '*' || (is_array($types) && (empty($types) || in_array('*', $types)))) {
            return;
        }
        $mimetypes = array_map(fn($type) => mimeinfo('type', $type), (array)$types);
        $mimetype = $path ? \file_storage::mimetype($path, $filename) : mimeinfo('type', $filename);
        if (!in_array($mimetype, $mimetypes)) {
            throw new moodle_exception(
                'invalidfiletype',
                'repository',
                '',
                get_mimetype_description(['filename' => $filename])
            );
        }
    }
}
