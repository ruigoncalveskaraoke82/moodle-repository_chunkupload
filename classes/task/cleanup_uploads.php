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

namespace repository_chunkupload\task;

use repository_chunkupload\local\settings;
use repository_chunkupload\local\upload_session;

/**
 * Deletes the temporary files of uploads that were abandoned.
 *
 * @package    repository_chunkupload
 * @copyright  2026 Rui Pratas
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_uploads extends \core\task\scheduled_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskcleanup', 'repository_chunkupload');
    }

    /**
     * Delete the expired uploads.
     */
    public function execute() {
        $deleted = upload_session::delete_expired(settings::cleanup_age());
        mtrace("Unfinished uploads deleted: {$deleted}");
    }
}
