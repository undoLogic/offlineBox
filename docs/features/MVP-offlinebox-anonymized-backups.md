# OfflineBox - Anonymized Backups + Download Buttons (MVP)

**Status (2026-11-07):** built. The dump was tested in throwaway containers (MySQL 5.7, PHP 8.1, MariaDB `mysqldump`) with stand-ins for CakePHP and S3. It has not run in a real CakePHP project, against a real bucket, or through the admin page yet. Ticked scenarios below are the ones that were run.

This is MVP work (AGENTS.md "MVP Work"). No automated tests are created. `## Testing` lists what to check by hand, and nothing is ticked until it has been run.

## Goal

Stop working on real customer data on the dev machine. Each backup run makes two dumps of every database in `OfflineBoxCommand::CONNECTIONS`:

1. **Full backup:** unchanged. Used for disaster recovery, with history, as today.
2. **Anonymized backup:** the same structure and row counts, but real data only in whitelisted columns and admin user rows. Every other value is fake. No history: only the latest is kept.

Daily dev work in Docker uses the anonymized dump. The full dump is used only to check that backups work.

## Scope (MVP)

- Anonymized dump made by the existing cron command, straight after the full backup
- Per-connection whitelist in `OfflineBoxCommand::CONNECTIONS`, next to `exclude`
- New bucket folder for anonymized dumps (latest only)
- Two download buttons per database on the existing admin page (`/admin/offline-box`), downloading files already in the bucket
- Admin page and `--check` show which columns are kept

Out of scope for now:

- Download logging (a project adds it later if it wants it, see D5)
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

## Decisions

### D1. Downloads come from the bucket only (decided)

The buttons download the **latest file already in the bucket** and never dump anything. That way a download is always the data that was actually backed up, and the web server never runs a dump (decision 4).

- Anonymized button: `backups_anonymized/<connection>.sql`
- Full button: `backups_recent/<connection>.sql`
- There is only one anonymize code path (cron), so the download and the S3 copy can't drift apart.
- A download can be up to a day old. That is fine for dev work.

### D2. Streamed through PHP (decided)

The S3 object is streamed through PHP (`GetObject` body stream into the response), chunk by chunk, never loaded fully into memory. The bucket URL and key never reach the browser. No presigned URLs.

### D3. Any Admin can download both (decided)

The page is under the `Admin` prefix, and Admin has full access to backups and infrastructure. There is no `isOwner()` check: in these projects Owner is the customer-facing role, below Admin, and has nothing to do with backups. Prefix RBAC is the only role check, as AGENTS.md says.

### D4. GET (decided)

Both downloads are plain GET links. They only read data, which AGENTS.md allows for GET, and they sit behind the logged-in Admin session.

### D5. No download log in the MVP (decided)

Logging is optional and added later by the developer of each project. The download actions have a comment showing where a project can add it, like the failure hook in the command. Nothing is logged by default.

### D6. Plain `.sql` (decided)

Dumps stay plain `.sql` (decision 10). Download filenames: `{database}-anonymized-{YYYY-MM-DD}.sql` and `{database}-full-{YYYY-MM-DD}.sql`, where the date is the dump's date, not today's.

### D7. Admin users are kept real, so you can log in (decided)

Rows for admin users are copied unchanged, so the developer logs in to dev with their own existing account. Every other user row is anonymized. No fixed dev password.

Each project names its users table, the column and the admin values, because they differ per project:

```php
'keepRows' => [
    'users' => ['user_type' => ['admin']],
],
```

- A row is kept whole when the column holds one of the listed values. Every other row in that table follows the normal column rules. If a table lists several columns, a match on any one of them keeps the row.
- `keepRows` is a list of exact values. There is no pattern or "all rows" option.
- This puts the admins' real emails and password hashes in the anonymized dump. That is accepted: the admins are the developers using it.
- A `keepRows` table or column that doesn't exist fails the anonymized dump. A typo here would silently lock you out of dev, so it fails loudly instead.

### D8. Which `*_id` columns are kept automatically (decided)

**Decided (2026-11-07):** the naming convention is now a rule in AGENTS.md ("Column Naming: Internal vs External IDs"), and the integer check stays as a safety net. The recommended rule below is what gets built.

Primary keys and relationship columns are kept automatically, so joins still work. The question is how to tell an internal relationship column from a sensitive external ID such as `stripe_customer_id`, `google_id` or `paypal_payer_id`.

**Your proposal:** from now on, external IDs are named without the underscore (e.g. `stripeCustomerID`), so `_id` always means an internal relationship.

**My view:** adopt the convention, but don't rely on it alone. Also keep the integer check:

- **Existing schemas don't follow it yet.** Today's projects already have columns like `stripe_customer_id`. Until each one is renamed, the name alone would keep them real.
- **A convention fails silently.** One developer, or an AI, adding `paypal_payer_id` next year would put real external IDs into every dev copy, and nothing would warn about it. The anonymizer exists to make that kind of mistake safe.
- **The integer check costs nothing and catches most cases.** Internal keys here are integers. External IDs are almost always strings (`cus_...`, PayPal IDs, UUIDs). The only gap is an external ID stored as a number, such as a Google ID in a `BIGINT`.
- **The convention still helps:** it makes the schema easier to read, and it covers that numeric-external-ID gap once the old names are renamed.

