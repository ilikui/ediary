# eDiary

A lightweight personal notes app built with PHP and SQLite for AppServ/Apache.

## Included in this MVP

- First-run administrator account setup
- Login/logout with PHP password hashing and session ID regeneration
- Create, edit, delete, and pin notes
- Search titles, content, and comma-separated tags
- Responsive desktop/mobile layout
- PDO prepared statements and CSRF tokens
- SQLite database outside the public web directory by default

## Requirements

- PHP 8.0+
- PDO_SQLITE enabled
- Apache with PHP support
- Writable parent directory for the SQLite data folder

## AppServ installation

1. Copy this project to D:/AppServ/www/ediary.
2. Enable pdo_sqlite and sqlite3 in the active php.ini, then restart Apache.
3. Ensure the Apache/PHP process can write to D:/AppServ/ediary-data. The app attempts to create it automatically.
4. Open http://localhost/ediary/ and create the first administrator account. Use a unique password of at least 12 characters.
5. Back up D:/AppServ/ediary-data/ediary.sqlite regularly.

The data path is configured near the top of index.php:

    $dbDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'ediary-data';

For a different document-root layout, set an absolute path outside the public web root and grant the web-server account access to that folder.

## Security notes

- Keep the database and backups outside the public web root.
- Use HTTPS when accessing the app over a network.
- The current version is a personal single-user MVP.
- Note bodies are escaped and displayed as plain text; Markdown source is stored but not rendered as HTML.

## Planned improvements

- Markdown editor and safe preview
- Tag filtering and tag management
- Zettelkasten linked notes and knowledge graph
- Daily journal/calendar
- Markdown/JSON export and import
- Backup/restore
- Explicit per-note public publishing
