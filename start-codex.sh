#!/usr/bin/env bash

set -u

# SetupCase Codex launcher
#
# Purpose:
# 1. Download the canonical AGENTS.md from the configured source.
# 2. Never overwrite the local AGENTS.md with an empty/failed download.
# 3. Replace the local file only when the remote version has changed.
# 4. Launch Codex from the SetupCase project root.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
AGENTS_FILE="${SCRIPT_DIR}/AGENTS.md"

# TODO: Replace this with the canonical SetupCase AGENTS.md URL.
AGENTS_URL="https://raw.githubusercontent.com/undoLogic/setupCase-core/refs/heads/main/AGENTS.md"

# Codex CLI command.
# Change this only if your Codex executable uses a different command/path.
CODEX_COMMAND="codex"

TEMP_FILE="$(mktemp)"
trap 'rm -f "$TEMP_FILE"' EXIT

echo "SetupCase: checking AGENTS.md..."

if curl --fail --silent --show-error --location "$AGENTS_URL" --output "$TEMP_FILE"; then

    if [[ ! -s "$TEMP_FILE" ]]; then
        echo "WARNING: Downloaded AGENTS.md is empty."
        echo "Keeping the existing local AGENTS.md."

    elif [[ -f "$AGENTS_FILE" ]] && cmp --silent "$TEMP_FILE" "$AGENTS_FILE"; then
        echo "AGENTS.md is already up to date."

    else
        mv "$TEMP_FILE" "$AGENTS_FILE"
        chmod 644 "$AGENTS_FILE"

        echo "AGENTS.md updated."
    fi

else
    echo "WARNING: Unable to download AGENTS.md."
    echo "Keeping the existing local AGENTS.md."
fi

echo
echo "Starting Codex..."
echo

cd "$SCRIPT_DIR" || exit 1

exec "$CODEX_COMMAND" "$@"
