#!/usr/bin/env bash
# Screenshot a captured page at the reference's exact viewport.
#
# Headless, NOT the in-app browser pane: the pane only composites frames while
# it is actually displayed and silently returns stale or partial ones when it
# is not, which makes it useless for verification.
#
# A DISPOSABLE profile per run is load-bearing. Chrome keeps a lock on its
# user-data-dir, and a run that is killed or times out leaves the lock behind
# - every later run then hangs forever waiting for it. A unique directory per
# invocation cannot collide, and the whole tree is removed afterwards.
set -euo pipefail

PAGE="${1:-dashboard}"
OUT="${2:?usage: shoot.sh <page> <out.png> [width] [height]}"
W="${3:-1536}"
H="${4:-1024}"
PORT="${PORT:-8940}"

CHROME="/c/Program Files/Google/Chrome/Application/chrome.exe"
PROFILE="$(mktemp -d)"
trap 'rm -rf "$PROFILE" 2>/dev/null || true' EXIT

"$CHROME" --headless=new --disable-gpu --hide-scrollbars \
    --no-first-run --no-default-browser-check --disable-extensions \
    --disable-background-networking --disable-sync --disable-crash-reporter \
    --force-device-scale-factor=1 --window-size="${W},${H}" \
    --user-data-dir="$PROFILE" --virtual-time-budget=6000 \
    --screenshot="$OUT" "http://localhost:${PORT}/__compare/${PAGE}.html" \
    2>&1 | grep -iE 'written|error' || true

[ -f "$OUT" ] || { echo "no screenshot written" >&2; exit 1; }
