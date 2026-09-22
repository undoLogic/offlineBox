# OfflineBox

A self-contained, portable backup utility. It dumps a MySQL database (with
per-database table include/exclude rules) and pushes the dump to
S3-compatible object storage. Designed to be **copied as a single file into
any CakePHP/PHP project** — it has no dependency on any other Util/Table
class, and takes its S3 credentials through its constructor rather than
reading them itself, so each project can wire up secrets however it already
does.

Full design rationale and decision history: `docs/features/MVP-OfflineBox-Backup-Workflow.md`.

## Adding it to a new project

1. Copy `OfflineBox.php` into that project's `src/Util/`.
2. `composer require aws/aws-sdk-php` (its only external dependency besides CakePHP core).
3. Wire up a config array from wherever that project keeps secrets (see below) and call it from a Command/Shell — never from a web-reachable controller action (see "Where to call it from").

No other file needs to travel with it.

## Basic usage

```php
use App\Util\OfflineBox;

$offlineBox = new OfflineBox([
    'spaceKey'    => Configure::read('OfflineBox.spaceKey'),   // or get_cfg_var(), or however this project sources secrets
    'spaceSecret' => Configure::read('OfflineBox.spaceSecret'),
    'spaceRegion' => Configure::read('OfflineBox.spaceRegion'),
    'spaceName'   => Configure::read('OfflineBox.spaceName'),
]);

$dump = $offlineBox->mysqlDump('default', '', [
    'include' => '*',
    'exclude' => '',
]);

if ($dump['STATUS'] === 200) {
    $offlineBox->upload($dump['file'], $dump['pathInfo']['basename']);
}
```

`OfflineBox` never reads secrets itself — the constructor throws
`InvalidArgumentException` immediately if any of `spaceKey` / `spaceSecret` /
`spaceRegion` / `spaceName` is missing or empty, rather than connecting with
partial credentials.

## Table include / exclude

```php
$offlineBox->mysqlDump('default', '', [
    'include' => '*',
    'exclude' => <<<'TABLES'
activity_logs
analytics
TABLES,
]);
```

- `include`/`exclude` accept `*` (wildcard = all tables), a single table
  name, an array, or a newline-separated string — so a table list can be
  pasted directly from phpMyAdmin without manual conversion. Blank lines are
  ignored and duplicates are dropped. Both `\r\n` and `\n` line endings work.
- **`exclude` drops data only.** An excluded table's structure is still
  dumped as an empty table, never removed entirely — so software that
  expects the table to exist keeps working against a restored backup. A
  table listed in both `include` and `exclude` still gets its structure
  dumped, just without rows.
- If include/exclude resolves to zero tables on both sides, `mysqlDump()`
  aborts with `STATUS 500` instead of silently falling back to a
  whole-database dump (`mysqldump` treats "no table names given" as
  "dump everything," which would otherwise be a dangerous footgun for a
  misconfigured exclude list).

## What a dump file looks like

Every dump opens with a `CREATE DATABASE IF NOT EXISTS` / `USE` header
(written directly, not via `mysqldump --databases`, which can't be combined
with an explicit table list), so it restores cleanly against an empty server:

```
mysql < dump.sql
```

Internally, excluded tables are produced by a second `mysqldump --no-data`
pass appended to the same file — the class handles this automatically, it's
not something callers need to think about.

## Credentials never touch the command line

`mysqlDump()` writes a `0600`-permissioned `mysqldump --defaults-extra-file`
temp file (host/user/password) immediately before running the dump, and
deletes it in a `finally` block regardless of success or failure. The
database password is never a shell argument, so it never shows up in the
process list (`ps aux`).

## Public API

| Method | Purpose |
|---|---|
| `__construct(array $config)` | `spaceKey`, `spaceSecret`, `spaceRegion`, `spaceName` — all required. |
| `mysqlDump($connectionName = 'default', $filenamePrefix = '', array $tableConfig = [])` | Dumps one CakePHP connection to a local `.sql` file in `TMP`. Returns `['STATUS', 'MSG', 'file', 'pathInfo']` on success. |
| `upload($filename, $keyName, $contentMD5 = false)` | Uploads a file to S3-compatible storage and verifies it afterward (size + MD5/ETag) before reporting success. |
| `uploadLarge($filePath, $keyName)` | Multipart upload, for dumps too large for a single `putObject()`. |

Every public method returns `['STATUS' => 200|500, 'MSG' => '...']` at minimum.

## Where to call it from

Call `mysqlDump()`/`upload()` from a scheduled Command/Shell — this dumps
and uploads a full production database, so it must run at cron/console
privilege, never be reachable from a web route, and never infer which
database/tables to back up from a request or session. See
`sourceFiles/src/Model/Table/GroupsTable.php::uploadDatabaseBackupToS3()` in
this project for a worked example (multi-database loop, per-database
include/exclude config, called from `sourceFiles/src/Shell/OfflineBoxShell.php`).

## The other half: pulling backups down

`OfflineBox.php` is the producer side only — it uploads to S3-compatible
storage and stops there. Pulling those backups down onto a local/offline
copy, with safe two-gate retention (archive, then delete — never straight
out of the active folder), is a separate Windows PowerShell client:
`offlineBox_win/` at the project root. See `offlineBox_win/README.md` and
`docs/features/MVP-OfflineBox-Backup-Workflow.md` for that side of the
system.

## Local dump file cleanup

**Known gap:** the local `.sql` file written to `TMP` is not currently
deleted after a successful upload — it accumulates on disk over time. See
`docs/features/MVP-OfflineBox-Backup-Workflow.md` for status on this.
