#!/usr/bin/env bash
# Fetch Archive.org /metadata JSON into the harvest cache, 8 at a time.
# P=8 measured fastest against archive.org (P=24 throttles worse). Resumable:
# re-run and it only fetches identifiers with no valid cached file.
set -uo pipefail

CACHE_DIR=${CACHE_DIR:-/tmp/opencode/ia-cache}
IDS=${1:-/tmp/opencode/ia-missing.txt}
JOBS=${JOBS:-8}

mkdir -p "$CACHE_DIR"

fetch_one() {
  id=$1
  out="$CACHE_DIR/$id.json"
  if [ -s "$out" ] && grep -q '"metadata"' "$out" 2>/dev/null; then
    exit 0
  fi
  tmp="$out.part"
  if curl -sSf --max-time 90 -o "$tmp" "https://archive.org/metadata/$id" 2>/dev/null \
     && [ -s "$tmp" ] && grep -q '"metadata"' "$tmp" 2>/dev/null; then
    mv "$tmp" "$out"
  else
    rm -f "$tmp"
    echo "FAIL $id"
  fi
}
export -f fetch_one
export CACHE_DIR

grep -v '^[[:space:]]*$' "$IDS" | xargs -P "$JOBS" -I{} bash -c 'fetch_one "$@"' _ {}
