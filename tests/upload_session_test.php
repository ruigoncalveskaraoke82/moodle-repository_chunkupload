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

/**
 * Tests for the chunked upload sessions.
 *
 * @package    repository_chunkupload
 * @category   test
 * @copyright  2026 Rui Pratas
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \repository_chunkupload\local\upload_session
 */
final class upload_session_test extends \advanced_testcase {
    /**
     * Write a temporary chunk file.
     *
     * @param string $content
     * @return string path
     */
    protected function chunk(string $content): string {
        $path = make_request_directory() . '/chunk';
        file_put_contents($path, $content);
        return $path;
    }

    /**
     * Chunks are added in order and the upload completes.
     */
    public function test_append_in_order(): void {
        $this->resetAfterTest();
        $session = upload_session::create(2, 'big file.bin', 10, 1);
        $this->assertTrue(upload_session::is_valid_uuid($session->get_uuid()));
        $this->assertSame(0, $session->get_received());
        $this->assertSame(4, $session->append(0, $this->chunk('abcd')));
        $this->assertFalse($session->is_complete());
        $this->assertSame(10, $session->append(4, $this->chunk('efghij')));
        $this->assertTrue($session->is_complete());
        $this->assertSame('abcdefghij', file_get_contents($session->get_part_path()));
    }

    /**
     * A chunk at the wrong position is ignored and the current size is returned.
     */
    public function test_append_wrong_offset(): void {
        $this->resetAfterTest();
        $session = upload_session::create(2, 'a.bin', 10, 1);
        $session->append(0, $this->chunk('abcd'));
        // The same chunk again (lost answer): nothing changes.
        $this->assertSame(4, $session->append(0, $this->chunk('abcd')));
        // A chunk from the future: nothing changes.
        $this->assertSame(4, $session->append(8, $this->chunk('ij')));
        $this->assertSame('abcd', file_get_contents($session->get_part_path()));
    }

    /**
     * More data than announced is refused.
     */
    public function test_append_too_much(): void {
        $this->resetAfterTest();
        $session = upload_session::create(2, 'a.bin', 3, 1);
        $this->expectException(\moodle_exception::class);
        $session->append(0, $this->chunk('abcd'));
    }

    /**
     * Uploads belong to their user and can be resumed only for the same file.
     */
    public function test_load_and_resume(): void {
        $this->resetAfterTest();
        $session = upload_session::create(2, 'a.bin', 10, 1);
        $session->append(0, $this->chunk('abc'));
        $uuid = $session->get_uuid();

        $this->assertNull(upload_session::load(3, $uuid));
        $this->assertNull(upload_session::load(2, '../../etc/passwd'));
        $this->assertSame(3, upload_session::load(2, $uuid)->get_received());
        $this->assertNotNull(upload_session::resume(2, $uuid, 'a.bin', 10));
        $this->assertNull(upload_session::resume(2, $uuid, 'b.bin', 10));
        $this->assertNull(upload_session::resume(2, $uuid, 'a.bin', 11));

        $session->delete();
        $this->assertNull(upload_session::load(2, $uuid));
    }

    /**
     * Empty files and invalid names are refused.
     */
    public function test_create_invalid(): void {
        $this->resetAfterTest();
        try {
            upload_session::create(2, 'a.bin', 0, 1);
            $this->fail('Empty file accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('emptyfile', $e->errorcode);
        }
        $this->expectException(\moodle_exception::class);
        upload_session::create(2, '', 5, 1);
    }

    /**
     * A user cannot keep too many unfinished uploads.
     */
    public function test_too_many_uploads(): void {
        $this->resetAfterTest();
        for ($i = 0; $i < upload_session::MAX_OPEN_PER_USER; $i++) {
            upload_session::create(5, "f{$i}.bin", 10, 1);
        }
        $this->expectException(\moodle_exception::class);
        upload_session::create(5, 'last.bin', 10, 1);
    }

    /**
     * Old uploads are deleted by the cleanup, recent ones are kept.
     */
    public function test_delete_expired(): void {
        $this->resetAfterTest();
        $old = upload_session::create(2, 'old.bin', 10, 1);
        $new = upload_session::create(2, 'new.bin', 10, 1);
        $dir = upload_session::user_dir(2);
        foreach (['.part', '.json'] as $ext) {
            touch($dir . '/' . $old->get_uuid() . $ext, time() - 2 * DAYSECS);
        }
        $this->assertSame(1, upload_session::delete_expired(DAYSECS));
        $this->assertNull(upload_session::load(2, $old->get_uuid()));
        $this->assertNotNull(upload_session::load(2, $new->get_uuid()));

        $task = new task\cleanup_uploads();
        $this->assertNotEmpty($task->get_name());
    }
}
