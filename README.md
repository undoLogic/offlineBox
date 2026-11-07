# OfflineBox

Public branding: www.OfflineBox.com

**Until your data is physically in your hands, you don't have it.**

OfflineBox is a separate, opt-in module in SetupCase core. Nothing in `sourceFiles/` loads it until a project installs it. It dumps each database connection you list, uploads the dumps to S3-compatible storage, and verifies each upload. A local client can then pull the backups down to a machine in your office, so a real offline copy exists.

Every SetupCase app owns its data, can prove it, and can restore it.

## MVP Status

This is MVP work (AGENTS.md "MVP Work"):

- No automated tests are created or scaffolded.
- `## Testing` lists the expected behaviour to check by hand against a test bucket. Nothing is marked covered until it has been run.
- This README is the feature file. It lives with the module instead of in `docs/features/`, so it travels with the class.

**Status (2026-11-05):** installed and tested on one real project in local Docker, including a real backup to a DigitalOcean Spaces test bucket and a full restore from it. Not yet run on production. Core itself is unchanged: the old OfflineBox code stays in `sourceFiles/` and `codeBlocks/` until the install steps remove it from a project.

Tested on the real project (114 tables, 179 MB, MySQL 5.7, PHP 8.1, MariaDB `mysqldump`):

- `--check`, and a full backup using the multipart upload path, with no files left behind
- Restore onto an empty MySQL 5.7 server with default settings: all 114 tables, with every row count matching and the same database charset
- The page through a real login: the link appears, a non-admin is redirected away, a logged-out visitor goes to login, and no secrets appear

Tested in throwaway containers:

- Exact bytes for `latin1` accents and emoji, and a trigger, view and procedure restored
- Exclude typo, bad connection, wrong bucket, corrupted upload and missing setting all fail loudly, with no files left behind
- `mysqlDump()` and `upload()` refuse to run from a web server

Not tested yet: real DigitalOcean Spaces or AWS, a UNIX socket connection, and PHP 8.4. The `## Testing` boxes stay unticked until they pass against a real bucket.

## Supported for the MVP

| | Version | Notes |
|---|---|---|
| Database server | MySQL 5.7 (first target: 5.7.44 Percona) | Reached over a UNIX socket as `localhost`. Server charset is `latin1`. Docker's `mysql:5.7` is the same major version. |
| PHP | 8.4 on the first target (minimum 8.0) | 8.4.27 is the web version there. CLI PHP must be checked separately, see `## Requirements`. Tested on 8.1 and 8.3. |
| Web server | Apache | Not involved: backups never run through the web. |

Other database servers are not supported or tested in the MVP, including MySQL 8 and MariaDB servers.

## Decisions

### Confirmed (2026-11-05)

- **3. Delete the old version, per project.** Full removal, nothing kept, but only when the AI installs OfflineBox on an actual project (see the install steps below). Core's `sourceFiles/` and `codeBlocks/` are not changed for now. Moving a project from the old class is not drop-in, because the constructor changed.
- **4. CLI only.** All hosts are assumed to run a real CLI cron job on CakePHP. A backup can only run through `bin/cake offline_box`.
    - No controller action, route or `config/cron.php` job runs a backup. `run_all` would dump everything on every call, inside a web request.
    - The class enforces this: `mysqlDump()` and `upload()` throw unless `PHP_SAPI === 'cli'`, so web code can't start a backup by accident. The read-only admin page (decision 12) uses a separate method that dumps and writes nothing.
    - No cron heartbeat is recorded. Phase 1B fills that gap.
    - **Downloads (2026-11-07):** the admin page can download the latest files already in the bucket (`OfflineBox::download()`). It streams them and never dumps. See `docs/features/MVP-offlinebox-anonymized-backups.md`.
- **5. Secrets use the ENV Vars pattern** (`get_cfg_var('X') ?: env('X') ?: ''`), like the Files and Status Endpoint blocks. Backups get their own `OFFLINEBOX_S3_*` settings instead of reusing `FILES_S3_*`, so backups can have a separate bucket and key. Unlike those blocks, a missing setting is a failure, not "off": a backup that silently never runs is the failure this module exists to prevent.
- **11. The CLI environment is the project's job.** Each project adjusts its bootstrap at launch to select the right live or dev environment, so OfflineBox assumes the CLI gets the correct database and adds no environment detection of its own. Connect to the real environment and confirm the database before setting up cron (see `## Cron`).

