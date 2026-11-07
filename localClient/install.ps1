<#
OfflineBox by undoLogic
Copyright (c) 2021-2026 undoLogic Inc.

Licensed under the Apache License, Version 2.0.
See the LICENSE and NOTICE files in the project root.
This notice must be retained in copies or substantial portions of this file.

Downloads the OfflineBox local client into the folder holding this script.
Run it again to update. An existing config.json is never overwritten.
Spec: README.md in this folder.

Usage:
    powershell -ExecutionPolicy Bypass -File .\install.ps1
#>

$ErrorActionPreference = 'Stop'

$baseUrl = 'https://raw.githubusercontent.com/undoLogic/setupCase-core/main/offlineBox/localClient'
$files = 'offlineBox.ps1', 'config.example.json', 'README.md'

# Windows PowerShell may default to a TLS version GitHub refuses.
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

try {
    foreach ($file in $files) {
        # Download beside the target first, so a failure leaves the working copy in place.
        $target = Join-Path $PSScriptRoot $file
        Invoke-WebRequest -UseBasicParsing -Uri "$baseUrl/$file" -OutFile "$target.part"
        Move-Item -LiteralPath "$target.part" -Destination $target -Force
        Write-Host "Downloaded $target"
    }

    $config = Join-Path $PSScriptRoot 'config.json'
    if (Test-Path -LiteralPath $config) {
        Write-Host "Kept your existing $config"
    } else {
        Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'config.example.json') -Destination $config
        Write-Host "Created $config. Fill in your read-only keys and absolute paths."
    }
} catch {
    Write-Host "Download failed: $($_.Exception.Message)"
    exit 1
}
