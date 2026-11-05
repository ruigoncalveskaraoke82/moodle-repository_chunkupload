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

use moodle_exception;

/**
 * One file being uploaded in chunks.
 *
 * Every upload is kept in the temporary directory of the user who started it, as two files:
 * "<uuid>.part" with the bytes received so far and "<uuid>.json" with the file name and the expected size.
 * Chunks are only accepted in order, so the part file never has gaps.
 *
 * @package    repository_chunkupload
 * @copyright  2026 Rui Pratas
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upload_session {
    /** Directory inside $CFG->tempdir. */
    public const TEMP_DIR = 'repository_chunkupload';

    /** Maximum number of unfinished uploads a user can have at the same time. */
    public const MAX_OPEN_PER_USER = 20;

    /** @var string Upload identifier. */
    protected $uuid;

    /** @var int Owner of the upload. */
    protected $userid;

    /** @var \stdClass Metadata: filename, filesize, contextid, timecreated. */
    protected $meta;

    /**
     * Constructor, use {@see create()} or {@see load()}.
     *
     * @param int $userid
     * @param string $uuid
     * @param \stdClass $meta
     */
    protected function __construct(int $userid, string $uuid, \stdClass $meta) {
        $this->userid = $userid;
        $this->uuid = $uuid;
        $this->meta = $meta;
    }

    /**
     * Whether a string is a valid upload identifier.
     *
     * @param string $uuid
     * @return bool
     */
    public static function is_valid_uuid(string $uuid): bool {
        return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid);
    }

    /**
     * Temporary directory of a user, created when needed.
     *
     * @param int $userid
     * @return string
     */
    public static function user_dir(int $userid): string {
        return make_temp_directory(self::TEMP_DIR . '/' . $userid);
    }

    /**
     * Start a new upload.
     *
     * @param int $userid
     * @param string $filename
     * @param int $filesize expected size in bytes
     * @param int $contextid context where the upload was started
     * @return self
     */
    public static function create(int $userid, string $filename, int $filesize, int $contextid): self {
        $filename = clean_param($filename, PARAM_FILE);
        if ($filename === '') {
            throw new moodle_exception('invalidfilename', 'repository_chunkupload');
        }
        if ($filesize <= 0) {
            throw new moodle_exception('emptyfile', 'repository_chunkupload', '', s($filename));
        }
        $dir = self::user_dir($userid);
        if (count(glob($dir . '/*.json') ?: []) >= self::MAX_OPEN_PER_USER) {
            throw new moodle_exception('toomanyuploads', 'repository_chunkupload', '', self::MAX_OPEN_PER_USER);
        }
        $uuid = \core\uuid::generate();
        $meta = (object)[
            'filename' => $filename,
            'filesize' => $filesize,
            'contextid' => $contextid,
            'timecreated' => time(),
        ];
        $session = new self($userid, $uuid, $meta);
        if (
            file_put_contents($session->get_part_path(), '') === false ||
                file_put_contents($session->get_meta_path(), json_encode($meta)) === false
        ) {
            $session->delete();
            throw new moodle_exception('cannotwrite', 'repository_chunkupload');
        }
        return $session;
    }

    /**
     * Load an upload of a user.
     *
     * @param int $userid
     * @param string $uuid
     * @return self|null null when it does not exist (or was cleaned up)
     */
    public static function load(int $userid, string $uuid): ?self {
        if (!self::is_valid_uuid($uuid)) {
            return null;
        }
        $dir = self::user_dir($userid);
        $metapath = $dir . '/' . $uuid . '.json';
        if (!is_file($metapath) || !is_file($dir . '/' . $uuid . '.part')) {
            return null;
        }
        $meta = json_decode((string)file_get_contents($metapath));
        if (!is_object($meta) || !isset($meta->filename, $meta->filesize)) {
            return null;
        }
        $meta->filesize = (int)$meta->filesize;
        $meta->contextid = (int)($meta->contextid ?? 0);
        return new self($userid, $uuid, $meta);
    }

    /**
     * Load an unfinished upload of the same file, to continue it.
     *
     * @param int $userid
     * @param string $uuid
     * @param string $filename
     * @param int $filesize
     * @return self|null
     */
    public static function resume(int $userid, string $uuid, string $filename, int $filesize): ?self {
        $session = self::load($userid, $uuid);
        if (
            !$session || $session->get_filename() !== clean_param($filename, PARAM_FILE)
                || $session->get_filesize() !== $filesize || $session->get_received() > $filesize
        ) {
            return null;
        }
        return $session;
    }

    /**
     * Upload identifier.
     *
     * @return string
     */
    public function get_uuid(): string {
        return $this->uuid;
    }

    /**
     * Original (cleaned) file name.
     *
     * @return string
     */
    public function get_filename(): string {
        return $this->meta->filename;
    }

    /**
     * Expected size in bytes.
     *
     * @return int
     */
    public function get_filesize(): int {
        return $this->meta->filesize;
    }

    /**
     * Path of the file with the received bytes.
     *
     * @return string
     */
    public function get_part_path(): string {
        return self::user_dir($this->userid) . '/' . $this->uuid . '.part';
    }

    /**
     * Path of the metadata file.
     *
     * @return string
     */
    protected function get_meta_path(): string {
        return self::user_dir($this->userid) . '/' . $this->uuid . '.json';
    }

    /**
     * Number of bytes received so far.
     *
     * @return int
     */
    public function get_received(): int {
        clearstatcache(true, $this->get_part_path());
        $size = @filesize($this->get_part_path());
        return $size === false ? 0 : (int)$size;
    }

    /**
     * Whether every byte has been received.
     *
     * @return bool
     */
    public function is_complete(): bool {
        return $this->get_received() === $this->get_filesize();
    }

    /**
     * Add a chunk at the given position.
     *
     * When the position does not match the bytes already received (for example, the answer to an earlier
     * chunk was lost), nothing is written and the caller gets the current size so that it can continue from there.
     *
     * @param int $offset position of the first byte of the chunk
     * @param string $chunkpath file with the chunk
     * @return int bytes received after this call
     */
    public function append(int $offset, string $chunkpath): int {
        $length = (int)filesize($chunkpath);
        if ($length <= 0) {
            throw new moodle_exception('emptychunk', 'repository_chunkupload');
        }
        $handle = fopen($this->get_part_path(), 'ab');
        if (!$handle) {
            throw new moodle_exception('cannotwrite', 'repository_chunkupload');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new moodle_exception('cannotwrite', 'repository_chunkupload');
            }
            $received = (int)fstat($handle)['size'];
            if ($offset !== $received) {
                return $received;
            }
            if ($received + $length > $this->get_filesize()) {
                throw new moodle_exception('chunktoolarge', 'repository_chunkupload');
            }
            $source = fopen($chunkpath, 'rb');
            if (!$source) {
                throw new moodle_exception('cannotwrite', 'repository_chunkupload');
            }
            $copied = stream_copy_to_stream($source, $handle);
            fclose($source);
            fflush($handle);
            if ($copied !== $length) {
                // Never keep a half written chunk: go back to the last good position.
                ftruncate($handle, $received);
                throw new moodle_exception('cannotwrite', 'repository_chunkupload');
            }
            touch($this->get_meta_path());
            return $received + $length;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Delete the upload and its temporary files.
     */
    public function delete(): void {
        @unlink($this->get_part_path());
        @unlink($this->get_meta_path());
    }

    /**
     * Delete the uploads that have not received data for a while.
     *
     * @param int $maxage seconds without activity
     * @return int number of uploads deleted
     */
    public static function delete_expired(int $maxage): int {
        global $CFG;
        $base = $CFG->tempdir . '/' . self::TEMP_DIR;
        if (!is_dir($base)) {
            return 0;
        }
        $limit = time() - $maxage;
        $deleted = 0;
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $stems = [];
            foreach (glob($dir . '/*.{json,part}', GLOB_BRACE) ?: [] as $file) {
                $stems[pathinfo($file, PATHINFO_FILENAME)][] = $file;
            }
            foreach ($stems as $files) {
                $lastactivity = max(array_map(fn($f) => (int)@filemtime($f), $files));
                if ($lastactivity < $limit || count($files) === 1 && $lastactivity < time() - HOURSECS) {
                    foreach ($files as $file) {
                        @unlink($file);
                    }
                    $deleted++;
                }
            }
            @rmdir($dir); // Only succeeds when the directory is empty.
        }
        return $deleted;
    }
}