### Confirmed for the first real-project test

- **12. Admin validation page.** A read-only admin page shows whether the setup is valid and exactly which databases and tables will be exported. See `## Admin validation page`.
    - It can only report what the web server sees. Cron can differ in PHP version, `php.ini`, `PATH` and user. So `bin/cake offline_box --check` runs the same checks from the CLI, and the page says so.
    - It installs with the module, like the command. Core itself shows no link. The AI adds the link at install, normally on the project's `admin/pages` index with the other admin links and features.
    - The shared `OfflineBox::validate()` is not a backup path: it never dumps, and it writes nothing to disk or the bucket. This keeps the AGENTS.md rule that no controller action shares a method with a scheduled job.
- **1. Module contents.** `offlineBox/` holds `OfflineBox.php`, this README, the `OfflineBoxCommand.php` template and the admin page files (decision 12). The class is copied unchanged. The command is copied and then edited for the project.
- **2. Namespace.** The class declares `namespace App\Util;`, because it installs to `src/Util/`, so "copy unchanged" stays true.
- **6. Bucket layout.** One bucket per project, with dated objects at `backups/<filename>` (`OfflineBox::KEY_PREFIX`) and the latest copy of each connection at `backups_recent/<connection>.sql` (`OfflineBox::RECENT_PREFIX`), so nobody has to scroll a long list to find it. The copy only happens after the upload verifies, and it overwrites the last copy, so the server key never needs delete permission. This matches the local client, whose example config reads `backups/`.
    - A connection with `anonymize` also gets `backups_anonymized/<connection>.sql` (`OfflineBox::ANONYMIZED_PREFIX`): the latest anonymized dump, overwritten each run, never deleted.
- **7. One upload method.** `upload()` uses the SDK's `ObjectUploader`, as the Files block does, which switches to multipart for large files. It is always verified. Multipart ETags are not MD5, so those uploads verify size only.
- **8. Dump settings for MySQL 5.7.**
    - `--single-transaction`: consistent snapshot without blocking the live site. It only protects InnoDB. MyISAM tables are dumped as they change, so they can be out of step with the rest if the site is busy. The page lists them as a warning. Schedule the backup for a quiet time, and convert those tables to InnoDB as a separate project decision. OfflineBox never converts them.
    - `--no-tablespaces`: since 5.7.31 a dump otherwise needs the `PROCESS` privilege, which a per-site database user usually lacks.
    - `--routines`: otherwise stored procedures are lost. **Found in testing:** if a procedure was created by a different database user, the whole dump fails with `insufficient privileges to SHOW CREATE PROCEDURE`. It fails loudly, not silently. Fix it by granting that user read access to `mysql.proc`, or by changing the procedure's owner. `--check` can't detect this, so the first real backup is what proves it.
    - `--default-character-set=utf8mb4`: `mysqldump` defaults to `utf8` on 5.7, which can damage 4-byte characters such as emoji. The `latin1` server default doesn't change this, and tables keep their own charset in the dump.
    - The `CREATE DATABASE` header also carries the source database's charset and collation. Otherwise a restore uses whatever the new server defaults to.
    - Not added: `--set-gtid-purged=OFF`, because the MariaDB `mysqldump` in Docker rejects it. Add it only if the first live dump contains `GTID_PURGED` lines.
- **13. No include list.** You can only exclude tables, never pick which ones to back up. With an include list, a table left off by mistake, or added later, would be missing from the backup, and nobody would notice until the restore failed.
- **9. Excludes fail closed.** A name in `exclude` that matches no table fails the dump, because a typo would otherwise back up the data it was meant to leave out. The cost is that dropping an excluded table fails the next backup until the list is updated.
- **10. Out of scope for the MVP.** Dumps stay plain `.sql` (no gzip or encryption), and backups are never deleted by this module. Set a bucket lifecycle rule if storage growth matters. Encryption and thinning stay in Phase 5.

## For AI assistants installing OfflineBox

Follow these steps in order, on the actual project. Paths are relative to the app folder (`sourceFiles/` in a SetupCase project). Don't stage or commit anything. Leave the changes for the developer to review.

Don't rewrite or "improve" the class inside the host project. Project-specific behaviour (which databases, table rules, failure alerts) goes in the host project's command, never in the class. Keep the copyright headers, and carry the `LICENSE` and `NOTICE` files into the destination project.

