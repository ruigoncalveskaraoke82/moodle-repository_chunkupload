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

namespace repository_chunkupload;

use repository_chunkupload\local\upload_session;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/repository/lib.php');
require_once($CFG->dirroot . '/repository/chunkupload/lib.php');

/**
 * Tests for adding a received file to the draft area.
 *
 * @package    repository_chunkupload
 * @category   test
 * @copyright  2026 Rui Pratas
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \repository_chunkupload
 * @covers     \repository_chunkupload\local\checks
 */
final class repository_test extends \advanced_testcase {
    /**
     * Repository instance of the plugin.
     *
     * @return \repository_chunkupload
     */
    protected function get_repository(): \repository_chunkupload {
        global $DB;
        $id = $DB->get_field_sql("SELECT i.id
                                    FROM {repository_instances} i
                                    JOIN {repository} r ON r.id = i.typeid
                                   WHERE r.type = :type", ['type' => 'chunkupload'], MUST_EXIST);
        return \repository::get_repository_by_id($id, \context_system::instance());
    }

    /**
     * Upload a whole file for the current user.
     *
     * @param string $name
     * @param string $content
     * @return upload_session
     */
    protected function receive(string $name, string $content): upload_session {
        global $USER;
        $session = upload_session::create($USER->id, $name, strlen($content), 1);
        $path = make_request_directory() . '/chunk';
        file_put_contents($path, $content);
        $session->append(0, $path);
        return $session;
    }

    /**
     * A complete upload ends up in the draft area; a second one with the same name gets a free name.
     */
    public function test_upload_to_draft(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $repo = $this->get_repository();
        $itemid = file_get_unused_draft_itemid();

        $session = $this->receive('report.txt', 'hello world');
        $_POST = ['repo_upload_file' => $session->get_uuid(), 'itemid' => $itemid, 'savepath' => '/'];
        $result = $repo->upload('', -1);
        $this->assertSame('report.txt', $result['file']);
        $this->assertNull(upload_session::load($USER->id, $session->get_uuid()), 'Temporary files are removed');

        $session = $this->receive('report.txt', 'second');
        $_POST['repo_upload_file'] = $session->get_uuid();
        $result = $repo->upload('', -1);
        $this->assertSame('report (1).txt', $result['file']);

        $files = get_file_storage()->get_area_files(
            \context_user::instance($USER->id)->id,
            'user',
            'draft',
            $itemid,
            'filename',
            false
        );
        $contents = [];
        foreach ($files as $file) {
            $contents[$file->get_filename()] = $file->get_content();
        }
        $this->assertSame(['report (1).txt' => 'second', 'report.txt' => 'hello world'], $contents);
    }

    /**
     * An unfinished upload cannot be added.
     */
    public function test_upload_incomplete(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $session = upload_session::create($USER->id, 'a.txt', 100, 1);
        $_POST = ['repo_upload_file' => $session->get_uuid(), 'itemid' => file_get_unused_draft_itemid()];
        $this->expectException(\moodle_exception::class);
        $this->get_repository()->upload('', -1);
    }

    /**
     * Moodle size limits are ignored or applied according to the settings.
     */
    public function test_limits(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $repo = $this->get_repository();

        // Ignored (default): a file larger than maxbytes is accepted.
        $session = $this->receive('big.txt', str_repeat('x', 100));
        $_POST = ['repo_upload_file' => $session->get_uuid(), 'itemid' => file_get_unused_draft_itemid()];
        $this->assertSame('big.txt', $repo->upload('', 10)['file']);

        // Applied.
        set_config('ignorelimits', 0, 'chunkupload');
        $session = $this->receive('big2.txt', str_repeat('x', 100));
        $_POST = ['repo_upload_file' => $session->get_uuid(), 'itemid' => file_get_unused_draft_itemid()];
        try {
            $repo->upload('', 10);
            $this->fail('Moodle limit not applied');
        } catch (\file_exception $e) {
            $this->assertSame('maxbytesfile', $e->errorcode);
        }

        // The plugin's own maximum always applies.
        set_config('maxfilesize', 1, 'chunkupload');
        $this->expectException(\file_exception::class);
        local\checks::plugin_size('huge.bin', 2 * 1024 * 1024);
    }

    /**
     * Accepted file types of the file picker.
     */
    public function test_file_type(): void {
        local\checks::file_type('photo.jpg', ['.jpg', '.png']);
        local\checks::file_type('anything.zip', '*');
        $this->expectException(\moodle_exception::class);
        local\checks::file_type('movie.mp4', ['.jpg', '.png']);
    }

    /**
     * "Save as" keeps the extension of the original file.
     */
    public function test_saveas_filename(): void {
        $this->assertSame('new.pdf', \repository_chunkupload::saveas_filename('old.pdf', 'new'));
        $this->assertSame('new.pdf', \repository_chunkupload::saveas_filename('old.pdf', 'new.pdf'));
        $this->assertSame('old.pdf', \repository_chunkupload::saveas_filename('old.pdf', ''));
        $this->assertSame('new', \repository_chunkupload::saveas_filename('README', 'new'));
    }
}
