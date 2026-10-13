# eDiary

A lightweight personal knowledge base built with PHP 8.0+ and SQLite for AppServ/Apache.

## Features

- First-run administrator setup and login/logout
- Create, edit, delete, search, and pin notes
- Markdown editing with live preview and safe server-side rendering
- Note types: regular note, Zettelkasten card, and journal entry
- Tags with filtering, date filtering, type filtering, and a monthly calendar
- Wiki links in the form [[Note title]] and automatically derived backlinks
- Explicit per-note public publishing with a standalone reading page
- JSON export/import and SQLite online backup (requires the PHP SQLite3 extension)
- Responsive desktop/mobile UI
- PDO prepared statements, password hashing, CSRF protection, and HTML escaping
- SQLite database and backups outside the public web root by default

## Requirements

- PHP 8.0+
- PDO_SQLITE enabled
- SQLite3 enabled for the online database-backup button
- Apache with PHP support
- Writable parent directory for the SQLite data folder
- mbstring is recommended for improved Unicode title handling, but basic fallback behavior is provided

## Install on AppServ

1. Place this repository at D:\AppServ\www\ediary.
2. Open the active php.ini. Enable pdo_sqlite and sqlite3 (the latter is needed for online backup). If you change extensions, restart Apache.
3. Make sure the Apache/PHP process can create and write to D:\AppServ\ediary-data.
4. Visit http://localhost/ediary/ and create your administrator account. Choose a unique password of at least 12 characters.
5. To publish a note, edit it and tick 公开发布. The public page is reachable by the generated link. Unchecked notes remain private.
6. Back up D:\AppServ\ediary-data\ediary.sqlite and the backups directory regularly.

## Data path

The default path in index.php is:

    $dbDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'ediary-data';

For D:\AppServ\www\ediary\index.php, that resolves to D:\AppServ\ediary-data, outside the public document root. If your folder layout differs, set an absolute data path outside the public web root and grant the web-server process access.

The app migrates the existing notes table in place by adding new columns only when they are missing. Existing note rows, IDs, titles, content, tags, pin state, and timestamps are retained.

## Markdown and wiki links

Supported Markdown subset includes headings, paragraphs, bold, italic, strike-through, inline code, fenced code blocks, unordered lists, blockquotes, and HTTP(S) links. Raw HTML is escaped before rendering. This is intentionally a lightweight built-in renderer; it is not a full CommonMark implementation.

Link notes by writing:

    [[Note title]]

Clicking the link searches your private workspace for an exact title. Backlinks are derived by scanning other notes for the matching wiki-link text, so no separate link table is required.

## Import/export and backup

- Export JSON downloads the current account's notes.
- Import JSON accepts an eDiary JSON export, inserts up to 1,000 notes per import, and marks all imported notes private by default to prevent accidental publication. Existing notes are not overwritten.
- Create database backup uses SQLite3::backup() and stores a timestamped backup under D:\AppServ\ediary-data\backups. The backup directory is outside the web root.

Do not upload or commit your SQLite database or backup files to a public Git repository.

## Publishing and privacy

- A note is private unless 公开发布 is explicitly selected.
- Public routes query only rows with is_public = 1.
- Imported notes are always private, even if the source JSON includes a public flag.
- Anyone with a published URL can read that note; do not publish sensitive information.
- This is a personal single-user MVP. Use HTTPS before exposing it to the public internet.

## Known limitations / next steps

- Markdown is a safe built-in subset, not full CommonMark.
- Backlinks use exact title matching and are derived at page load.
- Calendar shows the current month; date filtering can select any date.
- JSON import currently inserts new notes rather than updating/merging existing ones.
- Consider adding automated tests, rate limiting, stronger session-cookie settings, and a dedicated publishing index/sitemap before internet-facing deployment.