**1. Check the project.** Confirm it meets `## Requirements`. Run `php -v` and `mysqldump --version` on the CLI, and check `vendor/aws/aws-sdk-php` exists. If the SDK is missing, install it with `composer require aws/aws-sdk-php`, and commit `vendor/` if the project does.

**2. Remove any old version.** First search the project, ignoring case, for `OfflineBox`, `uploadDatabaseBackupToS3`, `downloadBackup` and `mysqldump`. Older projects can have these wired in several places.

- Delete `src/Util/OfflineBox.php` and `src/Util/deprecated-OfflineBox.php` if they exist, plus any old command such as `src/Command/offlineBoxCommand.php`. The old file name can differ only by case, so check for that.
- Delete any Table method that calls the old class (e.g. `uploadDatabaseBackupToS3()`) and its `use App\Util\OfflineBox;`.
- Delete any `require_once` of the old file, and any `use` of `App\Util\ClientKey` or `generate_*` functions that came from it.
- **Tell the developer if the old class holds hard-coded storage keys, without printing them.** They are in git history, so they should be rotated. Don't reuse them.
- **Tell the developer about any production cron entry.** An old `bin/cake offline_box` job runs the new command as soon as it deploys, and fails until step 5 is done on that server.
- **Web actions that run their own `mysqldump`** (e.g. a `downloadBackup()` action and its link): list them for the developer and remove them once confirmed, along with any imports nothing else uses. OfflineBox never runs from the web, and a project may still rely on these for something else.
- **If anything else uses the old class, stop and show the developer.** The old constructor took no arguments, so those callers would break.
- Don't edit anything under `codeBlocks/` if the project has that folder.

**3. Copy the files unchanged.** Fetch each one from its raw link, under `https://raw.githubusercontent.com/undoLogic/setupCase-core/main/offlineBox/`:

| Fetch | Copy to |
|---|---|
| `OfflineBox.php` | `src/Util/OfflineBox.php` |
| `OfflineBoxCommand.php` | `src/Command/OfflineBoxCommand.php` |
| `src/Controller/Admin/OfflineBoxController.php` | same path |
| `templates/Admin/OfflineBox/index.php` | same path |
| `templates/element/2026/admin/offlineBox/index_summary.php` | same path |
| `templates/element/2026/admin/offlineBox/index_databases.php` | same path |
| `templates/element/2026/admin/offlineBox/index_rows.php` | same path |
| `templates/element/2026/admin/offlineBox/index_install.php` | same path |

**4. Edit the command for the project.** This is the only copied file you edit. Ask the developer whether another project shares this server's `php.ini`. If so, set `SETTING_PREFIX` (see `## Storage setup`). Set `CONNECTIONS` to the project's real connections (see `## Choosing tables`), and ask the developer which tables to exclude. Ask whether they want an anonymized dump for dev; if so, ask which columns to `keep` and which admin rows to keep whole (`keepRows`, see `## Anonymized dumps`). Add the failure alert at the failure hook, using the project's existing email or queue. `SetupCase::sendEmail()` may contain a `die()` for the live environment, so check it before relying on it.

**5. Set the storage credentials.** See `## Storage setup`. Ask the developer for the values. Never commit them, and never write them in a tracked file.

- **Local Docker:** add the blank settings to the `web` service's `environment:` block in `dockerLinux/docker-compose.yml`. The developer fills them in locally, and blanks them again before committing.
- **Production:** the same names go in `php.ini`, with no code change.

**6. Add the admin link.** Add a link to the validation page on the project's admin index page, normally `templates/Admin/Pages/index.php` (`/admin/pages`), alongside the other admin links and features. If the project doesn't have that page, ask the developer where the link goes. The page must stay under the `Admin` prefix:

```php
<?= $this->Html->link('OfflineBox', ['prefix' => 'Admin', 'controller' => 'OfflineBox', 'action' => 'index']); ?>
```

**7. Verify.**

1. Open the page and report anything it flags.
2. Run `bin/cake offline_box --check`. Confirm it names the real database.
3. Run `bin/cake offline_box` once. Confirm the object is in the bucket and no `.sql` file remains in `tmp/`.
4. Restore that dump onto an empty server, never the live one (see `## Restoring`).

**8. Set up cron, only once the developer has confirmed.** **Ensure you are connected to the real environment before setting up cron.** See `## Cron`.

**Never:** add this to `config/cron.php`, add a route or controller action that runs a backup, or weaken the CLI guard in the class. To change the class itself, change it in SetupCase core and copy it again.

