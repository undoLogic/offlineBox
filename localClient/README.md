# OfflineBox Local Client (Windows)

**Until your data is physically in your hands, you don't have it.**

The local client pulls OfflineBox backups from S3-compatible storage onto a Windows machine in your office, then prunes the local copies so they stay manageable forever. It never touches the bucket.

This folder is self-contained. `install.ps1` fetches it on its own, so you can set it up on any Windows machine.

**Status (2026-11-05):** tested on Linux with PowerShell 7 against a fake S3 server. Not yet run on Windows, on PowerShell 5.1 or against a real bucket.

## Folder contents

| File | Purpose |
|---|---|
| `install.ps1` | Installs or updates the other files |
| `offlineBox.ps1` | The script Task Scheduler runs daily |
| `config.example.json` | Template; copy to `config.json` and fill in |
| `.gitignore` | Keeps `config.json` out of git |
| `README.md` | This file |

`config.json` holds real keys. Never commit it.

## Requirements

- Windows with PowerShell 5.1 or later
- AWS CLI on `PATH` (works with any S3-compatible provider, including DigitalOcean Spaces)
- A **read-only** storage key for each backup set

Use read-only keys only. If this machine is ever compromised, the attacker can read backups but cannot delete or change anything in the bucket.

## How it works

Each daily run loops through every backup set in the config:

1. **Pull.** Download every file dated within the last `PullDays`, skipping files already on disk.
2. **Prune.** For files older than `KeepAllMonths`, keep the last non-empty file per database per year-month, and delete the rest locally.
3. **Log.** Write one timestamped line per action to a plain text log.

### Dates and databases come from the filename

OfflineBox names every dump:

```text
{YYYY-MM-DD}_{prefix}{database}_{HHMMSS}.sql
```

The client reads the date and database from the name only. It never uses S3 or Windows timestamps, because copying or moving a file changes those. Databases are detected automatically: every unique database part is one database, so a set with one database or four needs no extra configuration.

Files with any other name are never pulled or pruned. That includes `backups_recent/<connection>.sql`, so the client only collects the dated backups.

### Why pruned files never come back

The client only pulls files dated within `PullDays`, and only prunes files older than `KeepAllMonths`. Because `PullDays` is always shorter, a pruned file is always outside the pull window and is never downloaded again. No download history file is needed.

The script refuses to start if `PullDays` is not shorter than `KeepAllMonths`, counting each month as 28 days. So 3 months allows a `PullDays` of up to 83.

### Retention result

- Last `KeepAllMonths` months: every backup
- Before that: one backup per database per month, forever

The month the cutoff falls in can hold one extra file until the whole month has aged out.

## Safety rules

- The bucket is never modified. Pruning only affects the local disk.
- A month's keeper must be non-empty. If a month has no non-empty file, nothing in that month is deleted and a warning is logged.
- Pruning is skipped for a set if its pull failed or found nothing new.
- A download lands in a `.part` file and only gets its real name once its size matches the bucket's listing. A cut-off download never counts as a backup, and leftover `.part` files are removed on the next run.
- The script refuses to start if two sets share a `LocalDir`. Projects usually share the connection name `default`, so one project's file could otherwise become another's keeper.
- The script refuses to start if `LogFile` or a `LocalDir` isn't an absolute path. Task Scheduler usually starts in `C:\Windows\System32`, so a relative path could put backups there.
- `DryRun` logs what would be deleted without deleting. Leave it on for the first few weeks and check the log.
- If one set fails, it is logged and the remaining sets still run.
- The script exits non-zero if any set failed, so Task Scheduler's history shows it.

## Config

Copy `config.example.json` to `config.json`:

