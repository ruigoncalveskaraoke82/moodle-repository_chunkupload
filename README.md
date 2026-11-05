# Large file upload (repository_chunkupload)

A repository for the Moodle file picker that uploads files in small chunks. With it, very large files
(course backups, videos, datasets) can be uploaded reliably, even when the web server, a proxy or a
service such as Cloudflare only accepts small requests.

## Features

- **Chunked upload**: each file is sent in parts (5 MB by default), so the PHP and proxy request limits no
  longer matter. The chunk size is reduced automatically when PHP accepts less.
- **Automatic retry and resume**: when the connection fails, the upload waits and tries again, and when the
  browser goes offline it continues as soon as the connection is back. An interrupted upload, even after
  reloading the page, continues from where it stopped instead of starting again.
- **Several files at once**, with drag and drop and a progress bar for each file (in file managers that accept
  more than one file).
- **No external libraries and no iframe**: it uses the browser's native upload, so it is light and works on
  sites that block embedded pages.
- **Secure**: every request checks the session key and the `repository/chunkupload:view` capability; files go
  through the antivirus and the accepted file types of the form, like any other upload.
- **Automatic cleanup**: a scheduled task deletes the temporary files of abandoned uploads.

## Requirements

Moodle 4.5 or later.

## Installation

1. Copy this folder to `repository/chunkupload` (in Moodle 5.1 and later: `public/repository/chunkupload`),
   or install the ZIP from *Site administration > Plugins > Install plugins*.
2. Visit *Site administration > Notifications* to complete the installation. The repository is enabled
   automatically.
3. Open the file picker anywhere in Moodle and choose **Large file upload**.

## Settings

*Site administration > Plugins > Repositories > Large file upload*

| Setting | Default | Description |
|---|---|---|
| Chunk size (MB) | 5 | Size of each part. It is reduced automatically to what PHP accepts. |
| Maximum file size (MB) | 0 (no limit) | Largest file accepted by this repository. |
| Ignore Moodle file size limits | Yes | When enabled, the site, course, activity and file area maximum sizes do not apply to this repository; only the maximum file size above does. |
| Delete unfinished uploads after (hours) | 24 | Abandoned uploads are deleted by the scheduled task *Delete abandoned large file uploads*. |

### Who can use it

Use is controlled by the capability `repository/chunkupload:view`. By default it is allowed for managers,
course creators and editing teachers. To change it, edit the role definitions in
*Site administration > Users > Permissions > Define roles*.

## How it works

1. The browser asks `chunk.php` to start an upload (name and size are checked before any data is sent).
2. The file is sent in chunks, in order. The server only accepts the chunk that continues the file, so a
   repeated or lost request never corrupts it.
3. When every chunk has arrived, the file picker asks Moodle to add the file to the draft area: the antivirus,
   the accepted file types and, if enabled, the Moodle size limits are applied, and the temporary file is removed.

Temporary files are kept in `$CFG->tempdir/repository_chunkupload/<userid>/`. In a cluster, `$CFG->tempdir`
must be shared between the web servers (as Moodle already requires).

## License

2026 Rui Pratas

This program is free software: you can redistribute it and/or modify it under the terms of the
GNU General Public License as published by the Free Software Foundation, either version 3 of the
License, or (at your option) any later version. See <https://www.gnu.org/licenses/>.