## Requirements

- PHP 8.0 or later on the **CLI**, and whatever the project's installed `aws/aws-sdk-php` requires (3.402 in core needs 8.2). The CLI can differ from the web version; check with `php -v`.
- CakePHP (uses `ConnectionManager`, `Log`, `TMP` and console commands)
- `aws/aws-sdk-php`
- `mysqldump` on the cron user's `PATH`, with CLI PHP allowed to call `exec()`. Check with `mysqldump --version`: an 8.0 client fails against a 5.7 server (`COLUMN_STATISTICS` error).
- A server that can run a CLI cron job, with a cron user that can write to `TMP` and `logs/`
- An S3-compatible bucket, ideally in the client's own account

## Storage setup

Use a separate **test bucket** for local Docker, never the production one. Backups hold real data, so keep every bucket private.

### 1. Create the bucket and key

**DigitalOcean Spaces**

1. Go to **Spaces Object Storage > Create Bucket**. Pick a region and a name such as `myproject-offlinebox-test`. Restrict file listing, so the bucket stays private.
2. Go to **Spaces Object Storage > Access Keys > Create Access Key**. Choose limited access, for this bucket only, with read and write.
3. Copy the secret straight away, because it is only shown once.
4. Leave `OFFLINEBOX_S3_ENDPOINT` empty. It defaults to the region's Spaces address.

**AWS S3**

1. Create the bucket and keep **Block all public access** on.
2. Create an IAM user with this policy and an access key. It can't delete backups:

   ```json
   {
     "Version": "2012-10-17",
     "Statement": [
       {"Effect": "Allow", "Action": "s3:ListBucket", "Resource": "arn:aws:s3:::BUCKET"},
       {"Effect": "Allow", "Action": ["s3:GetObject", "s3:PutObject", "s3:AbortMultipartUpload", "s3:ListMultipartUploadParts"], "Resource": "arn:aws:s3:::BUCKET/*"}
     ]
   }
   ```

3. Set `OFFLINEBOX_S3_ENDPOINT` to `https://s3.<region>.amazonaws.com`.

### 2. Plug it in

| Setting | Meaning |
|---|---|
| `OFFLINEBOX_S3_KEY` | Access key |
| `OFFLINEBOX_S3_SECRET` | Secret key |
| `OFFLINEBOX_S3_REGION` | Region, e.g. `nyc3` |
| `OFFLINEBOX_S3_BUCKET` | Bucket name |
| `OFFLINEBOX_S3_ENDPOINT` | Optional. Defaults to DigitalOcean Spaces for the region. |

The command reads `php.ini` first, then the environment, so moving from Docker to production needs no code change.

**Local Docker.** Add these under the `web` service's `environment:` block in `dockerLinux/docker-compose.yml`, using a test bucket:

```yaml
      # OfflineBox: local TEST bucket only. Blank these before committing this file.
      # Then apply with: docker compose up -d --no-deps web
      OFFLINEBOX_S3_KEY: ""
      OFFLINEBOX_S3_SECRET: ""
      OFFLINEBOX_S3_REGION: ""    # e.g. nyc3 or tor1
      OFFLINEBOX_S3_BUCKET: ""
      OFFLINEBOX_S3_ENDPOINT: ""  # Leave empty for DigitalOcean Spaces. AWS: https://s3.us-east-1.amazonaws.com
```

**The compose file is committed, so blank the key and secret before committing it.** Then recreate only the web container. The database is not touched:

```bash
cd dockerLinux && docker compose up -d --no-deps web
```

Never use `docker compose down -v`, because it deletes the database.

**Production.** Add the same names to the `php.ini` that **CLI PHP** reads (check with `php --ini`), using the production bucket:

```ini
OFFLINEBOX_S3_KEY = "..."
OFFLINEBOX_S3_SECRET = "..."
OFFLINEBOX_S3_REGION = "nyc3"
OFFLINEBOX_S3_BUCKET = "myproject-offlinebox"
```

The admin page runs under web PHP, so that `php.ini` needs them too for its Storage section to pass.

**Projects sharing one `php.ini`.** Give each project its own key and bucket, and set a prefix in its `src/Command/OfflineBoxCommand.php`. Leave the prefix blank when a project has the file to itself.

```php
public const SETTING_PREFIX = 'UPDATECASE_';
```

