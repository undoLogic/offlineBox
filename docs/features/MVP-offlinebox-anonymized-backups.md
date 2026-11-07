# OfflineBox - Anonymized Backups + Download Buttons (MVP)

**Status:** draft for review, 2026-11-07. Nothing is built yet. Questions marked **[DECIDE]** need an answer before programming starts. Each one has a recommendation.

This is MVP work (AGENTS.md "MVP Work"). No automated tests are created. `## Testing` lists what to check by hand, and nothing is ticked until it has been run.

## Goal

Stop working on real customer data on the dev machine. Each backup run makes two dumps of every database in `OfflineBoxCommand::CONNECTIONS`:

1. **Full backup:** unchanged. Used for disaster recovery, with history, as today.
2. **Anonymized backup:** the same structure and row counts, but real data only in whitelisted columns. Every other column holds fake values. No history: only the latest is kept.

Daily dev work in Docker uses the anonymized dump. The full dump is used only to check that backups work.

## Scope (MVP)

- Anonymized dump made by the existing cron command, straight after the full backup
- Per-connection whitelist in `OfflineBoxCommand::CONNECTIONS`, next to `exclude`
- New bucket folder for anonymized dumps (latest only)
- Two download buttons per database on the existing admin page (`/admin/offline-box`)
- Admin page and `--check` show which columns are kept and which are faked

Out of scope for now:

- Pull script (bash / PowerShell) and a dev-only S3 key
- A separate bucket for anonymized dumps (only needed once a dev key exists)
- Public service and heartbeat product (Phase 1B in the README roadmap)
- gzip or encryption of dumps (decision 10 stays as it is)

## Existing behaviour that must not change

Names here are the real ones from `OfflineBox.php` and `README.md`:

- `bin/cake offline_box` dumps each connection, uploads it to `backups/<date>_<connection>_<time>.sql` (`KEY_PREFIX`), verifies it, and copies it to `backups_recent/<connection>.sql` (`RECENT_PREFIX`).
- `exclude` keeps working exactly as today and applies to both dumps: an excluded table keeps its structure and comes back empty. An `exclude` name matching no table still fails the run (decision 9).
- Backups only run from the CLI (decision 4). `mysqlDump()`, `upload()` and `copyToRecent()` throw outside the CLI.
- Failures are logged at `error` and go to the command's failure hook (the project's own email or queue).
- The local `.sql` file is always deleted after the run, even on failure.

## Decisions to make before programming

These are places where the original draft conflicts with decisions already in the README or AGENTS.md.

### D1. What the download buttons do **[DECIDE]**

The draft had the buttons run the anonymize export on demand in the web request, sharing one function with the cron push. That breaks decision 4 (no dump ever runs from the web), the class's CLI guard, and the AGENTS.md rule that no controller action shares a method with a cron task.

**Recommended:** the buttons download the **latest file already in the bucket**. They never dump anything.

- Anonymized button: streams `backups_anonymized/<connection>.sql`
- Full button: streams `backups_recent/<connection>.sql`
- There is still only one anonymize code path (the cron one), so the button and the S3 copy can't drift apart. The download is exactly the file cron made.
- Cost: the download is up to a day old. For dev work that is fine.

### D2. How the file reaches the browser **[DECIDE]**

**Recommended:** stream the S3 object through PHP (`GetObject` body stream into the response), chunk by chunk, never loading it into memory. The bucket URL and key never reach the browser, and every download passes through the server, so the download log is complete.

The other option, a short-lived presigned URL (e.g. 60 seconds), is simpler and avoids PHP timeouts on big files, but anyone holding the link in that window can download it.

### D3. Who can download the full data **[DECIDE]**

The page sits under the `Admin` prefix, so every Admin can reach every action in it (AGENTS.md "Prefix access is all-or-nothing").

**Recommended:** the full-data action checks `AppController::isOwner()` itself, in the action, and returns 403 otherwise. The button is also hidden for non-owners, but hiding it is not the protection. The anonymized download is open to anyone who can reach the page.

Confirm that every host project has an Owner role and `isOwner()`. If some don't, say what "owner" means there.

### D4. HTTP method for downloads

