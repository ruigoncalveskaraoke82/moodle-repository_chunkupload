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
 * Repository that uploads files in small chunks, so that very large files can be sent reliably.
 *
 * @package    repository_chunkupload
 * @copyright  2026 Rui Pratas
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/repository/lib.php');

use repository_chunkupload\local\checks;
use repository_chunkupload\local\settings;
use repository_chunkupload\local\upload_session;

/**
 * Chunked upload repository.
 *
 * The browser sends each file in chunks to chunk.php. When a file is complete, the file picker asks
 * repository_ajax.php to "upload" it, and {@see self::upload()} moves it into the user's draft area.
 *
 * @package    repository_chunkupload
 * @copyright  2026 Rui Pratas
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository_chunkupload extends repository {
    /** Identifier of the upload form in the file picker. */
    public const FORM_ID = 'chunkupload';

    /**
     * The repository has no listing: it only shows the upload form.
     *
     * @param string $path
     * @param string $page
     * @return array
     */
    public function get_listing($path = '', $page = '') {
        return [
            'nologin' => true,
            'nosearch' => true,
            'norefresh' => true,
            'list' => [],
            'dynload' => false,
            'upload' => ['label' => get_string('files'), 'id' => self::FORM_ID],
            'allowcaching' => true,
        ];
    }

    /**
     * Files are copied into Moodle.
     *
     * @return int
     */
    public function supported_returntypes() {
        return FILE_INTERNAL;
    }

    /**
     * The repository does not access private data of an external system.
     *
     * @return bool
     */
    public function contains_private_data() {
        return false;
    }

    /**
     * Names of the site-wide settings.
     *
     * @return string[]
     */
    public static function get_type_option_names() {
        return ['pluginname', 'chunksize', 'maxfilesize', 'ignorelimits', 'cleanuphours'];
    }

    /**
     * Site-wide settings form.
     *
     * @param MoodleQuickForm $mform
     * @param string $classname
     */
    public static function type_config_form($mform, $classname = 'repository') {
        parent::type_config_form($mform, $classname);
        $component = 'repository_chunkupload';

        $mform->addElement('text', 'chunksize', get_string('chunksize', $component), ['size' => 6]);
        $mform->setType('chunksize', PARAM_INT);
        $mform->setDefault('chunksize', settings::DEFAULT_CHUNKSIZE);
        $mform->addHelpButton('chunksize', 'chunksize', $component);
        $mform->addElement(
            'static',
            'chunksizeinfo',
            '',
            get_string('chunksizeinfo', $component, display_size(settings::php_request_limit()))
        );

        $mform->addElement('text', 'maxfilesize', get_string('maxfilesize', $component), ['size' => 8]);
        $mform->setType('maxfilesize', PARAM_INT);
        $mform->setDefault('maxfilesize', settings::DEFAULT_MAXFILESIZE);
        $mform->addHelpButton('maxfilesize', 'maxfilesize', $component);

        $mform->addElement('advcheckbox', 'ignorelimits', get_string('ignorelimits', $component));
        $mform->setDefault('ignorelimits', settings::DEFAULT_IGNORELIMITS);
        $mform->addHelpButton('ignorelimits', 'ignorelimits', $component);

        $mform->addElement('text', 'cleanuphours', get_string('cleanuphours', $component), ['size' => 6]);
        $mform->setType('cleanuphours', PARAM_INT);
        $mform->setDefault('cleanuphours', settings::DEFAULT_CLEANUPHOURS);
        $mform->addHelpButton('cleanuphours', 'cleanuphours', $component);

        $url = new moodle_url('/admin/tool/capability/index.php');
        $mform->addElement(
            'static',
            'whocanuse',
            get_string('whocanuse', $component),
            get_string('whocanuse_desc', $component, $url->out(false))
        );
    }

    /**
     * Validate the site-wide settings.
     *
     * @param MoodleQuickForm $mform
     * @param array $data
     * @param array $errors
     * @return array
     */
    public static function type_form_validation($mform, $data, $errors) {
        if (!isset($data['chunksize']) || $data['chunksize'] < 1 || $data['chunksize'] > 1024) {
            $errors['chunksize'] = get_string('errorrange', 'repository_chunkupload', (object)['min' => 1, 'max' => 1024]);
        }
        if (!isset($data['maxfilesize']) || $data['maxfilesize'] < 0) {
            $errors['maxfilesize'] = get_string(
                'errorrange',
                'repository_chunkupload',
                (object)['min' => 0, 'max' => '∞']
            );
        }
        if (!isset($data['cleanuphours']) || $data['cleanuphours'] < 1 || $data['cleanuphours'] > 720) {
            $errors['cleanuphours'] = get_string('errorrange', 'repository_chunkupload', (object)['min' => 1, 'max' => 720]);
        }
        return $errors;
    }

    /**
     * Upload form shown in the file picker; also loads the JavaScript that drives it.
     *
     * @return string HTML
     */
    public function get_upload_template() {
        global $OUTPUT, $PAGE, $USER;
        $PAGE->requires->js_call_amd('repository_chunkupload/uploader', 'init', [[
            'endpoint' => (new moodle_url('/repository/chunkupload/chunk.php'))->out(false),
            'ajaxurl' => (new moodle_url('/repository/repository_ajax.php'))->out(false),
            'chunksize' => settings::chunk_size(),
            'maxfilesize' => settings::max_file_size(),
            'maxfilesizetext' => settings::max_file_size() ? display_size(settings::max_file_size(), 0) : '',
            'formid' => self::FORM_ID,
            'userid' => (int)$USER->id,
        ]]);
        return $OUTPUT->render_from_template('repository_chunkupload/uploadform', []);
    }

    /**
     * Move a completely received file into the user's draft area.
     *
     * Called by repository_ajax.php, with the upload id in the "repo_upload_file" parameter.
     *
     * @param string $saveasfilename optional new name
     * @param int $maxbytes limit already combined with the site and course limits
     * @return array information about the new draft file
     */
    public function upload($saveasfilename, $maxbytes) {
        global $CFG, $USER;

        $uuid = required_param('repo_upload_file', PARAM_ALPHANUMEXT);
        $types = optional_param_array('accepted_types', '*', PARAM_RAW);
        $savepath = optional_param('savepath', '/', PARAM_PATH);
        $itemid = optional_param('itemid', 0, PARAM_INT);
        $license = optional_param('license', $CFG->sitedefaultlicense, PARAM_TEXT);
        $author = optional_param('author', '', PARAM_TEXT);
        $areamaxbytes = optional_param('areamaxbytes', FILE_AREA_MAX_BYTES_UNLIMITED, PARAM_INT);

        \core\session\manager::write_close();

        $session = upload_session::load($USER->id, $uuid);
        if (!$session) {
            throw new moodle_exception('uploadnotfound', 'repository_chunkupload');
        }
        if (!$session->is_complete()) {
            throw new moodle_exception('uploadincomplete', 'repository_chunkupload', '', s($session->get_filename()));
        }

        $path = $session->get_part_path();
        $filename = $session->get_filename();
        if ($saveasfilename !== '' && $saveasfilename !== null) {
            $filename = self::saveas_filename($filename, clean_param($saveasfilename, PARAM_FILE));
        }
        $filesize = $session->get_filesize();

        try {
            \core\antivirus\manager::scan_file($path, $filename, true);
        } catch (moodle_exception $e) {
            // The scanner may already have removed the file.
            $session->delete();
            throw $e;
        }
        checks::plugin_size($filename, $filesize);
        checks::file_type($filename, $types, $path);
        if (!settings::ignore_limits()) {
            checks::moodle_limits($filename, $filesize, $itemid, $maxbytes, $areamaxbytes);
        }
        if (file_is_draft_areas_limit_reached($USER->id)) {
            throw new file_exception('maxdraftitemids');
        }

        $usercontext = context_user::instance($USER->id);
        $record = (object)[
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $itemid,
            'filepath' => $savepath === '/' ? '/' : file_correct_filepath($savepath),
            'filename' => $filename,
            'license' => $license,
            'author' => $author,
            'userid' => $USER->id,
            'source' => self::build_source_field($filename),
        ];
        // Several files may be sent together, so an existing name gets a free variant instead of a question.
        if (repository::draftfile_exists($record->itemid, $record->filepath, $record->filename)) {
            $record->filename = repository::get_unused_filename($record->itemid, $record->filepath, $record->filename);
        }

        $storedfile = get_file_storage()->create_file_from_pathname($record, $path);
        $session->delete();

        \core\event\draft_file_added::create([
            'objectid' => $storedfile->get_id(),
            'context' => $usercontext,
            'other' => [
                'itemid' => $record->itemid,
                'filename' => $record->filename,
                'filesize' => $filesize,
                'filepath' => $record->filepath,
                'contenthash' => $storedfile->get_contenthash(),
            ],
        ])->trigger();

        return [
            'url' => moodle_url::make_draftfile_url($record->itemid, $record->filepath, $record->filename)->out(false),
            'id' => $record->itemid,
            'file' => $record->filename,
        ];
    }

    /**
     * Apply a "save as" name, keeping the original extension when the new name has none.
     *
     * @param string $original
     * @param string $saveas
     * @return string
     */
    public static function saveas_filename(string $original, string $saveas): string {
        if ($saveas === '') {
            return $original;
        }
        $extension = pathinfo($original, PATHINFO_EXTENSION);
        if ($extension === '' || preg_match('/\.' . preg_quote($extension, '/') . '$/i', $saveas)) {
            return $saveas;
        }
        return $saveas . '.' . $extension;
    }
}