```ini
UPDATECASE_OFFLINEBOX_S3_KEY = "..."
UPDATECASE_OFFLINEBOX_S3_SECRET = "..."
UPDATECASE_OFFLINEBOX_S3_REGION = "nyc3"
UPDATECASE_OFFLINEBOX_S3_BUCKET = "updatecase-offlinebox"
```

- Local Docker uses the same prefixed names in `docker-compose.yml`.
- A prefixed project reads only its prefixed names and never falls back to the plain ones, so a forgotten setting fails instead of writing to another project's bucket. The Storage section and the Installation section show the exact names to add.
- Two projects must never share a bucket: each would overwrite the other's `backups_recent/default.sql`, and one project's backups would hide the other's failures on the admin page.
- Separate keys don't fully separate the projects. Any PHP code on the server can read everything in that `php.ini`, so a hacked project can read the other project's key. Separate keys do let you limit each key to its own bucket and revoke one without touching the other. Full separation needs different server users and secrets the other user can't read.

### 3. Check it

1. Open `/admin/offline-box`. The Storage section should show every setting, by its exact name, as set and the bucket as reachable. "None yet" for the newest backup is expected at this point.
2. Run the backup. In Docker, that is `docker exec <web container> sh -c 'cd /var/www/vhosts/website.com/www/sourceFiles && bin/cake offline_box'`.
3. Reload the page. The newest backup should appear under `backups/`, and the bucket should also have `backups_recent/default.sql`.

## Command template

`OfflineBoxCommand.php` is copied into the project and then edited (step 4). `bin/cake offline_box` runs the backup for each connection in `CONNECTIONS`, one bad connection doesn't stop the rest, and it exits non-zero on any failure. `--check` runs the validation only and exits non-zero only on a problem, not a warning.

## Admin validation page

Answers "is this set up correctly, and what will be exported?" without running a backup.

- **URL:** `GET /admin/offline-box`. Admin prefix RBAC only, with no role checks in the action.
- **Read-only:** no dump, no bucket write, no temp file, no POST. A check that fails shows red instead of breaking the page.
- **Downloads:** each exported database has **Download anonymized data** (when it has `anonymize`) and **Download full data** buttons. They are GET links to `downloadAnonymized` / `downloadFull`, open to any Admin, and stream the latest file from the bucket. A missing file shows "None yet". Download logging is optional: the controller has a comment where a project can add it.
- **Secrets:** passwords and keys are never shown, only "set" or "missing".
- **Files in `offlineBox/`** (folders match the install path):
    - `src/Controller/Admin/OfflineBoxController.php`
    - `templates/Admin/OfflineBox/index.php`
    - `templates/element/2026/admin/offlineBox/index_summary.php`, `index_databases.php`, `index_rows.php` and `index_install.php`

The controller calls `OfflineBox::validate()` and hands the template ready-to-show rows. Every section uses Bootstrap badges: green OK, yellow warning, red problem.

**Summary.** "Ready" or "Problems found", with the count of each. It always includes: "Checked from the web server. Run `bin/cake offline_box --check` as the cron user to check the cron environment."

**Databases: what will be exported.** One row per configured CakePHP connection:

| Column | Shows |
|---|---|
| Connection and database | Connection name and database name |
| Will export | **Yes** if it is in `OfflineBoxCommand::CONNECTIONS`, otherwise **No** |
| Connects | OK or the error, with the server version. Only tried for connections that will export. |
| Tables | Count with data, and count structure only |
| Size | Estimated size, from `information_schema` |

Each exported database expands to its full table list, **largest first** with each table's size, so huge log tables are easy to find and exclude. Each table has a **Data + structure** or **Structure only** badge. A structure-only table still shows its size in the database, even though its rows aren't exported. Red: an `exclude` name matching no table, or a database with no tables. Yellow: non-InnoDB tables, because the snapshot doesn't protect them.

**Environment.**

- Web PHP version, and whether `exec()` is allowed
- `mysqldump` found and its version. Red for an 8.0 client against a 5.7 server.
- `TMP` is writable, with free disk space compared to the estimated dump size

**Storage.**

- Each `OFFLINEBOX_S3_*` setting: set or missing, plus the bucket name and endpoint
- Bucket reachable with these credentials
- Newest object under `backups/` with its date, size and age. Red if it is 2 days old or more. Yellow if there is none yet, as on a new install.

