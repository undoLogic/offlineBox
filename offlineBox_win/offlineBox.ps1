<#
OfflineBox Windows client.

Pulls backups from S3-compatible object storage onto local disk, then applies
the two-gate retention model documented in docs/features/MVP-OfflineBox-Backup-Workflow.md:

  1. ArchiveAfter - moves files from Current -> Archive once they reach this age.
  2. DeleteAfter  - (optional) deletes files already IN Archive once they reach this age.

Current is never a delete target. If DeleteAfter is not configured, nothing is
ever deleted - the script only downloads and archives.

Requires the AWS CLI (https://aws.amazon.com/cli/) to be installed and on PATH.

Usage:
    .\offlineBox.ps1
    .\offlineBox.ps1 -ConfigPath "C:\path\to\config.json"
#>

param(
    [string]$ConfigPath = (Join-Path $PSScriptRoot "config.json")
)

$ErrorActionPreference = "Stop"

function Get-OfflineBoxConfig {
    param(
        [Parameter(Mandatory = $true)][string]$ConfigPath
    )

    if (-not (Test-Path $ConfigPath)) {
        Write-Error "Config file not found: $ConfigPath"
        exit 1
    }

    try {
        $config = Get-Content $ConfigPath -Raw | ConvertFrom-Json -ErrorAction Stop
    } catch {
        Write-Error "Config file is not valid JSON: $ConfigPath"
        exit 1
    }

    $requiredKeys = @(
        'S3Endpoint', 'S3Region', 'S3Bucket', 'S3Prefix', 'S3AccessKey', 'S3SecretKey',
        'LocalCurrentDir', 'LocalArchiveDir', 'ArchiveAfter'
    )

    foreach ($key in $requiredKeys) {
        if ([string]::IsNullOrWhiteSpace([string]$config.$key)) {
            Write-Error "Config is missing required value: $key"
            exit 1
        }
    }

    return $config
}

function ConvertTo-CutoffDate {
    param(
        [Parameter(Mandatory = $true)][string]$RelativeAge
    )

    $trimmed = $RelativeAge.Trim()
    if ($trimmed -notmatch '^-(\d+)\s*(day|days|month|months|year|years)$') {
        throw "Invalid relative age '$RelativeAge'. Expected a format like '-1 month' or '-6 months'."
    }

    $amount = [int]$Matches[1]
    $unit = $Matches[2]

    if ($unit -match '^day') {
        return (Get-Date).AddDays(-$amount)
    } elseif ($unit -match '^month') {
        return (Get-Date).AddMonths(-$amount)
    } else {
        return (Get-Date).AddYears(-$amount)
    }
}

function Sync-BackupsFromS3 {
    param($Config)

    $env:AWS_ACCESS_KEY_ID = $Config.S3AccessKey
    $env:AWS_SECRET_ACCESS_KEY = $Config.S3SecretKey
    $env:AWS_DEFAULT_REGION = $Config.S3Region

    if (-not (Test-Path $Config.LocalCurrentDir)) {
        New-Item -ItemType Directory -Path $Config.LocalCurrentDir -Force | Out-Null
    }

    $remote = "s3://$($Config.S3Bucket)/$($Config.S3Prefix)"

    aws s3 sync $remote $Config.LocalCurrentDir --endpoint-url $Config.S3Endpoint

    if ($LASTEXITCODE -ne 0) {
        throw "aws s3 sync failed with exit code $LASTEXITCODE"
    }
}

# Moves files out of Current once they pass ArchiveAfter. Never deletes.
function Move-OldBackupsToArchive {
    param($Config)

    $cutoff = ConvertTo-CutoffDate -RelativeAge $Config.ArchiveAfter

    if (-not (Test-Path $Config.LocalArchiveDir)) {
        New-Item -ItemType Directory -Path $Config.LocalArchiveDir -Force | Out-Null
    }

    Get-ChildItem -Path $Config.LocalCurrentDir -File | Where-Object { $_.LastWriteTime -lt $cutoff } | ForEach-Object {
        Write-Host "Archiving: $($_.Name)"
        Move-Item -Path $_.FullName -Destination (Join-Path $Config.LocalArchiveDir $_.Name) -Force
    }
}

# Deletes only files already inside Archive, and only once past DeleteAfter.
# Current is never scanned or touched here. Skips entirely if DeleteAfter is unset.
function Remove-ExpiredArchivedBackups {
    param($Config)

    if ([string]::IsNullOrWhiteSpace([string]$Config.DeleteAfter)) {
        Write-Host "DeleteAfter not configured - skipping delete step, nothing will be deleted."
        return
    }

    $cutoff = ConvertTo-CutoffDate -RelativeAge $Config.DeleteAfter

    Get-ChildItem -Path $Config.LocalArchiveDir -File | Where-Object { $_.LastWriteTime -lt $cutoff } | ForEach-Object {
        Write-Host "Deleting expired archived backup: $($_.Name)"
        Remove-Item -Path $_.FullName -Force
    }
}

try {
    Write-Host "OfflineBox: loading config from $ConfigPath"
    $config = Get-OfflineBoxConfig -ConfigPath $ConfigPath

    Write-Host "OfflineBox: syncing backups from S3"
    Sync-BackupsFromS3 -Config $config

    Write-Host "OfflineBox: archiving backups older than $($config.ArchiveAfter)"
    Move-OldBackupsToArchive -Config $config

    Write-Host "OfflineBox: checking archived backups for deletion"
    Remove-ExpiredArchivedBackups -Config $config

    Write-Host "OfflineBox: done"
} catch {
    Write-Error "OfflineBox failed: $($_.Exception.Message)"
    exit 1
}
