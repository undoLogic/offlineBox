<#
OfflineBox by undoLogic
Copyright (c) 2021-2026 undoLogic Inc.

Licensed under the Apache License, Version 2.0.
See the LICENSE and NOTICE files in the project root.
This notice must be retained in copies or substantial portions of this file.

OfflineBox local client. Pulls backups from S3-compatible storage onto this
machine, then prunes the local copies. It never modifies the bucket.
Spec: README.md in this folder.

Usage:
    .\offlineBox.ps1
    .\offlineBox.ps1 -ConfigPath "D:\path\to\config.json"
#>

param(
    [string]$ConfigPath = (Join-Path $PSScriptRoot 'config.json')
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 2.0

$script:Invariant = [Globalization.CultureInfo]::InvariantCulture
$script:LogFile = $null

function Write-Log {
    param([string]$Message, [string]$SetName = '')

    $line = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss', $script:Invariant)
    if ($SetName) { $line += " [$SetName]" }
    $line += " $Message"

    Write-Host $line
    if ($script:LogFile) {
        [IO.File]::AppendAllText($script:LogFile, $line + "`r`n")
    }
}

function Test-HasValue {
    param($Object, [string]$Name)

    $property = $Object.PSObject.Properties[$Name]
    return ($null -ne $property) -and (-not [string]::IsNullOrWhiteSpace([string]$property.Value))
}

# A relative path would follow the starting folder, and Task Scheduler usually
# starts in System32. Windows also treats "\folder" and "D:folder" as relative.
function Assert-AbsolutePath {
    param([string]$Name, [string]$Path)

    $pattern = if ([IO.Path]::DirectorySeparatorChar -eq '/') { '^/' } else { '^([A-Za-z]:[\\/]|\\\\)' }
    if ($Path -notmatch $pattern) { throw "$Name must be an absolute path, like D:\OfflineBox\...: '$Path'" }
}

function Get-PositiveWholeNumber {
    param($Set, $Config, [string]$Name)

    $source = if (Test-HasValue $Set $Name) { $Set } else { $Config }
    if (-not (Test-HasValue $source $Name)) { throw "Config is missing $Name for set '$($Set.Name)'" }

    $value = $source.$Name
    if ((($value -isnot [int]) -and ($value -isnot [long])) -or ($value -lt 1)) {
        throw "$Name must be a whole number of 1 or more for set '$($Set.Name)'"
    }
    return [int]$value
}

# Returns one normalised object per backup set. Throws before anything is
# downloaded or deleted if the config can't be trusted.
function Get-ValidatedSets {
    param($Config)

    if (-not ($Config.PSObject.Properties['DryRun'] -and ($Config.DryRun -is [bool]))) {
        throw 'DryRun must be true or false, without quotes'
    }
    if (-not ($Config.PSObject.Properties['BackupSets'] -and @($Config.BackupSets).Count -gt 0)) {
        throw 'BackupSets must list at least one set'
    }

    $sets = @()
    $seenDirs = @{}
    foreach ($set in @($Config.BackupSets)) {
        foreach ($key in 'Name', 'S3Endpoint', 'S3Region', 'S3Bucket', 'S3AccessKey', 'S3SecretKey', 'LocalDir') {
            if (-not (Test-HasValue $set $key)) { throw "Config is missing $key in a backup set" }
        }
        # S3Prefix may be an empty string, but it must be stated.
        if (-not $set.PSObject.Properties['S3Prefix']) { throw "Config is missing S3Prefix for set '$($set.Name)'" }

        $pullDays = Get-PositiveWholeNumber $set $Config 'PullDays'
        $keepAllMonths = Get-PositiveWholeNumber $set $Config 'KeepAllMonths'

        # A month is at least 28 days, so a pruned file is always outside the pull window.
        if ($pullDays -ge ($keepAllMonths * 28)) {
            throw "PullDays ($pullDays) must be shorter than KeepAllMonths ($keepAllMonths month(s), counted as $($keepAllMonths * 28) days) for set '$($set.Name)'"
        }

        Assert-AbsolutePath 'LocalDir' $set.LocalDir

        # Every project's main connection is called "default", so sharing a folder
        # would make one project's files count as keepers for another's.
        $dirKey = [IO.Path]::GetFullPath($set.LocalDir).TrimEnd('\', '/').ToLowerInvariant()
        if ($seenDirs.ContainsKey($dirKey)) { throw "LocalDir '$($set.LocalDir)' is used by more than one set" }
        $seenDirs[$dirKey] = $true

        $sets += [pscustomobject]@{
            Name = $set.Name; Endpoint = $set.S3Endpoint; Region = $set.S3Region; Bucket = $set.S3Bucket
            Prefix = [string]$set.S3Prefix; AccessKey = $set.S3AccessKey; SecretKey = $set.S3SecretKey
            LocalDir = $set.LocalDir; PullDays = $pullDays; KeepAllMonths = $keepAllMonths
        }
    }
    return $sets
}

# Reads {YYYY-MM-DD}_{prefix}{database}_{HHMMSS}.sql. Anything else returns
# $null and is never pulled or pruned.
function Get-BackupInfo {
    param([string]$FileName)

    if ($FileName -notmatch '^(\d{4}-\d{2}-\d{2})_(.+)_(\d{6})\.sql$') { return $null }
    $dateText = $Matches[1]; $database = $Matches[2]; $version = $Matches[3]

    $date = [datetime]::MinValue
    $isDate = [datetime]::TryParseExact($dateText, 'yyyy-MM-dd', $script:Invariant, [Globalization.DateTimeStyles]::None, [ref]$date)
    if (-not $isDate) { return $null }

    return [pscustomobject]@{ Name = $FileName; Date = $date; Database = $database; Version = $version }
}

# Runs the AWS CLI and returns its stdout lines. Throws its stderr on failure.
function Invoke-Aws {
    param([string[]]$AwsArgs)

    # Windows PowerShell turns native stderr into errors, which 'Stop' would treat as fatal.
    $ErrorActionPreference = 'Continue'
    $output = @(& aws @AwsArgs 2>&1)
    $exitCode = $LASTEXITCODE

    $errors = @($output | Where-Object { $_ -is [Management.Automation.ErrorRecord] })
    if ($exitCode -ne 0) {
        # A blank stderr line surfaces as this type name rather than as empty text.
        $lines = @($errors | ForEach-Object { $_.ToString() } | Where-Object { $_ -ne 'System.Management.Automation.RemoteException' })
        $message = ($lines -join ' ') -replace '\s+', ' '
        throw "AWS CLI exit code ${exitCode}: $($message.Trim())"
    }
    return @($output | Where-Object { $_ -isnot [Management.Automation.ErrorRecord] } | ForEach-Object { [string]$_ })
}

# Lists text rows rather than JSON: Windows PowerShell's ConvertFrom-Json has a
# size limit a long-lived bucket could reach.
function Get-RemoteBackups {
    param($Set)

    $awsArgs = @(
        's3api', 'list-objects-v2', '--bucket', $Set.Bucket, '--endpoint-url', $Set.Endpoint, '--region', $Set.Region,
        '--output', 'text', '--query', 'Contents[].[Key,Size]'
    )
    # Windows PowerShell drops empty arguments, which would break "--prefix ''".
    if ($Set.Prefix -ne '') { $awsArgs += @('--prefix', $Set.Prefix) }

    $backups = @()
    foreach ($row in (Invoke-Aws $awsArgs)) {
        if ($row -notmatch '^(.+)\t(\d+)\s*$') { continue }
        $key = $Matches[1]; $size = [long]$Matches[2]

        $info = Get-BackupInfo ($key -split '/')[-1]
        if ($info) {
            $info | Add-Member -NotePropertyName Key -NotePropertyValue $key
            $info | Add-Member -NotePropertyName Size -NotePropertyValue $size
            $backups += $info
        }
    }
    return $backups
}

function Format-Size {
    param([long]$Bytes)

    return ($Bytes / 1MB).ToString('0.0', $script:Invariant) + ' MB'
}

# Downloads to a .part file first, so a cut-off download never looks like a
# finished backup. Returns how many files were downloaded.
function Invoke-Pull {
    param($Set)

    $oldest = (Get-Date).Date.AddDays(-$Set.PullDays)
    $wanted = @(Get-RemoteBackups $Set | Where-Object { $_.Date -ge $oldest } | Sort-Object Date, Version -Descending)

    $pulled = 0
    foreach ($backup in $wanted) {
        $target = Join-Path $Set.LocalDir $backup.Name
        if (Test-Path -LiteralPath $target) {
            Write-Log "SKIP $($backup.Name) (already on disk)" $Set.Name
            continue
        }

        $partial = "$target.part"
        $null = Invoke-Aws @(
            's3', 'cp', "s3://$($Set.Bucket)/$($backup.Key)", $partial, '--endpoint-url', $Set.Endpoint,
            '--region', $Set.Region, '--only-show-errors', '--no-progress'
        )

        $actual = (Get-Item -LiteralPath $partial).Length
        if ($actual -ne $backup.Size) {
            throw "$($backup.Name) downloaded $actual bytes, expected $($backup.Size)"
        }
        Move-Item -LiteralPath $partial -Destination $target

        Write-Log "PULL $($backup.Name) ($(Format-Size $actual))" $Set.Name
        $pulled++
    }
    return $pulled
}

function Get-PrunableFiles {
    param($Set)

    $cutoff = (Get-Date).Date.AddMonths(-$Set.KeepAllMonths)
    foreach ($file in (Get-ChildItem -LiteralPath $Set.LocalDir -File)) {
        $info = Get-BackupInfo $file.Name
        if ($info -and ($info.Date -lt $cutoff)) {
            $info | Add-Member -NotePropertyName Path -NotePropertyValue $file.FullName
            $info | Add-Member -NotePropertyName Size -NotePropertyValue $file.Length
            $info
        }
    }
}

# Keeps the last non-empty file per database per month. A month with no
# non-empty file is left alone.
function Invoke-Prune {
    param($Set, [bool]$DryRun)

    $groups = @(Get-PrunableFiles $Set | Group-Object -CaseSensitive -Property {
        $_.Database + '|' + $_.Date.ToString('yyyy-MM', $script:Invariant)
    })

    foreach ($group in $groups) {
        $files = @($group.Group | Sort-Object Date, Version -Descending)
        $month = $files[0].Date.ToString('MMMM yyyy', $script:Invariant)
        $keeper = @($files | Where-Object { $_.Size -gt 0 }) | Select-Object -First 1

        if (-not $keeper) {
            Write-Log "WARN $($files[0].Database) $month has no non-empty file; nothing deleted" $Set.Name
            continue
        }

        Write-Log "KEEP $($keeper.Name) ($month keeper)" $Set.Name
        foreach ($file in @($files | Where-Object { $_.Name -cne $keeper.Name })) {
            if ($DryRun) {
                Write-Log "PRUNE $($file.Name) (DryRun)" $Set.Name
            } else {
                Remove-Item -LiteralPath $file.Path
                Write-Log "PRUNE $($file.Name)" $Set.Name
            }
        }
    }
}

function Invoke-BackupSet {
    param($Set, [bool]$DryRun)

    $env:AWS_ACCESS_KEY_ID = $Set.AccessKey
    $env:AWS_SECRET_ACCESS_KEY = $Set.SecretKey
    # Leftovers from this machine's own AWS setup must not change which account is used.
    Remove-Item Env:AWS_SESSION_TOKEN, Env:AWS_PROFILE -ErrorAction SilentlyContinue
    # Newer AWS CLIs add checksums that some S3-compatible providers don't support.
    $env:AWS_REQUEST_CHECKSUM_CALCULATION = 'when_required'
    $env:AWS_RESPONSE_CHECKSUM_VALIDATION = 'when_required'

    if (-not (Test-Path -LiteralPath $Set.LocalDir)) {
        $null = New-Item -ItemType Directory -Path $Set.LocalDir -Force
    }
    Get-ChildItem -LiteralPath $Set.LocalDir -File -Filter '*.part' | Remove-Item

    $pulled = Invoke-Pull $Set
    if ($pulled -eq 0) {
        Write-Log 'WARN Nothing new pulled; pruning skipped' $Set.Name
        return
    }
    Invoke-Prune $Set $DryRun
}

function Open-Log {
    param($Config)

    if (-not (Test-HasValue $Config 'LogFile')) { throw 'Config is missing LogFile' }

    Assert-AbsolutePath 'LogFile' $Config.LogFile

    $folder = Split-Path -Parent $Config.LogFile
    if ($folder -and -not (Test-Path -LiteralPath $folder)) {
        $null = New-Item -ItemType Directory -Path $folder -Force
    }
    $script:LogFile = $Config.LogFile
}

# ---------------------------------------------------------------------------

try {
    if (-not (Test-Path -LiteralPath $ConfigPath)) { throw "Config file not found: $ConfigPath" }
    $config = Get-Content -LiteralPath $ConfigPath -Raw | ConvertFrom-Json
    Open-Log $config
    $sets = @(Get-ValidatedSets $config)
    if (-not (Get-Command aws -ErrorAction SilentlyContinue)) { throw 'AWS CLI not found on PATH' }
} catch {
    Write-Log "ERROR Refusing to start: $($_.Exception.Message)"
    exit 1
}

$failed = 0
foreach ($set in $sets) {
    try {
        Invoke-BackupSet $set $config.DryRun
    } catch {
        $failed++
        Write-Log "ERROR $($_.Exception.Message); skipping set" $set.Name
    }
}

Write-Log "DONE $failed of $($sets.Count) sets failed"
if ($failed -gt 0) { exit 1 }
exit 0
