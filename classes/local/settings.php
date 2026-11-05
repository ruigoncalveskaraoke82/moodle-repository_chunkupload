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

/**
 * Access to the repository settings, with safe defaults.
 *
 * The settings are stored by the repository type configuration form, under the plugin name "chunkupload".
 *
 * @package    repository_chunkupload
 * @copyright  2026 Rui Pratas
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settings {
    /** Name under which the repository type stores its configuration. */
    public const CONFIG_PLUGIN = 'chunkupload';

    /** Default chunk size, in megabytes. */
    public const DEFAULT_CHUNKSIZE = 5;

    /** Default maximum file size, in megabytes (0 = no limit). */
    public const DEFAULT_MAXFILESIZE = 0;

    /** Default for ignoring the Moodle file size limits. */
    public const DEFAULT_IGNORELIMITS = 1;

    /** Default number of hours after which unfinished uploads are deleted. */
    public const DEFAULT_CLEANUPHOURS = 24;

    /** Smallest chunk size accepted, in bytes. */
    public const MIN_CHUNK_BYTES = 262144;

    /** Bytes kept free in a request for the other form fields. */
    public const POST_MARGIN_BYTES = 65536;

    /**
     * Read one setting.
     *
     * @param string $name
     * @param int $default
     * @return int
     */
    protected static function get(string $name, int $default): int {
        $value = get_config(self::CONFIG_PLUGIN, $name);
        if ($value === false || $value === '' || $value === null) {
            return $default;
        }
        return (int)$value;
    }

    /**
     * Chunk size in bytes, never larger than what PHP accepts in one request.
     *
     * @return int
     */
    public static function chunk_size(): int {
        $size = max(1, self::get('chunksize', self::DEFAULT_CHUNKSIZE)) * 1024 * 1024;
        return min($size, self::php_request_limit());
    }

    /**
     * The largest chunk PHP accepts in one request, in bytes.
     *
     * @return int
     */
    public static function php_request_limit(): int {
        $limits = [];
        foreach (['upload_max_filesize', 'post_max_size'] as $directive) {
            $bytes = get_real_size(ini_get($directive));
            if ($bytes > 0) {
                $limits[] = $directive === 'post_max_size' ? $bytes - self::POST_MARGIN_BYTES : $bytes;
            }
        }
        $limit = $limits ? min($limits) : PHP_INT_MAX;
        return max(self::MIN_CHUNK_BYTES, $limit);
    }

    /**
     * Maximum size of one file in bytes, or 0 for no limit.
     *
     * @return int
     */
    public static function max_file_size(): int {
        return max(0, self::get('maxfilesize', self::DEFAULT_MAXFILESIZE)) * 1024 * 1024;
    }

    /**
     * Whether the Moodle file size limits (site, course, activity and file area) are ignored.
     *
     * @return bool
     */
    public static function ignore_limits(): bool {
        return (bool)self::get('ignorelimits', self::DEFAULT_IGNORELIMITS);
    }

    /**
     * Age in seconds after which an unfinished upload is deleted.
     *
     * @return int
     */
    public static function cleanup_age(): int {
        return max(1, self::get('cleanuphours', self::DEFAULT_CLEANUPHOURS)) * HOURSECS;
    }

    /**
     * Whether the repository is enabled on the site.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        global $CFG;
        require_once($CFG->dirroot . '/repository/lib.php');
        $type = \repository::get_type_by_typename(self::CONFIG_PLUGIN);
        return $type && $type->get_visible();
    }
}