A download doesn't change data, so GET would be allowed. But the full download writes a log entry and hands out real customer data, and link previews and scanners follow GET links.

**Recommended:** both buttons are small POST forms with CSRF, and the actions allow POST only.

### D5. Where the download log goes **[DECIDE]**

This module is copied into host projects and has no table of its own. The draft said "the existing logging/comments pattern", which doesn't exist in the module.

**Recommended:** `Log::info()` with scope `offlinebox`, written to `logs/offlinebox.log`, one line per download: user id, user email, date and time, connection, full or anonymized, IP. If a host project already has an audit table, the install step can call that as well.

### D6. Gzip

Decision 10 says dumps stay plain `.sql`. **Recommended:** keep `.sql` for the MVP. The download filename becomes `{database}-anonymized-{YYYY-MM-DD}.sql` and `{database}-full-{YYYY-MM-DD}.sql`, where the date is the dump's date, not today's. gzip can come later for both dumps together.

### D7. Logins in dev **[DECIDE]**

The draft wanted logins to work against the anonymized dump, but also fakes every password and email. Then nobody can log in.

**Recommended:** columns whose name contains `password` get one fixed bcrypt hash of a known dev password (for example `password`), stored as a constant in the class. Emails become `user{id}@example.test`. In dev you log in as `user1@example.test` with the known password. The real hash never leaves the server.

### D8. Foreign keys kept automatically

The draft kept every `*_id` column. Many `*_id` columns are not relationships and hold sensitive external values: `stripe_customer_id`, `google_id`, `session_id`, `paypal_payer_id`.

**Recommended:** keep automatically only

- primary key columns (any name, including composite keys), and
- **integer** columns named `id` or ending in `_id`.

A string `*_id` column is faked unless it is whitelisted.

### D9. Warnings for new tables and columns

The draft warned about any column not in the whitelist. Almost every column is not in the whitelist, so every run would print hundreds of warnings and nobody would read them.

**Recommended:** each table lists both `keep` and `fake`. A column in neither list is still faked (safe), but gets a warning so someone decides on it. A table not listed at all is faked fully and gets one warning for the table. See `## 1. Whitelist config`.

## 1. Whitelist config

Lives in `OfflineBoxCommand::CONNECTIONS`, next to `exclude`, so each database has its own list and the admin page shows the same config cron uses:

```php
public const CONNECTIONS = [
    'default' => [
        'exclude' => "activity_logs\nsome_other_log",
        'anonymize' => [
            'users' => [
                'keep' => ['user_type', 'active', 'created', 'modified'],
                'fake' => ['first_name', 'last_name', 'email', 'password', 'phone'],
            ],
            'products' => [
                'keep' => ['name', 'price', 'active', 'created', 'modified'],
                'fake' => [],
            ],
        ],
    ],
];
```

Leaving `anonymize` out of a connection means no anonymized dump is made for it. It does **not** mean "keep everything".

### Rules

- **Safe by default:** any column not in `keep` is faked. New columns and new tables are protected automatically.
- **No keep-all option.** There is no `*`. Each kept column is named.
- **Kept automatically, never listed:** see D8.
- **All rows kept.** No subsetting. Row counts match the full dump.
- **Structure is complete:** every table, column, index, trigger, view and routine, the same as the full dump.
- **Excluded tables:** structure only, the same as the full dump.
- **Warnings, not errors:** a column in neither `keep` nor `fake`, a table not in `anonymize`, and a `keep` or `fake` name that matches no column. A wrong name is safe (the real column is faked anyway), so it doesn't fail the run, unlike `exclude` (decision 9).
- **Errors:** `anonymize` naming a table that is also in `exclude` (there is nothing to anonymize), and a column listed in both `keep` and `fake`.

## 2. Fake values

Every fake value must be valid for the column, so the dump restores and the app still runs. Rules are checked top to bottom, and the first match wins:

| Column | Fake value |
|---|---|
| Name contains `password` | The fixed dev hash (D7) |
| Name contains `email` | `user{pk}@example.test` |
| Part of a unique index (not the primary key) | Normal fake value for the type with `-{pk}` added, so it stays unique |
| varchar / char / text | `anonymized`, cut to the column length |
| int / decimal / float | `0` |
| date | `2000-01-01` |
| datetime / timestamp | `2000-01-01 00:00:00` |
| time | `00:00:00` |
| year | `2000` |
| tinyint(1) / boolean | `0` |
| enum | First allowed value |
| set | Empty set `''` |
| json | `{}` |
| blob / binary | Empty |
| Nullable, and the real value is NULL | `NULL` (keeps "is set / not set" logic working) |

`{pk}` is the row's primary key. For a composite key, the parts are joined with `-`.

Passwords, tokens and API keys must never be put in `keep`.

Known limits, accepted for the MVP:

- A unique index on an int column: the fake value is the primary key value itself, not `0`.
- A unique varchar too short for `anonymized-{pk}` gets `{pk}` only. If even that doesn't fit, the run fails for that connection and says which column.
- Views, triggers and routines are copied as-is. If one of their bodies contains literal customer data, it stays in the anonymized dump.

## 3. How the anonymized dump is made

1. **Structure:** `mysqldump --no-data --routines` with the existing dump flags, for every table. This gives the same schema, triggers, views and routines as the full dump.
2. **Data:** for each non-excluded table, PHP reads the rows with an unbuffered `SELECT`, in primary-key order, swaps every non-kept column for its fake value, and writes `INSERT` statements in batches to the same file. It never holds a whole table in memory.
3. The file starts with the same `CREATE DATABASE` / `USE` header as the full dump, so it restores the same way.
4. **Reading only:** the anonymizer never writes to the database. No temp tables, no `UPDATE`.

**[DECIDE]** The `SELECT` is not inside the full dump's `--single-transaction` snapshot, so the anonymized dump can differ slightly from the full one if the site is busy. Recommended: accept that. It runs in its own `START TRANSACTION WITH CONSISTENT SNAPSHOT`, so it is consistent with itself.

New public method on `OfflineBox`: `anonymizedDump($connectionName, array $tableConfig)`. It is CLI-only like `mysqlDump()`, and returns the usual `STATUS` / `MSG` array plus `file`, `database` and `warnings`.

## 4. Bucket

- Same bucket, new folder: `backups_anonymized/<connection>.sql` (new constant `ANONYMIZED_PREFIX`). Underscore to match `backups_recent/`.
- **Latest only:** each run overwrites it. No history, no deleting, so the server key still needs no delete permission.
- Uploaded with the existing `upload()`, so it is verified the same way.
- The full backup flow is unchanged.

## 5. Command flow

For each connection, in this order:

1. Full dump, upload, copy to `backups_recent/` (unchanged)
2. Delete the local full `.sql`
3. If the connection has `anonymize`: anonymized dump, upload to `backups_anonymized/`, delete the local file

- The anonymized dump only runs once the full backup has succeeded. A failed anonymized dump never touches the full backup.
- A failed anonymized dump makes the command exit non-zero and goes to the failure hook, as a separate line, e.g. `default (anonymized): FAILED: ...`.
- Warnings are printed and logged at `warning`. They don't change the exit code.
- Doing the full dump first and deleting it before the anonymized one means `TMP` never holds both at once.

## 6. Admin page changes

On `/admin/offline-box`, in each exported database's row:

- **Download anonymized data:** shown when the connection has `anonymize`, with the file's date and size. Greyed out with "None yet" if the file isn't in the bucket.
- **Download full data:** owners only (D3), with the date and size of `backups_recent/<connection>.sql`.

In each database's table list, every non-excluded table shows its columns as **Kept**, **Faked** or **Not decided** (yellow). Not-decided columns are the warnings from `## 1`. `--check` prints the same warnings.

Storage section: red if `backups_anonymized/<connection>.sql` is 2 days old or more, the same rule as `backups/`.

New controller actions, both POST-only and under the `Admin` prefix: `downloadAnonymized($connection)` and `downloadFull($connection)`. The connection name must be a key of `OfflineBoxCommand::CONNECTIONS`, or the action returns 404. It is never used to build a bucket key from anything the user typed.

## 7. Docs to update when this is built