**What this page can't prove:** that the key can write to the bucket, that a real dump succeeds, that cron is scheduled, or that a backup restores. Those need the first real run and `## Restoring`.

## Choosing tables

Every table is always in the backup. Each connection takes one value, `exclude`, for the big tables whose rows you don't want:

```php
public const CONNECTIONS = [
    'default' => ['exclude' => "activity_logs\nsome_other_log"],
];
```

- Excluded tables keep their structure and triggers, and are restored empty, so the app still starts and finds them.
- A new table is backed up automatically, because the list only names what to leave out.
- The list can be an array or a newline-separated string (paste straight from phpMyAdmin). Commas don't separate names: a comma-separated list is read as one table name, so the dump fails.
- If an `exclude` name matches no table, the dump fails instead of backing up data meant to be left out. A view counts as no match: it holds no rows, so there's nothing to exclude.

## Anonymized dumps

Add `anonymize` to a connection to also upload a dev-safe copy, after the full backup succeeds:

```php
'default' => [
    'exclude' => "activity_logs",
    'anonymize' => [
        'keep' => ['users' => ['user_type', 'active', 'created'], 'products' => ['name', 'price']],
        'keepRows' => ['users' => ['user_type' => ['admin']]],
    ],
],
```

- Only columns in `keep`, primary keys and integer `id` / `*_id` columns keep real data. Every other column is faked with a value valid for its type, so new columns and tables are safe by default.
- `keepRows` keeps whole rows real, so admins can log in to dev with their own account.
- Same structure, routines, views and triggers as the full dump, and the same row counts. Excluded tables stay empty.
- A failed anonymized dump never touches the full backup, but the command still exits non-zero.

Full rules and fake values: `docs/features/MVP-offlinebox-anonymized-backups.md`.

## Cron

```cron
0 3 * * * cd /path/to/app && bin/cake offline_box >> logs/offlinebox.log 2>&1
```

**Before adding this, ensure the CLI connects to the real environment, not dev.** Run `bin/cake offline_box --check` on the server and confirm the database name. Cron has a short `PATH`, so add the folders holding `php` and `mysqldump` if needed.

The command exits non-zero on failure so cron and any monitoring can detect it. Failures are also logged at `error`, so they land in `logs/cli-error.log`, which the Status Endpoint block counts.

The admin page's **Installation** section shows these same steps with the server's real folder filled in, ready to copy.

Don't add this job to `config/cron.php`, and don't call the command or the class from a web route. The class refuses to run outside the CLI.

## Restoring

Each dump starts with `CREATE DATABASE IF NOT EXISTS` and `USE`, so it restores onto an empty server of the same major version (MySQL 5.7 in the MVP):

```bash
mysql -h <host> -u <user> -p --max-allowed-packet=1G < 2026-11-05_default_030000.sql
```

Set the same limit on the server first with `SET GLOBAL max_allowed_packet = 1073741824;`, or start it with `--max-allowed-packet=1G`. A stock MySQL 5.7 server allows only 4 MB, so a large row stops the restore with `ERROR 2006: MySQL server has gone away` and leaves only some of the tables. If that happens, drop the database, raise the limit and restore again.

**Never restore onto a server holding the live database to "test" a backup.** The dump switches to the original database name and replaces its tables.

### Setting up local Docker from a backup

Load local Docker from a real backup instead of a web download. Every local setup then also proves that the backups in the bucket can be restored.

1. Use **Download anonymized data** on the admin page, or download `backups_anonymized/default.sql` from the bucket's web panel, so production keys never go into Docker. Use the full `backups_recent/default.sql` only to test a restore.
2. From `dockerLinux/`, restore it. This replaces the tables in the local database:

```bash
docker compose exec -T db sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" --max-allowed-packet=1G' < default.sql
```

A full dump puts real data in the local database, so keep the dump file out of git and delete it when you're done. An anonymized dump still holds the kept columns and admin rows. Excluded tables come back empty.

## Roadmap

Each phase is useful by itself. A later phase never needs to exist for an earlier one to be worth running.

### Phase 1: Server backup to S3 (current)

Install one class, add a cron job, and every listed database lands in S3-compatible storage automatically.

Built, and tested only as listed in `## MVP Status`:

