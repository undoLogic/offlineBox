# OfflineBox (Windows)

Pulls database backups from S3-compatible object storage onto this computer,
which acts as the offline/local copy ("the OfflineBox"). See
`docs/features/MVP-OfflineBox-Backup-Workflow.md` for the full design.

## Setup

1. Install the [AWS CLI](https://aws.amazon.com/cli/) and confirm it's on `PATH`:
   ```powershell
   aws --version
   ```
2. Copy `config.example.json` to `config.json` in this same folder and fill in real values.
   `config.json` is gitignored - never commit real S3 credentials.
3. Run it once manually to confirm it works:
   ```powershell
   .\offlineBox.ps1
   ```

## Config keys

| Key | Meaning |
|---|---|
| `S3Endpoint` | S3-compatible endpoint URL |
| `S3Region` | Region (e.g. `nyc3`) |
| `S3Bucket` | Bucket name |
| `S3Prefix` | Remote path/prefix backups are uploaded under |
| `S3AccessKey` / `S3SecretKey` | S3 credentials |
| `LocalCurrentDir` | Where new backups are downloaded to |
| `LocalArchiveDir` | Where aging backups are moved to |
| `ArchiveAfter` | Age at which a backup in `Current` moves to `Archive`, e.g. `-1 month` |
| `DeleteAfter` | Age at which a backup **already in `Archive`** is deleted, e.g. `-6 months`. Optional - omit or leave blank to disable deletion entirely. |

`ArchiveAfter`/`DeleteAfter` accept `-<n> day(s)`, `-<n> month(s)`, or `-<n> year(s)`.

## Safety model

- Nothing in `Current` is ever deleted, regardless of age - only moved to `Archive`.
- Deletion only ever considers files already sitting in `Archive`, and only once they pass `DeleteAfter`.
- If `DeleteAfter` is unset, the script never deletes anything - it only downloads and archives.

## Scheduling

Register it in Windows Task Scheduler to run once per day, non-interactively:

```powershell
$action = New-ScheduledTaskAction -Execute "powershell.exe" `
    -Argument "-NoProfile -ExecutionPolicy Bypass -File `"C:\path\to\offlineBox_win\offlineBox.ps1`""
$trigger = New-ScheduledTaskTrigger -Daily -At 3am
Register-ScheduledTask -TaskName "OfflineBox Backup" -Action $action -Trigger $trigger -RunLevel Highest
```

Missing or invalid `config.json` stops the script immediately with a non-zero
exit code and no partial sync - useful for surfacing failures in Task Scheduler's history.

## Troubleshooting 

If scripts are disabled run this to allow
```shell
Set-ExecutionPolicy -ExecutionPolicy RemoteSigned -Scope CurrentUser
```
