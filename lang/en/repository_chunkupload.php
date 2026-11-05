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
 * Strings for repository_chunkupload (en).
 *
 * @package    repository_chunkupload
 * @copyright  2026 Rui Pratas
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['cannotwrite'] = 'The uploaded data could not be saved on the server.';
$string['chunkmissing'] = 'A part of the file did not reach the server.';
$string['chunksize'] = 'Chunk size (MB)';
$string['chunksize_help'] = 'Files are sent in parts of this size. Smaller parts work better on slow or unstable connections and behind proxies with a low request limit; larger parts are slightly faster on good connections.';
$string['chunksizeinfo'] = 'This server accepts at most {$a} per request; a larger chunk size is reduced automatically.';
$string['chunktoolarge'] = 'A part of the file is larger than allowed.';
$string['chunkupload:view'] = 'Use large file upload in the file picker';
$string['cleanuphours'] = 'Delete unfinished uploads after (hours)';
$string['cleanuphours_help'] = 'Uploads that receive no data for this number of hours are deleted from the server. While an upload is kept, it can be resumed.';
$string['configplugin'] = 'Large file upload settings';
$string['dropfile'] = 'Drag and drop a file here, or click to choose one';
$string['dropfiles'] = 'Drag and drop files here, or click to choose them';
$string['emptychunk'] = 'A part of the file arrived empty.';
$string['emptyfile'] = 'The file "{$a}" is empty.';
$string['errorrange'] = 'Enter a number from {$a->min} to {$a->max}.';
$string['ignorelimits'] = 'Ignore Moodle file size limits';
$string['ignorelimits_help'] = 'When enabled, files uploaded with this repository are not limited by the site, course, activity or file area maximum sizes. Only the maximum file size set here applies. When disabled, the usual Moodle limits apply.';
$string['invalidaction'] = 'Unknown action.';
$string['invalidfilename'] = 'The file name is not valid.';
$string['maxfilesize'] = 'Maximum file size (MB)';
$string['maxfilesize_help'] = 'Largest file that can be uploaded with this repository. 0 means no limit.';
$string['maxsize'] = 'Maximum size: {$a}';
$string['pluginname'] = 'Large file upload';
$string['pluginname_help'] = 'Upload large files in small parts, with progress, automatic retry and resume.';
$string['privacy:metadata'] = 'The Large file upload repository keeps files only while they are being uploaded. They are then stored by the Moodle files subsystem, or deleted if the upload is abandoned.';
$string['remove'] = 'Remove {$a}';
$string['repositorydisabled'] = 'The Large file upload repository is not enabled.';
$string['retry'] = 'Try again';
$string['someerrors'] = 'Some files could not be uploaded. Try again or remove them.';
$string['statusdone'] = 'Done';
$string['statusfailed'] = 'Failed: {$a}';
$string['statusoffline'] = 'No connection. The upload will continue when the connection is back.';
$string['statusprocessing'] = 'Saving…';
$string['statusresuming'] = 'Resuming from {$a}%';
$string['statusretrying'] = 'Connection problem. Trying again in {$a} s…';
$string['statusuploading'] = 'Uploading… {$a}%';
$string['statuswaiting'] = 'Ready to upload';
$string['taskcleanup'] = 'Delete abandoned large file uploads';
$string['toolarge'] = 'The file is larger than the maximum allowed ({$a}).';
$string['toomanyuploads'] = 'You have too many unfinished uploads (maximum {$a}). Finish or remove some of them first.';
$string['uploadbutton'] = 'Upload';
$string['uploadincomplete'] = 'The file "{$a}" has not been completely uploaded yet.';
$string['uploadnotfound'] = 'The upload was not found. It may have expired; please upload the file again.';
$string['whocanuse'] = 'Who can use it';
$string['whocanuse_desc'] = 'Use is controlled by the capability <code>repository/chunkupload:view</code>. By default it is allowed for managers, course creators and editing teachers. Change it in the role definitions; you can check who has it in the <a href="{$a}">capability overview</a>.';