- `README.md` decision 4: add that the admin page can download existing backups from the bucket, but still never dumps.
- `README.md` decision 6: add `backups_anonymized/`.
- `README.md` "Setting up local Docker from a backup": use `backups_anonymized/default.sql`, or the download button.
- `README.md` install step 4: ask the developer for the `anonymize` lists.
- AWS policy in `## Storage setup`: no change needed (`PutObject` already covers it).

## Testing

All scenarios are manual for the MVP. Run them against a test bucket and a local Docker copy, never production.

### Full backup is not affected

**Intent:** live clients rely on the full backup, so the new code must not change it.

**Surfaces:**
- `OfflineBoxCommand::execute()`
- `OfflineBox::mysqlDump()`, `upload()`, `copyToRecent()`

#### Scenarios

- [ ] With no `anonymize` key, a run uploads the same objects as before, and nothing appears under `backups_anonymized/`.
- [ ] With `anonymize` set, the full dump is the same as one made without it, apart from the timestamp lines.
- [ ] A failed anonymized dump still leaves a verified full backup in `backups/` and `backups_recent/`.
- [ ] A failed anonymized dump makes the command exit non-zero and calls the failure hook.
- [ ] No `.sql` file is left in `TMP` after a successful or failed run.

### Anonymized dump is usable

**Intent:** a developer can restore the anonymized dump and run the app against it.

**Surfaces:**
- `OfflineBox::anonymizedDump()`
- `backups_anonymized/<connection>.sql`

#### Scenarios

- [ ] The dump restores into an empty Docker MySQL 5.7 with no errors.
- [ ] Every table, column, index, trigger, view and routine matches the full dump.
- [ ] Row counts match the full dump for every non-excluded table.
- [ ] Excluded tables are present and empty.
- [ ] The app boots against it, and you can log in as `user1@example.test` with the dev password.
- [ ] Relationships still work: pages that join tables show linked records.
- [ ] Unique indexes restore without duplicate-key errors.

### No real data leaks

**Intent:** only whitelisted columns hold real data.

**Surfaces:**
- `anonymize` config in `OfflineBoxCommand::CONNECTIONS`
- Fake-value rules

#### Scenarios

- [ ] A column in `fake` holds only fake values.
- [ ] A column in `keep` holds the real values.
- [ ] A table missing from `anonymize` holds only fake values, apart from its keys, and a warning is logged.
- [ ] A newly added column, in neither list, is faked and a warning is logged.
- [ ] A string `*_id` column (e.g. `stripe_customer_id`) is faked unless kept.
- [ ] No real password hash or email appears anywhere in the dump (search for a known real one).
- [ ] A misspelled name in `keep` gives a warning and the real column is faked.
- [ ] A table in both `exclude` and `anonymize` fails the anonymized dump with a clear message.

### Download buttons

**Intent:** developers can get the anonymized dump easily, and only owners can get real data.

**Surfaces:**
- `Admin/OfflineBoxController::downloadAnonymized()`, `downloadFull()`
- `/admin/offline-box`

#### Scenarios

- [ ] An admin can download the anonymized dump, and it matches the object in the bucket.
- [ ] An owner can download the full dump.
- [ ] A non-owner admin doesn't see the full-data button.
- [ ] A non-owner admin posting straight to the full-data action gets 403.
- [ ] A GET to either download action is refused.
- [ ] An unknown connection name returns 404.
- [ ] A large dump (100 MB or more) downloads without a memory or timeout error.
- [ ] Every download, full or anonymized, writes one log line with who, when, which connection and which type.
- [ ] The page still runs no dump and writes nothing to the bucket.

### Validation page and --check

**Intent:** the setup shows clearly what will be kept and what will be faked.

**Surfaces:**
- `OfflineBox::validate()`
- `bin/cake offline_box --check`

#### Scenarios

- [ ] Each column shows Kept, Faked or Not decided.
- [ ] `--check` prints the same warnings as the page.
- [ ] A missing or 2-day-old anonymized dump shows red in the Storage section.

## Notes

- First real use: undoLogic's own projects only. Smooth it out before offering it to anyone else.
- Later: a dev-only S3 key that can read only `backups_anonymized/`, then a separate anonymized bucket, then a pull script into the Docker SQL folder.
- Long term: OfflineBox as its own service (open code, paid watching and validation). The service only ever holds heartbeats, never customer data or keys.