**Recommended rule:**

- Kept automatically: primary key columns (any name, including composite keys), and **integer** columns named `id` or ending in `_id`.
- Faked: everything else, including any string `*_id` column, unless it is in `keep`.
- The `_id` match is lowercase and exact, so `stripeCustomerID` does not match and is faked.

- Existing external `_id` columns are renamed only when asked, as a normal schema change. Until then the integer check keeps string ones faked.

### D9. Only list what is kept (decided)

Each table lists only the columns it keeps. Everything not listed is assumed fake. There is no `fake` list and no "not decided" warning: a new column or table is faked silently, which is the safe outcome.

The one warning left: a name in `keep` that matches no column, because that is almost always a typo, and the column you meant to keep is being faked.

## 1. Whitelist config

Lives in `OfflineBoxCommand::CONNECTIONS`, next to `exclude`, so each database has its own lists and the admin page shows the same config cron uses:

```php
public const CONNECTIONS = [
    'default' => [
        'exclude' => "activity_logs\nsome_other_log",
        'anonymize' => [
            'keep' => [
                'users' => ['user_type', 'active', 'created', 'modified'],
                'products' => ['name', 'price', 'active', 'created', 'modified'],
                // tables not listed: every column faked, apart from keys (D8)
            ],
            'keepRows' => [
                'users' => ['user_type' => ['admin']],
            ],
        ],
    ],
];
```

Leaving `anonymize` out of a connection means no anonymized dump is made for it. It does **not** mean "keep everything".

### Rules

- **Safe by default:** any column not in `keep` is faked. New columns and new tables are protected automatically.
- **No keep-all option.** There is no `*`. Each kept column is named.
- **Kept automatically, never listed:** keys (see D8).
- **Admin rows kept whole:** see D7.
- **All rows kept.** No subsetting. Row counts match the full dump.
- **Structure is complete:** every table, column, index, trigger, view and routine, the same as the full dump.
- **Excluded tables:** structure only, the same as the full dump.
- **Warning, not an error:** a `keep` name that matches no column. The real column is faked anyway, so it is safe.
- **Errors:** a `keep` or `keepRows` table that is also in `exclude` (there is nothing to anonymize), a `keepRows` table or column that doesn't exist (D7), and any key under `anonymize` other than `keep` and `keepRows` (a typo like `keepRow` would otherwise lock you out of dev).

## 2. Fake values

Every fake value must be valid for the column, so the dump restores and the app still runs. Rules are checked top to bottom, and the first match wins. Kept columns and `keepRows` rows skip this table.

| Column | Fake value |
|---|---|
| Nullable, and the real value is NULL | `NULL` (keeps "is set / not set" logic working) |
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

`{pk}` is the row's primary key. For a composite key, the parts are joined with `-`. A table with no primary key uses the row number instead.

A unique number column gets the row number. A unique column of any other type (date, enum and so on) becomes NULL if it is nullable, and otherwise fails the anonymized dump, asking for it to be kept. So does a non-nullable spatial column.

Passwords, tokens and API keys must never be put in `keep`. Faked passwords become `anonymized`, so only `keepRows` users can log in to dev.

Known limits, accepted for the MVP:

- A unique index on an int column: the fake value is the primary key value itself, not `0`.
- A unique varchar too short for `anonymized-{pk}` gets `{pk}` only. If even that doesn't fit, the run fails for that connection and says which column.
- Views, triggers and routines are copied as-is. If one of their bodies contains literal customer data, it stays in the anonymized dump.

## 3. How the anonymized dump is made

1. **Structure:** `mysqldump --no-data --skip-triggers --routines` with the existing dump flags, for the whole database: tables, views and routines.
2. **Data:** for each non-excluded table, PHP reads the rows on its own connection with an unbuffered `SELECT`, in primary-key order, swaps every non-kept value for its fake value, and writes `INSERT` statements in batches (500 rows or 1 MB) to the same file. It never holds a whole table in memory.
3. **Triggers:** `mysqldump --no-data --no-create-info` last, so restoring the data fires no trigger, as with mysqldump's own output.
4. The file starts with the same `CREATE DATABASE` / `USE` header as the full dump, so it restores the same way.
5. **Reading only:** the anonymizer never writes to the database. No temp tables, no `UPDATE`.

Details:

- The data section uses the same settings mysqldump does (`FOREIGN_KEY_CHECKS=0`, `SQL_MODE='NO_AUTO_VALUE_ON_ZERO'`, UTC, `utf8mb4`), and restores them afterwards.
- Generated columns are left out of the `INSERT`s, because MySQL computes them.
- Binary and spatial values are written as hex, and `BIT` as a number, so kept values restore byte for byte.

**Decided (accepted for the MVP):** the `SELECT` is not inside the full dump's `--single-transaction` snapshot, so the anonymized dump can differ slightly from the full one if the site is busy. It runs in its own `START TRANSACTION WITH CONSISTENT SNAPSHOT`, so it is consistent with itself.

