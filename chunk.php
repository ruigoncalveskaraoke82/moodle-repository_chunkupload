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

/**
 * Receives the chunks of the files being uploaded.
 *
 * Actions (all by POST, with sesskey):
 * - start: begin or continue an upload; returns its id and the bytes already received.
 * - chunk: add the next chunk; returns the bytes received.
 * - cancel: delete an unfinished upload.
 *
 * The finished file is added to the draft area by the file picker, through repository_ajax.php.
 *
 * @package    repository_chunkupload
 * @copyright  2026 Rui Pratas
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/repository/lib.php');

use repository_chunkupload\local\settings;
use repository_chunkupload\local\upload_session;

// A request larger than post_max_size arrives without any field.
if (empty($_POST)) {
    throw new moodle_exception('errorpostmaxsize', 'repository');
}

$action = required_param('action', PARAM_ALPHA);
$contextid = required_param('contextid', PARAM_INT);

[$context, $course, $cm] = get_context_info_array($contextid);
require_login($course, false, $cm, false, true);
require_sesskey();
$PAGE->set_context($context);

if (!settings::is_enabled()) {
    throw new moodle_exception('repositorydisabled', 'repository_chunkupload');
}
require_capability('repository/chunkupload:view', $context);

// Chunks of the same user must not wait for each other on the session lock.
\core\session\manager::write_close();
core_php_time_limit::raise();

$result = [];
switch ($action) {
    case 'start':
        $filename = required_param('filename', PARAM_FILE);
        $filesize = required_param('filesize', PARAM_INT);
        $resume = optional_param('resume', '', PARAM_ALPHANUMEXT);
        $itemid = optional_param('itemid', 0, PARAM_INT);
        $maxbytes = optional_param('maxbytes', -1, PARAM_INT);
        $areamaxbytes = optional_param('areamaxbytes', FILE_AREA_MAX_BYTES_UNLIMITED, PARAM_INT);
        $types = optional_param_array('accepted_types', '*', PARAM_RAW);

        \repository_chunkupload\local\checks::before_upload(
            $context,
            $course,
            $filename,
            $filesize,
            $types,
            $itemid,
            $maxbytes,
            $areamaxbytes
        );

        $session = $resume ? upload_session::resume($USER->id, $resume, $filename, $filesize) : null;
        if (!$session) {
            $session = upload_session::create($USER->id, $filename, $filesize, $context->id);
        }
        $result = ['uuid' => $session->get_uuid(), 'received' => $session->get_received()];
        break;

    case 'chunk':
        $uuid = required_param('uuid', PARAM_ALPHANUMEXT);
        $offset = required_param('offset', PARAM_INT);
        $session = upload_session::load($USER->id, $uuid);
        if (!$session) {
            throw new moodle_exception('uploadnotfound', 'repository_chunkupload');
        }
        if (empty($_FILES['chunk']) || !empty($_FILES['chunk']['error']) || !is_uploaded_file($_FILES['chunk']['tmp_name'])) {
            throw new moodle_exception('chunkmissing', 'repository_chunkupload');
        }
        if ($_FILES['chunk']['size'] > settings::chunk_size()) {
            throw new moodle_exception('chunktoolarge', 'repository_chunkupload');
        }
        $result = ['received' => $session->append($offset, $_FILES['chunk']['tmp_name'])];
        break;

    case 'cancel':
        $uuid = required_param('uuid', PARAM_ALPHANUMEXT);
        $session = upload_session::load($USER->id, $uuid);
        if ($session) {
            $session->delete();
        }
        $result = ['cancelled' => true];
        break;

    default:
        throw new moodle_exception('invalidaction', 'repository_chunkupload');
}

echo $OUTPUT->header();
echo json_encode($result);