- [x] Dump each connection with a list of tables to exclude
- [x] Upload as private objects and verify size and MD5
- [x] Keep the database password off the command line
- [x] CLI guard on `mysqlDump()` and `upload()`, with a read-only `validate()`
- [x] `App\Util` namespace and the Apache-2.0 copyright header
- [x] Configurable S3 endpoint with an optional `spaceEndpoint`
- [x] One upload method using `ObjectUploader`, always verified
- [x] MySQL 5.7 dump flags, and the charset in the `CREATE DATABASE` header
- [x] Fail on an `exclude` name that matches no table
- [x] Remove partial dumps and credentials files, create them as `0600`, and quote the password
- [x] Include the `mysqldump` error text in `MSG`
- [x] `OfflineBoxCommand.php`: exits non-zero on failure, handles each database separately, deletes the local `.sql`, uploads under `backups/`, and has the failure hook
- [x] Admin validation page and `--check`
- [x] Install steps for removing the old version from a project
- [x] Anonymized dump to `backups_anonymized/`, and download buttons on the admin page (tested in throwaway containers only, see the feature file)

Still to do:

- [x] First install on a real project, tested in local Docker with a fake S3 server
- [ ] Test against a real bucket, then on production
- [ ] Decide when to remove the old code from core itself

### Phase 1B: Heartbeat

Silence gets flagged, with no storage keys anywhere but the server.

- After a verified backup, the server sends one HTTP request to a monitoring endpoint with project, databases, date and result
- The endpoint uses a per-project key that can only report status
- The monitor flags any project that hasn't reported within an expected window

### Phase 2: Offline copy and local restore check

A machine in the office holds the data and proves it restores.

- Local client pulls backups and prunes its local copies. Windows is in `localClient/` (see its README); add Linux and macOS
- Automated restore check: load the latest dump into a throwaway MySQL container of the same major version (5.7), compare table and row counts, confirm the newest record is recent, log pass or fail
- Move local credentials into a protected store

### Phase 3: Local heartbeat

- The office machine reports each restore check to the monitor
- Catches every failure mode: dump failed, sync failed, office machine off, restore failed

### Phase 4: Full app boot test

- Boot the app in a container against the restored database
- Run a few smoke checks (login page loads, key records display)

### Phase 5: Dashboard

- offlinebox.com dashboard showing each project's last verified backup
- History of checks for audit and client reporting
- Daily-then-monthly backup thinning
- Optional encryption before upload

## Manual quarterly check

Automation proves the data loads. A person confirms it makes sense. Once a quarter:

- Restore the latest backup by following the steps above
- Spot-check real records
- Send the client a short report: which databases, from what date, and the result

## Testing

Phase 1 only. All scenarios are manual, against a test bucket and a throwaway database.

### Dump

**Intent:** A dump holds the expected tables and exposes no credentials.

**Surfaces:**
- `OfflineBox::mysqlDump()`

#### Scenarios

- [x] A whole-database dump contains every table with its data.
- [x] Excluded tables appear with structure and no rows.
- [x] An `exclude` name that matches no table fails the dump.
- [x] An `exclude` name that is a view fails the dump.
- [x] A view that reads from an excluded table restores and works, with no rows.
- [x] An excluded table keeps its triggers.
- [x] A table list pasted with Windows line endings works.
- [ ] A password containing `#`, a space or quotes logs in.
- [ ] The password never appears in the process list.
- [ ] The dump and credentials files are `0600` while they exist.
- [ ] The credentials file is gone after both success and failure.

### Upload

**Intent:** A backup counts as done only once the stored object is confirmed to match.

**Surfaces:**
- `OfflineBox::upload()`

#### Scenarios

- [ ] A small dump uploads as a private object and verifies by size and MD5.
- [x] A dump above the multipart threshold uploads and verifies by size.
- [ ] A size or MD5 mismatch returns `STATUS` 500.
- [ ] Wrong credentials or a missing bucket return `STATUS` 500 without throwing.
- [ ] A custom `spaceEndpoint` is used, and leaving it out uses DigitalOcean Spaces.

### Command and failure handling

**Intent:** Any failure is loud, and one bad database does not stop the others.

**Surfaces:**
- `bin/cake offline_box`
- `OfflineBoxCommand`

#### Scenarios

