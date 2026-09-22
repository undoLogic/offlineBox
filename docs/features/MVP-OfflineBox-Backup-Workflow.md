# Feature: OfflineBox MVP Backup Workflow

**Status:** MVP / Implemented — pending manual verification (no PHP/PowerShell runtime available in the dev environment used to write this; see "Implementation Notes" below)
**Date:** 2026-09-22

## Objective

Create a simple, reusable backup workflow for PHP/CakePHP applications that:

1. Dumps selected database tables.
2. Pushes the backup to S3-compatible object storage.
3. Downloads backups to a Windows computer using a simple local CLI process.
4. Preserves older local backups rather than automatically deleting them.

The MVP should remain deliberately simple. Monitoring, reporting, automated retention, and Linux support can be added later.

> **Review Note (2026-09-22):** This document has been checked against `AGENTS.md` and the existing `sourceFiles/src/Util/OfflineBox.php` / `sourceFiles/src/Util/DigitalOcean.php`. Inline `> Review Note:` blocks below flag discrepancies, overlaps with existing code, and open decisions to confirm before implementation starts. A `## Testing` section has also been added near the end per the AGENTS.md feature-file requirement.

## Implementation Notes (2026-09-22)

Implemented:

- `sourceFiles/src/Util/OfflineBox.php` — rewritten: constructor-injected config (no internal secrets lookup), `mysqlDump()` decomposed into `mysqlDump_resolveConnection()` / `mysqlDump_resolveTables()` / `mysqlDump_parseTableList()` / `mysqlDump_expandWildcardTables()` / `mysqlDump_writeCredentialsFile()` / `mysqlDump_writeDatabaseCreateHeader()` / `mysqlDump_buildCommand()` / `mysqlDump_buildFullPass()` / `mysqlDump_buildStructureOnlyPass()` / `mysqlDump_execute()`, credentials passed to `mysqldump` via a `0600` `--defaults-extra-file` (no more password on the command line), `upload()` now verifies the object after upload (`upload_verify()`), and a new `uploadLarge()` (multipart) was added. `exclude` now drops data only (structure kept, via a second `--no-data` pass). Every dump opens with a `CREATE DATABASE IF NOT EXISTS` / `USE` header, written directly rather than via `mysqldump --databases` (which can't be combined with an explicit table list). No dependency on `DigitalOcean.php` or any other project Util class.
- `sourceFiles/src/Model/Table/GroupsTable.php::uploadDatabaseBackupToS3()` — the one existing caller of `OfflineBox`. Updated to build the credentials array via `get_cfg_var('PROJECT.OfflineBox.*')` and pass real `include`/`exclude` config per database (kept the existing `default`/`rebate` list, `corsi`/`storage` still commented out as before). Decomposed into `_credentials()` / `_databases()` / `_run()` helpers since the method was already over the 45-line pre-commit limit before this change.
- `offlineBox_win/offlineBox.ps1`, `offlineBox_win/config.example.json`, `offlineBox_win/README.md` — new Windows client, following the existing `launchPad_win/` convention (`.ps1` + JSON config loaded from beside the script). Implements the `ArchiveAfter`/`DeleteAfter` two-gate retention model, AWS CLI sync, and fails fast with a clear error + non-zero exit on missing/invalid config. `offlineBox_win/config.json` (the real one, with real credentials) is now gitignored — only `config.example.json` is committed.
- `sourceFiles/src/Util/offlineBox-readme.md` — usage README living next to `OfflineBox.php`, aimed at someone copying the class into a new project: how to add it, basic usage, include/exclude semantics, the `CREATE DATABASE` header, credential handling, public API table, and where to call it from.

Not yet done — needs your input before or during a follow-up pass:

> **Review Note — pre-existing AGENTS.md violation found, not fixed (out of scope for this pass):** While wiring `GroupsTable::uploadDatabaseBackupToS3()`, found it's already reachable **both** from console (`sourceFiles/src/Shell/OfflineBoxShell.php`) **and** from a web route (`Admin/GroupsController::uploadDatabaseBackupToS3()`, linked from a button in `sourceFiles/templates/Admin/Groups/index.php`, "OfflineBox Backup" section). AGENTS.md's non-HTTP entry point rule says cron/console logic must never be reachable from a web route, specifically because prefix RBAC is all-or-nothing — any future role granted the `Admin` prefix would automatically gain the ability to trigger a full database dump + S3 upload via a single web click, without anyone deciding that's fine for that role. This predates this feature and I left it as-is rather than silently removing an existing admin affordance — let me know if you want the web-triggerable action removed/gated as a follow-up.
>
> **Review Note — local dump file is never cleaned up:** `mysqlDump()` writes the `.sql` dump to `TMP` (`sourceFiles/tmp/`), and nothing currently deletes it after a successful `upload()`. It accumulates on disk indefinitely — unencrypted database contents sitting on the web server, growing disk usage and attack surface over time. Not fixed yet; flagged during a Q&A pass, waiting on direction for whether to delete on success only, or always (which would remove the ability to manually retry a failed upload from the local file).

Verification performed: static review of both PHP files for syntax/brace/type correctness (no `php` binary available in this environment to run `php -l` or the test suite). PowerShell script was not executed. See the `## Testing` section for what still needs manual/automated verification.

---

## Architecture

### 1. Website / PHP Utility

The website remains the **backup producer**.

Responsibilities:

- Connect to configured databases.
- Determine which tables should be backed up.
- Create database dumps, including a `CREATE DATABASE IF NOT EXISTS` / `USE` header so the dump restores cleanly against an empty server with a plain `mysql < dump.sql` — no need to pre-create the target database.
- Upload dumps to S3-compatible storage.
- Exit with useful success/error information.

> **Review Note — `mysqldump --databases` doesn't work here:** that flag is `mysqldump`'s normal way to add a `CREATE DATABASE`/`USE` header, but it changes how positional arguments are interpreted — in `--databases` mode, *every* name after it is treated as another database to dump, not as a table to restrict to. Since this feature already restricts to an explicit table list whenever `exclude` is used, combining it with `--databases` would silently try to dump extra "databases" named after our table names. Resolved by writing the header directly (`mysqlDump_writeDatabaseCreateHeader()`) before either `mysqldump` pass runs, decoupling it entirely from table filtering.

The website should **not**:

- Manage long-term retention.
- Delete old backups.
- Know whether an offline/local copy exists.
- Perform monitoring of the local OfflineBox.

The existing implementation uses:

- AWS SDK for PHP
- `S3Client`
- AWS exceptions
- AWS S3 multipart uploader

Although the current storage provider is DigitalOcean Spaces, it is accessed using the S3-compatible AWS client.

> **Decision — no sharing with `DigitalOcean.php`:** `OfflineBox` intentionally does **not** reuse or extend `sourceFiles/src/Util/DigitalOcean.php`, even though the S3 mechanics overlap. Today's goal is a fully **self-contained** class that can be copy/pasted as a drop-in util into other projects without carrying a dependency on this project's other Util classes. This is a deliberate exception to the AGENTS.md "reuse shared foundations" guidance, justified by the portability goal — noting it here so the exception is visible rather than silent.
>
> `OfflineBox` may still be **improved independently**, including adding functionality that `DigitalOcean.php` already has (implemented on its own inside `OfflineBox`, not shared): post-upload verification (size/ETag or MD5 check), multipart upload for large dumps, and any other missing S3/dump functions that make it a more complete standalone tool. Any such additions still follow the AGENTS.md function-size/helper-naming rules below.

---

## PHP Utility Structure

Keep the implementation as a **single, self-contained, portable OfflineBox utility class**.

The goal is a true drop-in file: copy `OfflineBox.php` into any CakePHP/PHP project, wire it up from a Command/Shell or other scheduled server process, and it works — without pulling in other project-specific Util classes (e.g. `DigitalOcean.php`) or bespoke helpers. Its only expected external dependencies are the AWS SDK for PHP, CakePHP core (`ConnectionManager`, `Log`), and whatever secrets mechanism is decided below.

Within that constraint, the class can and should be improved — add any missing functions (verification, retries, better error detail, multipart, etc.) as long as they stay inside `OfflineBox` itself.

Example flow:

```text
Scheduled Command
      |
      v
OfflineBox Utility
      |
      +--> Read database configuration
      |
      +--> Determine included/excluded tables
      |
      +--> Create database dump
      |
      +--> Upload to S3
```

> **Review Note — function size & helper naming:** AGENTS.md requires public methods to stay under ~35 lines (pre-commit hard-blocks `public` methods over 45 lines; `private`/`protected` are exempt), with any decomposition using the `<publicFunctionName>_<helperName>()` naming pattern and private helpers setting class properties that the public method then consumes (avoid chaining return values between helpers). The existing `mysqlDump()` is already ~53 lines — over the hard limit — so it needs splitting as part of this work, e.g. `mysqlDump_buildCommand()` / `mysqlDump_execute()`, plus thin new orchestration-only public methods for the multi-database loop, include/exclude resolution, and upload step, each delegating to `_`-suffixed private helpers.
>
> **Resolved:** `mysqlDump()` is now ~20 lines of orchestration, delegating to 7 `mysqlDump_*` private helpers (each setting/reading instance properties, no return-value chaining). `GroupsTable::uploadDatabaseBackupToS3()` was also over the limit and got the same treatment.

---

## Multiple Database Support

A project may contain multiple database connections.

Each configured database should therefore have its own backup/table configuration.

Conceptually:

```php
$databases = [
    'default' => [
        'include' => '*',
        'exclude' => <<<'TABLES'
lightspeed_products
lightspeed_inventory
lightspeed_prices
TABLES,
    ],

    'another_database' => [
        'include' => '*',
        'exclude' => '',
    ],
];
```

The exact configuration structure can be adjusted during implementation.

---

## Table Include / Exclude Rules

Support both:

- `include`
- `exclude`

### Wildcard

`*` means **all tables**.

Typical use:

```text
include = *
exclude = lightspeed_products
```

`exclude` drops **data only** — the table's structure (its `CREATE TABLE`) is still included in the dump, just empty. This means:

> Back up everything, but `lightspeed_products` comes back as an empty table with the right structure, not a missing one.

This is deliberate: software that expects the table to exist (queries, joins, migrations) keeps working against a restored backup, even though that table's rows weren't backed up. A table is never dropped from the dump entirely just because it's excluded — `exclude` only ever means "skip this table's data."

Exclusions should take precedence over inclusions for **data**. A table listed in both `include` and `exclude` still gets its structure dumped (since it was included), just without data.

> **Review Note — `mysqldump` has no single flag for this:** there's no built-in "structure for these, data for those" option in one pass. Implementing this means two `mysqldump` invocations concatenated into one file: a normal pass for the included/non-excluded tables (structure + data), and a second `--no-data` pass for the excluded tables (structure only), appended to the same output file.
>
> **Resolved:** `mysqlDump_resolveTables()` now sets both `$dumpTablesFull` (structure + data) and `$dumpTablesStructureOnly` (excluded tables — structure only, `array_intersect` of the include universe and the exclude list). `mysqlDump_buildCommand()` chains `mysqlDump_buildFullPass()` (`> file`) and, when there are excluded tables, `mysqlDump_buildStructureOnlyPass()` (`mysqldump --no-data ... >> file`) with `&&`, so a failure in either pass fails the whole dump. If include/exclude resolves to genuinely nothing on both sides, `mysqlDump()` still aborts with `STATUS 500` rather than falling back to a whole-database dump.

### Multiline Table Lists

Both `include` and `exclude` should support newline-separated table names.

This is intentional so table names can be copied directly from phpMyAdmin and pasted into the configuration without manually converting them into PHP arrays.

Example:

```php
'exclude' => <<<'TABLES'
lightspeed_products
lightspeed_inventory
lightspeed_transactions
lightspeed_import_rows
TABLES,
```

Parsing should:

1. Split on line endings.
2. Trim whitespace from every line.
3. Remove blank lines.
4. Remove duplicate table names if necessary.

Support Windows and Unix line endings (`\r\n`, `\n`).

> **Review Note — net-new work, and a `mysqldump` constraint:** None of this (multi-database config, include/exclude parsing, `*` wildcard) exists in the current `OfflineBox.php` — today `mysqlDump()` always dumps one entire database for one connection with no table filtering. Also note `mysqldump` has no native "all tables except N" flag: implementing `exclude` means either (a) querying `information_schema.tables` to expand `*` into an explicit table list before dumping, or (b) passing one `--ignore-table=database.table` flag per excluded table (repeatable, but gets verbose for long exclude lists). Worth deciding which approach before implementation — it affects the `mysqlDump_buildCommand()` helper design.
>
> **Resolved — went with (a):** `mysqlDump_resolveTables()` only expands the wildcard via a live `SHOW TABLES` query (simpler than `information_schema` and avoids a second connection/db-name param) when an `exclude` list is actually present; a plain `*` with no excludes skips table resolution entirely and dumps the whole database exactly as before (`$dumpTables = null` — no `--ignore-table` flags at all). If include/exclude ever resolves to zero tables, `mysqlDump()` returns a `STATUS 500` instead of silently falling through to a whole-database dump — `mysqldump` treats "no table args" as "all tables," which would otherwise have been a dangerous silent footgun for a misconfigured exclude list.

---

## Secrets

Secrets must no longer be embedded directly in the OfflineBox utility source code.

`OfflineBox` itself must have **no opinion** on where secrets come from. It receives configuration through its constructor as a plain array; it does not call `get_cfg_var()`, `Configure::read()`, or any other lookup internally.

Typical secrets include:

- S3 endpoint
- S3 region
- S3 bucket
- S3 access key
- S3 secret key

> **Decision — constructor-injected config, not an internal lookup:**
>
> ```php
> new OfflineBox([
>     'spaceKey'    => ...,
>     'spaceSecret' => ...,
>     'spaceRegion' => ...,
>     'spaceName'   => ...,
> ]);
> ```
>
> The Command/Shell that instantiates `OfflineBox` is responsible for sourcing that array however the *host project* already does secrets — this project's Command would use `get_cfg_var('PROJECT.OfflineBox.*')` (the established pattern here, matching `DigitalOcean.php`); a brand-new project could instead drop the keys straight into its own `config/app_local.php` and pull them with `Configure::read('OfflineBox.*')` to get rolling fast, without waiting on any server-level `php.ini` setup.
>
> This is what makes the class genuinely "copy the file and go": it resolves the earlier tension between `get_cfg_var` (travels with the *server*) and `Configure::read()` (travels with the *code* but is CakePHP-specific) by not picking either one — each project supplies the array using whatever it already has. This also fully replaces the two earlier candidate conventions discussed above; neither is used internally by `OfflineBox` anymore.
>
> **Implemented:** `OfflineBox::__construct(array $config)` throws `\InvalidArgumentException` if any of `spaceKey`/`spaceSecret`/`spaceRegion`/`spaceName` is missing/empty — never connects with partial credentials. In this project, `GroupsTable::uploadDatabaseBackupToS3_credentials()` builds that array from `get_cfg_var('PROJECT.OfflineBox.*')` (the one existing call site, reached via `OfflineBoxShell`).

> **Review Note — `mysqldump` credential exposure (security):** `mysqlDump()` currently builds the shell command with `--password=%s` via `sprintf()`/`exec()`. Even with `escapeshellarg()`, the password is visible in the process list (`ps aux`, `/proc/<pid>/cmdline`) for the duration of the dump on any multi-user host. Recommend switching to `mysqldump --defaults-extra-file=<tmp file>` (a `0600`-permissioned temp credentials file written just before the call and deleted immediately after, in a `try`/`finally`) so the password never appears on the command line. Flagging this now since it's a security risk per AGENTS.md review priorities, even though it isn't called out explicitly in the MVP acceptance criteria.
>
> **Resolved:** `mysqlDump_writeCredentialsFile()` now writes a `0600` `--defaults-extra-file` (`[client]` section with user/password/host/port) to `TMP`, immediately before running `mysqldump`; `mysqlDump_execute()` deletes it in a `finally` block regardless of success/failure. The password is never a command-line argument.

> **Review Note — `mysqldump` credential exposure (security):** `mysqlDump()` currently builds the shell command with `--password=%s` via `sprintf()`/`exec()`. Even with `escapeshellarg()`, the password is visible in the process list (`ps aux`, `/proc/<pid>/cmdline`) for the duration of the dump on any multi-user host. Recommend switching to `mysqldump --defaults-extra-file=<tmp file>` (a `0600`-permissioned temp credentials file written just before the call and deleted immediately after, in a `try`/`finally`) so the password never appears on the command line. Flagging this now since it's a security risk per AGENTS.md review priorities, even though it isn't called out explicitly in the MVP acceptance criteria.

---

# Windows OfflineBox CLI

Create an MVP Windows client for pulling backups from S3-compatible storage to a local computer.

## Goal

The Windows implementation should be extremely simple:

```text
PowerShell Script
      |
      +--> Load external configuration
      |
      +--> Connect to S3
      |
      +--> Download/sync backups
      |
      +--> Organize older local files
```

The initial implementation can use the AWS CLI from PowerShell.

---

## External Configuration

Do not hard-code machine-specific configuration into the PowerShell script.

The script should load an external configuration.

Configuration will contain information such as:

- S3 endpoint
- Bucket
- Remote backup path/prefix
- Local backup directory (`Current`)
- Archive directory (`Archive`)
- Authentication/credential reference
- `ArchiveAfter` — relative age at which a backup in `Current` is moved to `Archive` (e.g. `-1 month`)
- `DeleteAfter` — relative age at which a backup **already in `Archive`** is permanently deleted (e.g. `-6 months`)

`DeleteAfter` is optional. If it is not set, the script only archives — it never deletes anything. See "Local Backup Behaviour" below for how these two keys interact.

> **Review Note — relative-date parsing in PowerShell:** `-1 month` / `-6 months` style strings are PHP `strtotime()` syntax, not something PowerShell parses natively. The script needs a small helper to turn these into a cutoff date (e.g. a regex against `-<n> (day|days|month|months|year|years)` feeding `(Get-Date).AddMonths(-$n)` / `.AddDays(-$n)`), or the config format should be pinned to something PowerShell parses more directly (e.g. a plain integer day count). Worth deciding the exact accepted format before implementation so config values are unambiguous between the PHP side (if it ever reads similar config) and the PowerShell side.
>
> **Resolved:** `offlineBox_win/offlineBox.ps1`'s `ConvertTo-CutoffDate` implements exactly this — a regex (`^-(\d+)\s*(day|days|month|months|year|years)$`) feeding `.AddDays()`/`.AddMonths()`/`.AddYears()`, and throws a clear error on any other format. `ArchiveAfter`/`DeleteAfter` accept `-<n> day(s)|month(s)|year(s)`.

For the MVP, credentials may initially be stored locally while the workflow is developed.

A later hardening pass should move credentials into an appropriate Windows secret mechanism such as Windows Credential Manager or another protected credential store. (For now, `offlineBox_win/config.json` is gitignored and only `config.example.json` is committed.)

### Missing Configuration

If the configuration cannot be found or required values are missing:

- Stop immediately.
- Display a clear error.
- Return a non-zero exit code.
- Do not attempt a partial sync.

---

# Local Backup Behaviour

The Windows computer acts as the **OfflineBox**.

It periodically pulls backups from S3 onto local storage.

For the MVP, retention is driven by two config-supplied relative dates, applied as two independent, ordered gates:

1. **Archive gate (`ArchiveAfter`):** any backup file in `Current` older than this age is moved to `Archive`. This is a plain move — nothing is deleted.
2. **Delete gate (`DeleteAfter`):** any backup file **already sitting in `Archive`** older than this age is permanently deleted.

The two gates are deliberately decoupled and sequential:

- A file must already be in `Archive` before it is ever eligible for deletion — a file is never deleted directly out of `Current`, no matter how old it is.
- `Current` is never touched by the delete step, only the archive step.
- If `DeleteAfter` is not configured, nothing is ever deleted — the script behaves exactly like the original archive-only MVP.

Example:

```text
D:\OfflineBox\
    Current\   <- ArchiveAfter moves files out of here
    Archive\   <- DeleteAfter removes files from here only
```

> **Review Note — destructive-operation precondition (AGENTS.md):** AGENTS.md's "Preconditions on the Target" rule (written for DB deletes, but the same spirit applies here) requires any operation that deletes data to declare and enforce a precondition on its target rather than trusting the caller. Here the precondition is structural and double-checked at delete time, not inferred: (a) the file must currently reside under the `Archive` directory — checked by path, not by a remembered list — and (b) its age must exceed `DeleteAfter`. Both conditions are evaluated together immediately before each delete, and `DeleteAfter` must be explicitly present in config for the delete step to run at all (no implicit default). This keeps deletion an explicitly-configured, narrowly-scoped exception rather than something that happens if nobody was paying attention.

This deliberately favors safety over disk-space efficiency: the only backups ever removed are old copies that have already survived one full archive cycle.

---

# Retention Direction

The `ArchiveAfter` / `DeleteAfter` two-gate model above **is** the MVP retention mechanism — simple, config-driven, and file-age-based, with no thinning/consolidation logic.

The longer-term direction this could grow into (not part of this MVP) is a "daily-then-monthly" scheme:

- Keep **daily backups** for approximately one month.
- After the daily period, retain approximately **one backup per month**.
- Monthly historical backups may be retained indefinitely.

Example:

```text
Today
 |
 | Daily
 | Daily
 | Daily
 | ...
 | Daily backups (~30 days)
 |
 +---- Monthly snapshot
 +---- Monthly snapshot
 +---- Monthly snapshot
 +---- ...
```

That scheme requires picking *which* backup in a period to keep (thinning), which the MVP's two-gate model does not attempt — the MVP moves and deletes whole files by age only, never selects "one per month" out of a group. Treat this section as future direction/rationale, not an MVP requirement.

---

# Scheduling

## Server

The PHP backup command should be suitable for execution once per day by the existing server scheduler/cron infrastructure.

> **Review Note — non-HTTP entry point rule:** Per AGENTS.md, cron/console code gets zero role protection and must never infer scope from a session. This backup Command must not share a method with, or be reachable from, any controller action/web route, and the multi-database config (which databases, which include/exclude rules) must be passed to `OfflineBox` explicitly by the Command — not derived from any request/session. The doc's "Conceptually" config-array approach already lines up with this; noting it here as a constraint to keep in mind during implementation, not a change.

## Windows

The PowerShell download script should be suitable for execution once per day through Windows Task Scheduler.

The script itself should not depend on being manually launched.

---

# Future: Backup Verification / Heartbeat

**Not part of this MVP.**

A future OfflineBox web service/dashboard can provide end-to-end verification.

The local CLI could periodically report metadata describing which backups physically exist on the OfflineBox.

Example future flow:

```text
Website
   |
   | creates backup
   v
S3 Storage
   |
   | local sync
   v
OfflineBox PC
   |
   | reports inventory / heartbeat
   v
OfflineBox.com
```

OfflineBox.com could then compare:

- Expected backups
- S3 backups
- Locally confirmed backups
- Last successful local sync
- Last heartbeat

This would allow alerts such as:

- OfflineBox has not checked in.
- Yesterday's backup is missing locally.
- A database has stopped producing backups.
- Local synchronization has failed.

The important distinction is that the system would verify that an **actual offline/local copy exists**, rather than merely assuming a cloud upload equals a successful backup.

---

# Future Enhancements

Out of scope for today's MVP:

- Linux OfflineBox CLI
- macOS client
- OfflineBox.com dashboard
- Local heartbeat/reporting
- Email/SMS alerts
- Daily-then-monthly thinning/consolidation (picking "one backup per month" out of a group)
- Per-database or per-file-type retention policies (MVP uses one global `ArchiveAfter`/`DeleteAfter` pair)
- Multiple storage providers
- Encryption management
- Restore workflows
- Backup integrity checks
- Database dump validation
- Client-facing compliance reporting

---

# MVP Acceptance Criteria

- Existing OfflineBox PHP utility is cleaned up and reusable.
- S3 credentials are removed from the PHP source code.
- Multiple database connections can be configured.
- Tables can be included or excluded per database.
- `*` can represent all tables.
- Multiline table lists can be pasted directly from phpMyAdmin.
- Excluding a table drops its data only — its structure is still dumped as an empty table, never removed entirely, so software depending on the table's existence still works against a restored backup.
- Database dumps continue uploading successfully to DigitalOcean Spaces through the S3-compatible AWS client.
- A Windows PowerShell script can pull backups from S3-compatible storage.
- Windows configuration is external to the PowerShell script.
- Missing/invalid configuration causes a clear failure.
- Older backups in `Current` are moved into `Archive` once past `ArchiveAfter`.
- The Windows process never deletes anything from `Current` — only files already in `Archive` are ever eligible for deletion.
- If `DeleteAfter` is not configured, nothing is ever deleted.
- Files in `Archive` older than `DeleteAfter` are deleted only when both the location (in `Archive`) and age conditions are true.
- PowerShell process can be scheduled through Windows Task Scheduler.

---

## Testing

> **Review Note:** This feature file had no `## Testing` section, which AGENTS.md requires for every feature file. Added below per the required format. Per the MVP testing rule, no automated tests are being scaffolded for this work — scenarios below are for manual verification and stay unchecked until actually executed.

### Table Include / Exclude Resolution

**Intent:** Ensure the configured table list for a database backup matches what the config actually intends, especially when pasted directly from phpMyAdmin.

**Surfaces:**
- `OfflineBox::mysqlDump()` and its config-resolution helper(s)

#### Scenarios

- [ ] `include = *` with an empty `exclude` backs up every table in the database, structure and data.
- [ ] `include = *` with one or more `exclude` entries backs up every table's structure, but an excluded table's data is dropped — the table exists in the restored dump, just empty.
- [ ] An excluded table's `CREATE TABLE` statement appears in the dump file exactly once, with no `INSERT` statements for it.
- [ ] Multiline `include`/`exclude` values (with `\r\n` or `\n` line endings) are split, trimmed, and de-duplicated correctly.
- [ ] Blank lines in a pasted table list are ignored.
- [ ] A table name present in both `include` and `exclude` still gets its structure dumped (it was included), just without data.
- [ ] `include`/`exclude` resolving to zero tables on both sides (nothing to dump at all) aborts with `STATUS 500` rather than silently dumping the whole database.

### Multi-Database Configuration

**Intent:** Ensure each configured database connection is backed up independently with its own include/exclude rules.

**Surfaces:**
- `OfflineBox` database configuration array
- `Cake\Datasource\ConnectionManager::get()`

#### Scenarios

- [ ] Each configured database connection produces its own dump file.
- [ ] A misconfigured/missing connection name fails clearly for that database without aborting backups for other configured databases.

### Secrets Handling

**Intent:** Ensure no S3 credentials are embedded in source and that configuration is read from the agreed SetupCase configuration mechanism.

**Surfaces:**
- `OfflineBox::__construct()`

#### Scenarios

- [ ] No S3 key/secret/region/bucket values are hardcoded in `OfflineBox.php`.
- [ ] Missing/blank secret configuration fails the backup run with a clear error rather than connecting with empty credentials.

### Database Dump & Upload

**Intent:** Ensure dumps are created safely and uploaded successfully, and that credentials are not exposed via the process list.

**Surfaces:**
- `OfflineBox::mysqlDump()`
- `OfflineBox::upload()`

#### Scenarios

- [ ] A successful `mysqldump` produces a file that uploads to DigitalOcean Spaces via the S3-compatible client.
- [ ] A failed `mysqldump` (bad credentials, unreachable host) returns a `STATUS` 500 response and does not attempt an upload.
- [ ] A failed S3 upload returns a `STATUS` 500 response with the AWS error message.
- [ ] The database password does not appear in the process list (`ps aux`) while `mysqldump` is running.
- [ ] The dump file starts with `CREATE DATABASE IF NOT EXISTS` / `USE` for the correct database name, exactly once, regardless of whether tables are filtered.
- [ ] Restoring the dump with `mysql < dump.sql` against a fresh server (no pre-existing database) succeeds without manual setup.

### Windows OfflineBox CLI

**Intent:** Ensure the local pull-down process never loses data and fails loudly on bad configuration.

**Surfaces:**
- PowerShell download/sync script
- External configuration file
- `D:\OfflineBox\Current\`, `D:\OfflineBox\Archive\`

#### Scenarios

- [ ] Missing or incomplete external configuration stops the script immediately with a non-zero exit code and no partial sync.
- [ ] New backups are downloaded into `Current`.
- [ ] The script can be triggered non-interactively, as Windows Task Scheduler would run it.

### Archive & Delete Gates

**Intent:** Ensure retention is strictly two-stage — nothing is deleted directly from `Current`, and deletion only ever touches files already moved into `Archive` and past `DeleteAfter`.

**Surfaces:**
- PowerShell archive/delete step
- `ArchiveAfter` / `DeleteAfter` config keys
- `D:\OfflineBox\Current\`, `D:\OfflineBox\Archive\`

#### Scenarios

- [ ] A backup in `Current` older than `ArchiveAfter` is moved to `Archive`.
- [ ] A backup in `Current` newer than `ArchiveAfter` is left in place.
- [ ] A backup in `Archive` older than `DeleteAfter` is deleted.
- [ ] A backup in `Archive` newer than `DeleteAfter` is left in place.
- [ ] No file in `Current` is ever deleted by the script, regardless of age.
- [ ] When `DeleteAfter` is not configured, the delete step does not run and nothing is deleted anywhere.
- [ ] A file only just moved into `Archive` this run (younger than `DeleteAfter`) is not deleted in the same run.

---

## MVP Principle

**Keep the producer dumb, keep the local copy safe, and only ever delete a backup that has already passed through the archive stage and an explicit age threshold — never straight out of `Current`, and never by default.**
