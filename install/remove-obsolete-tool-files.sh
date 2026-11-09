#!/bin/sh
set -eu

PLUGIN_DIR=$(CDPATH= cd "$(dirname "$0")/.." && pwd)
if [ ! -f "$PLUGIN_DIR/base3.json" ]; then
    echo 'AgentArena manifest not found; no files removed.' >&2
    exit 1
fi

for filename in ArenaToolProfileProvider.php ArenaToolSet.php ArenaGetStateAgentTool.php ArenaSubmitTurnAgentTool.php; do
    obsolete="$PLUGIN_DIR/src/Tool/$filename"
    if [ -f "$obsolete" ]; then
        rm -f "$obsolete"
        printf 'Removed obsolete tool implementation: %s\n' "$obsolete"
    fi
done

echo 'AgentArena tool cleanup complete.'