```json
{
  "PullDays": 30,
  "KeepAllMonths": 3,
  "DryRun": true,
  "LogFile": "D:\\OfflineBox\\offlinebox.log",
  "BackupSets": [
    {
      "Name": "ClientA",
      "S3Endpoint": "https://nyc3.digitaloceanspaces.com",
      "S3Region": "nyc3",
      "S3Bucket": "clienta-backups",
      "S3Prefix": "backups/",
      "S3AccessKey": "read-only key",
      "S3SecretKey": "read-only secret",
      "LocalDir": "D:\\OfflineBox\\ClientA"
    },
    {
      "Name": "ClientB",
      "S3Endpoint": "https://nyc3.digitaloceanspaces.com",
      "S3Region": "nyc3",
      "S3Bucket": "clientb-backups",
      "S3Prefix": "backups/",
      "S3AccessKey": "read-only key",
      "S3SecretKey": "read-only secret",
      "LocalDir": "D:\\OfflineBox\\ClientB",
      "PullDays": 45
    }
  ]
}
```

| Key | Level | Required | Meaning |
|---|---|---|---|
| `PullDays` | Global, overridable per set | Yes | Pull files dated within this many days |
| `KeepAllMonths` | Global, overridable per set | Yes | Keep every file this many months back; prune older |
| `DryRun` | Global | Yes | `true` logs deletions without deleting. Write it without quotes. |
| `LogFile` | Global | Yes | Absolute path to the plain text log |
| `Name` | Set | Yes | Label used in the log |
| `S3Endpoint` | Set | Yes | The region's address, which must NOT contain the bucket name |
| `S3Region`, `S3Bucket` | Set | Yes | Storage location |
| `S3Prefix` | Set | Yes | Remote folder. OfflineBox uses `backups/`. An empty string means the bucket root. |
| `S3AccessKey`, `S3SecretKey` | Set | Yes | Read-only credentials |
| `LocalDir` | Set | Yes | Absolute path where this set's backups are kept. One folder per set. |

**`S3Endpoint` must NOT contain the bucket name.** DigitalOcean's bucket page shows an address that includes it, so remove that part and put the bucket name in `S3Bucket`:

```text
Wrong: https://my-bucket.tor1.digitaloceanspaces.com
Right: https://tor1.digitaloceanspaces.com
```

Keys are stored as plain text. Keep the machine's disk encrypted and its user account locked down.

## Setup

1. Install the AWS CLI:

   ```powershell
   winget install -e --id Amazon.AWSCLI
   ```

   Then open a new PowerShell window, so it can find the command, and run `aws --version` to confirm it works.