The plan (what is kept and how each column is faked) comes from one private `resolveAnonymize()`, used by both the dump and `validate()`, so the admin page and `--check` show exactly what the dump does.

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
- **Download full data:** with the date and size of `backups_recent/<connection>.sql`. Greyed out with "None yet" if it isn't there.

In each database's table list, every non-excluded table shows its kept columns, and which `keepRows` rule applies to it. Everything else is faked and isn't listed. A `keep` name matching no column shows yellow, and `--check` prints the same warning.

Storage section: one row per anonymized connection. Red if `backups_anonymized/<connection>.sql` is 2 days old or more, the same rule as `backups/`. Yellow "None yet" if it isn't there, as on a new install.

New controller actions, GET-only and under the `Admin` prefix: `downloadAnonymized($connection)` and `downloadFull($connection)`. Both call `OfflineBox::download()`, which only reads from the bucket.

- The connection name must be a key of `OfflineBoxCommand::CONNECTIONS`, or the action returns 404. The bucket key is built from the config entry, never from the raw request value.
- No role checks in the actions (D3).
- Each action has a comment marking where a project can add download logging (D5).

## 7. Docs to update when this is built

- `README.md` decision 4: add that the admin page can download existing backups from the bucket, but still never dumps.
- `README.md` decision 6: add `backups_anonymized/`.
- `README.md` "Setting up local Docker from a backup": use `backups_anonymized/default.sql`, or the download button.
- `README.md` install step 4: ask the developer for the `keep` lists and the `keepRows` admin rule.
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

- [x] The dump restores into an empty Docker MySQL 5.7 with no errors.
- [x] Every table, column, index, trigger, view and routine matches the original database.
  - Checked against `information_schema`, not against a full dump file.
- [x] Restoring the dump doesn't fire triggers, and the triggers still work afterwards.
- [x] Generated columns are recomputed on restore.
- [x] Kept values restore byte for byte: BLOB, BIT, DOUBLE, JSON, emoji and `latin1` accents.
- [x] Row counts match the full dump for every non-excluded table.
- [x] Excluded tables are present and empty.
- [ ] The app boots against it, and an admin can log in with their real email and password.
- [x] Relationships still work: pages that join tables show linked records.
  - Checked with a SQL join, not through the app.
- [x] Unique indexes restore without duplicate-key errors.

### No real data leaks

**Intent:** only kept columns and admin rows hold real data.

**Surfaces:**
- `anonymize` config in `OfflineBoxCommand::CONNECTIONS`
- Fake-value rules

#### Scenarios

- [x] A column in `keep` holds the real values.
- [x] A column not in `keep` holds only fake values, apart from NULLs.
- [x] A table missing from `anonymize.keep` holds only fake values, apart from its keys.
- [ ] A newly added column is faked without any config change.
- [x] A string `*_id` column (e.g. `stripe_customer_id`) is faked unless kept.
- [x] An external ID named without `_id` (e.g. `stripeCustomerID`) is faked.
- [x] Admin user rows are real. No non-admin user's real email or password hash appears anywhere in the dump (search for a known real one).
- [x] A misspelled name in `keep` gives a warning, and the real column is faked.
- [x] A misspelled `keepRows` table or column fails the anonymized dump with a clear message.
- [x] A `keep` table that is also in `exclude` fails the anonymized dump with a clear message.

### Download buttons

**Intent:** an admin can get either dump straight from the bucket, without the web server dumping anything.

**Surfaces:**
- `Admin/OfflineBoxController::downloadAnonymized()`, `downloadFull()`
- `/admin/offline-box`

#### Scenarios

- [ ] An admin can download the anonymized dump, and it matches the object in the bucket.
- [ ] An admin can download the full dump, and it matches `backups_recent/<connection>.sql`.
- [ ] A non-admin is redirected away from both download URLs, and a logged-out visitor is sent to login.
- [ ] An unknown connection name returns 404.
- [ ] A missing file in the bucket shows "None yet" on the page, and the URL returns 404 instead of an error page.
- [ ] A large dump (100 MB or more) downloads without a memory or timeout error.
- [ ] The downloaded filename is `{database}-anonymized-{date}.sql` / `{database}-full-{date}.sql`, with the dump's date.
- [ ] The page and the downloads run no dump and write nothing to the bucket.

### Validation page and --check

**Intent:** the setup shows clearly what will be kept.

**Surfaces:**
- `OfflineBox::validate()`
- `bin/cake offline_box --check`

#### Scenarios

- [x] Each table shows its kept columns and any `keepRows` rule.
  - Checked in the data `validate()` returns, not on the rendered page.
- [ ] A `keep` name matching no column shows yellow, and `--check` prints the same warning.
- [ ] A 2-day-old anonymized dump shows red in the Storage section, and a missing one shows yellow "None yet".

## Notes

- First real use: undoLogic's own projects only. Smooth it out before offering it to anyone else.
- Later: a dev-only S3 key that can read only `backups_anonymized/`, then a separate anonymized bucket, then a pull script into the Docker SQL folder.
- Long term: OfflineBox as its own service (open code, paid watching and validation). The service only ever holds heartbeats, never customer data or keys.
