# OfflineBox — Anonymized Backups + Download Buttons (MVP)

## Goal

Stop working on real customer data on the dev machine. Each backup run produces two dumps per database:

1. **Full backup**: unchanged. For disaster recovery, with history, as today.
2. **Anonymized backup**: same structure, but real data only in whitelisted columns. Everything else is replaced with fake values. No history; latest only.

Daily dev work (Docker) uses the anonymized dump. The full dump is touched only when validating backups.

## Scope (MVP)

- Shared anonymize logic, used by both the S3 push and the download buttons
- Whitelist config
- New S3 folder for anonymized dumps
- Two download buttons per database on the existing OfflineBox backup screen

Out of scope for now (future):
- Pull script (bash / PowerShell) and a dev-only S3 key
- Separate bucket for anonymized dumps (needed only once a dev key exists)
- Public service / heartbeat product

## Existing behavior to keep

- Backup class + cron pushes database dumps to S3 (`backups`, `backups-recent`)
- The **exclude** list for large tables keeps working exactly as today and applies to both dumps
- Failure email via the host project's existing email/queue

## 1. Whitelist config

Grouped by table. Only the listed columns keep real data. **No `*` / keep-all option.**

```php
'OfflineBox' => [
    'exclude' => [
        // existing large-table excludes
    ],
    'anonymize' => [
        'users'    => ['user_type', 'created', 'modified'],
        'products' => ['name', 'price', 'active'],
        // tables not listed: every column faked
    ],
],
```

If an app has several databases, add one level: database name → tables → columns.

### Rules

- **Safe by default:** any table or column not whitelisted gets fake data. New columns and new tables are protected automatically.
- **Always keep automatically (never listed):** primary keys (`id`) and foreign key columns (`*_id`), so relationships still work.
- **Keep all rows.** No subsetting. Row counts match the real database.
- **Structure always complete:** every table, every column, same schema as the full dump.
- **Excluded tables:** same as the full dump (structure only / skipped, per the existing exclude behavior).
- **Unknown-item warning:** when the export sees a table or column not in the whitelist, log a warning (not an error) so new items get noticed and decided on.

## 2. Fake values

Fake values must be valid for the column type, so the app still runs:

| Column type | Fake value |
|---|---|
| varchar / text | `anonymized` (or `anonymized-{id}` when unique) |
| email-like (name contains `email`) | `user{id}@example.test` (unique) |
| int / decimal | `0` |
| date / datetime | fixed date, e.g. `2000-01-01` |
| boolean / tinyint(1) | `0` |
| enum | first allowed value |
| nullable columns | `NULL` is acceptable |
| unique-indexed columns | must stay unique, so append the row id |

Passwords, tokens and API keys are never whitelisted, so they are always faked.

## 3. S3

- Anonymized dumps go into a new folder in the **same bucket**: `backups-anonymized/`
- **Latest only:** overwrite per database; no history and no pruning logic needed
- The full backup flow is unchanged

## 4. Backup screen: download buttons

On the existing OfflineBox backup screen, next to each database:

- **Download anonymized data**: runs the shared anonymize export on demand and streams it
- **Download full data**: the real dump

Requirements:
- **One shared anonymize function.** The button and the S3 push call the same code and must never drift apart.
- **Full data button: owner role only.** Hidden from everyone else and enforced server-side, not just hidden in the UI.
- **Log every download** (who, when, which database, full vs anonymized) using the existing logging/comments pattern.
- **Stream the output** directly to the browser (gzip). Don't build the whole file in memory, so larger databases don't time out.
- Filename: `{database}-anonymized-{YYYY-MM-DD}.sql.gz` / `{database}-full-{YYYY-MM-DD}.sql.gz`

## 5. Checks before calling it done

Smoke tests (this touches the existing backup feature, which live clients rely on):

- Full backup to S3 still runs and is byte-for-byte unaffected by the new code
- Anonymized dump imports cleanly into a fresh Docker MySQL
- The app boots and runs against the anonymized dump (logins/relationships intact)
- A non-whitelisted column contains only fake values
- A newly added column (not in the whitelist) comes out faked, and a warning is logged
- A non-owner cannot reach the full-data download, even by URL
- Every download is logged

## Notes

- First real use: undoLogic's own projects only. Smooth it out before offering it to anyone else.
- Later: dev-only S3 key + separate anonymized bucket + pull script into the Docker SQL directory.
- Long term: OfflineBox as its own service (open code, paid watching/validation). The service only ever holds heartbeats, never customer data or keys.