2. Create a read-only key for each bucket with your storage provider.
3. Open [`install.ps1` on GitHub](https://github.com/undoLogic/setupCase-core/blob/main/offlineBox/localClient/install.ps1) and click **Download raw file**. Move it into the folder the client will live in, e.g. `D:\OfflineBox\localClient\`, because it downloads into its own folder. Then run it:

   ```powershell
   powershell -ExecutionPolicy Bypass -File D:\OfflineBox\localClient\install.ps1
   ```

4. Fill in each backup set in the new `config.json`. `S3Endpoint` must NOT contain the bucket name (see Config).
5. Run once by hand and check the log:

   ```powershell
   powershell -ExecutionPolicy Bypass -File D:\OfflineBox\localClient\offlineBox.ps1
   ```

6. Schedule it daily in Task Scheduler:
   - Program: `powershell.exe`
   - Arguments: `-ExecutionPolicy Bypass -File D:\OfflineBox\localClient\offlineBox.ps1`
   - Run whether the user is logged on or not
7. After a few weeks of clean dry-run logs, set `DryRun` to `false`.

To update the client later, run `install.ps1` again. It replaces the other files and keeps your `config.json`. It doesn't update itself.

## Log format

One line per action:

```text
2026-11-05 03:00:12 [ClientA] PULL 2026-11-04_default_030000.sql (14.2 MB)
2026-11-05 03:00:15 [ClientA] SKIP 2026-11-03_default_030000.sql (already on disk)
2026-11-05 03:00:20 [ClientA] PRUNE 2026-07-12_default_030000.sql (DryRun)
2026-11-05 03:00:21 [ClientA] KEEP 2026-07-31_default_030000.sql (July 2026 keeper)
2026-11-05 03:00:22 [ClientB] ERROR Access denied; skipping set
2026-11-05 03:00:22 DONE 1 of 2 sets failed
```

`WARN` lines mark a month with no non-empty file, or a run that pulled nothing new. Several days of `Nothing new pulled` in a row means the server has probably stopped sending backups, even though the script still exits zero.

## Future, not now

- Call home after each run to a server log or heartbeat, so a machine that stops running gets noticed
- Automated restore check: load the latest dump into a throwaway MySQL container and verify table and row counts
- Stricter "non-empty" check, so a half-finished dump is never chosen as a keeper
- Move keys into Windows Credential Manager
- Bucket cleanup with a storage lifecycle rule, once storage cost matters

## Testing

This is MVP work, so there are no automated tests. A ticked scenario ran on Linux with PowerShell 7 and a fake S3 server.

### Pull

**Intent:** Recent backups reach this machine exactly once, and only whole files count.

**Surfaces:**
- `offlineBox.ps1`
- `config.json`

#### Scenarios

- [x] Files inside `PullDays` are downloaded, and older ones are not.
- [x] A file already on disk is skipped.
- [x] `backups_recent/` and files with other names are ignored.
- [x] A database name with a prefix or underscores is read correctly.
- [x] An empty `S3Prefix` lists the whole bucket and finds the backups.
- [x] A leftover `.part` file is removed.
- [x] A download whose size doesn't match the listing fails the set and is not given its real name.
- [x] The bucket's objects are unchanged after pulling and pruning.

### Prune

**Intent:** Old local files shrink to one per database per month, and the wrong file is never deleted.

**Surfaces:**
- `offlineBox.ps1`

#### Scenarios

- [x] Files older than `KeepAllMonths` reduce to the last non-empty one per database per month.
- [x] Each database keeps its own monthly file.
- [x] An empty file newer than the keeper doesn't replace it.
- [x] A month with only empty files is left alone and logs a warning.
- [x] Files inside `KeepAllMonths` are never touched.
- [x] Files whose names aren't valid backups are never touched.
- [x] `DryRun` logs deletions and deletes nothing.
- [x] Pruning is skipped when nothing new was pulled.
- [x] Pruning is skipped when the pull failed.
- [x] A pruned file still in the bucket isn't downloaded again.

### Config and failures

**Intent:** A bad config stops the run before anything changes, and one bad set doesn't stop the others.

**Surfaces:**
- `offlineBox.ps1`
- `config.json`

#### Scenarios

- [x] `PullDays` not shorter than `KeepAllMonths` refuses to start.
- [x] Two sets sharing a `LocalDir` refuses to start.
- [x] A quoted `DryRun` refuses to start.
- [x] A missing `S3Prefix` refuses to start.
- [x] A relative `LogFile` or `LocalDir` refuses to start.
- [x] An absolute `LogFile` is used as written.
- [x] A failing set is logged, the next set still runs, and the exit code is non-zero.

### Install

**Intent:** One downloaded file sets up the client, and updating it never costs you your settings.

**Surfaces:**
- `install.ps1`

#### Scenarios

- [x] It downloads the client files into its own folder, whatever folder it's started from.
- [x] It creates `config.json` from the example on the first run.
- [x] A second run keeps an edited `config.json`.
- [x] A failed download leaves the existing files in place, reports the error and exits non-zero.

### Real Windows machine

**Intent:** The client works where it will actually run.

**Surfaces:**
- Windows PowerShell 5.1
- AWS CLI v2 on Windows
- Task Scheduler
- DigitalOcean Spaces

#### Scenarios

- [ ] A manual run works on Windows PowerShell 5.1.
- [ ] `install.ps1` downloaded through a browser runs with the command in Setup.
- [ ] A read-only key can list and download, and can't upload or delete.
- [ ] The example config finds the objects under `backups/` in a real bucket.
- [ ] A large backup downloads completely.
- [ ] Task Scheduler runs it while logged out, and a failed run shows in its history.
- [ ] After a few weeks of dry runs, a real prune deletes what the log predicted.
