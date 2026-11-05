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
 * Installation of repository_chunkupload: creates the repository instance with the default settings.
 *
 * @package    repository_chunkupload
 * @copyright  2026 Rui Pratas
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Create the repository type and its single instance.
 *
 * @return bool
 */
function xmldb_repository_chunkupload_install() {
    global $CFG;
    require_once($CFG->dirroot . '/repository/lib.php');
    $options = [
        'chunksize' => \repository_chunkupload\local\settings::DEFAULT_CHUNKSIZE,
        'maxfilesize' => \repository_chunkupload\local\settings::DEFAULT_MAXFILESIZE,
        'ignorelimits' => \repository_chunkupload\local\settings::DEFAULT_IGNORELIMITS,
        'cleanuphours' => \repository_chunkupload\local\settings::DEFAULT_CLEANUPHOURS,
    ];
    $type = new repository_type('chunkupload', $options, true);
    return (bool)$type->create(true);
}