- [x] A full success exits 0 and objects are stored under `backups/`.
- [x] The newest backup is copied to `backups_recent/<connection>.sql` as a private object, overwriting the last copy and leaving `backups/` untouched.
- [x] A failed copy leaves the last `backups_recent/` file in place and says the dated backup is safe.
- [ ] A failed copy makes the command exit non-zero and logs an `error`.
- [ ] A bad connection is reported, the remaining connections still back up, and the exit code is non-zero.
- [ ] A missing `OFFLINEBOX_S3_*` setting exits non-zero and logs an `error`.
- [ ] A failure appears in `logs/cli-error.log` and is counted by the Status Endpoint.
- [ ] On the real server, the output and `--check` name the real database, not a dev one.
- [ ] No `.sql` file remains in `TMP` after a success, a failed dump or a failed upload.
- [ ] The Windows client's example config finds the uploaded objects under `backups/` (tracked in `localClient/README.md`).

### Restore

**Intent:** A backup can actually be turned back into a working database.

**Surfaces:**
- `mysql < <dump>.sql`

#### Scenarios

- [x] A dump restores onto an empty server with no database created first.
- [x] Table and row counts match the source for every table that wasn't excluded.
- [x] Excluded tables exist and are empty.
- [ ] Stored procedures, triggers and views are present and work, including on a server without the original database user (`DEFINER`). Passed with the same database user; a different user isn't tested yet.
- [ ] Accented characters in `latin1` tables and emoji in `utf8mb4` tables match the source exactly.
- [x] The restored database has the source's charset and collation.
- [ ] The app runs against the restored database.
- [x] With tables excluded, the app starts on the restored database and those tables have the same structure as the source.

### Admin validation page

**Intent:** An admin can see what will be exported and what is wrong, without anything being changed.

**Surfaces:**
- `/admin/offline-box`
- `OfflineBox::validate()`
- `bin/cake offline_box --check`

#### Scenarios

- [ ] A non-admin can't reach the page.
- [ ] Each listed database shows Will export: Yes, with the right tables and data or structure-only badges.
- [ ] A configured connection not in the list shows Will export: No.
- [ ] The table list is sorted largest first and shows each table's size.
- [ ] A connection that can't connect shows red, and the rest of the page still renders.
- [ ] An `exclude` name matching no table shows red.
- [ ] A missing `OFFLINEBOX_S3_*` setting shows red and the page still renders.
- [ ] Wrong storage credentials show red.
- [ ] The newest backup shows its date, size and age. A missing backup shows a warning, and one older than 2 days shows red.
- [ ] No password or secret key appears in the page source.
- [ ] Loading the page creates no file in `TMP`, no object in the bucket and no dump.
- [ ] The page works at phone width.
- [x] The Installation section shows the check, backup and cron commands with this server's folder filled in.
- [x] The Storage section labels each setting with its exact `php.ini` or environment name, and never shows its value.
- [x] With `SETTING_PREFIX` set, `--check` and the Installation section use the prefixed names.
- [ ] With `SETTING_PREFIX` set, a real backup reaches that project's own bucket.
- [x] With `SETTING_PREFIX` set and only the plain names present, the settings show as missing and the backup fails.
- [x] The `php.ini` block from the Installation section works once its values are filled in.
- [ ] `--check` reports the same checks from the CLI, changes nothing, and exits non-zero on a problem.

### First live target (MySQL 5.7, UNIX socket)

**Intent:** The module works with the real server, database user and cron environment, not just Docker. Docker's `mysqldump` is the MariaDB client, so a pass there doesn't prove this.

**Surfaces:**
- The cron entry on the server
- The server's `mysqldump`

#### Scenarios

- [ ] CLI `php -v` is 8.0 or later, and the app boots from cron.
- [ ] `mysqldump --version` is a 5.7-compatible client.
- [ ] `mysqldump` connects over the UNIX socket as `localhost`.
- [ ] The database user can run every dump flag, including `--routines`, without extra privileges.
- [ ] The `OFFLINEBOX_S3_*` settings are readable from CLI PHP.
- [ ] Any MyISAM tables have been listed and accepted.

### CLI only and old code removed

**Intent:** An installed project has one version of this feature, and nothing on the web can start a backup.

**Surfaces:**
- `src/Util/` in the host project
- `Admin/SetupPagesController` in the host project
- `config/cron.php`
- `OfflineBox::__construct()`

#### Scenarios

- [ ] Calling `mysqlDump()` or `upload()` from a web request throws, and nothing is dumped or uploaded.
- [ ] `cron.php?action=run_all` does not start a backup.

- [ ] After install, `/admin/en/setup-pages/download-backup` returns 404.
- [ ] After install, nothing in the project references the removed class or action.
- [ ] Old code used elsewhere in a project is reported to the developer, not deleted.
