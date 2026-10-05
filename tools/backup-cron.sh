#!/usr/bin/env bash
#
# Cron wrapper for tools/backup.sh - runs every 6 hours.
#
# Three things the schedule needs that the backup script does not do itself:
#   1. The target is an external drive. If it is not mounted, writing would land in
#      the mountpoint directory on the root disk instead, quietly filling it up.
#      So verify the mount before doing anything.
#   2. Overlap. A backup takes minutes; if one is somehow still running when the next
#      fires, two would write the same timestamped directory.
#   3. Pruning. Each backup is ~366M and the schedule produces 4 a day, so without
#      pruning this fills the drive within a few months.
set -uo pipefail

BASE="${BACKUP_BASE:-/run/media/jatt/9890EF3990EF1C8E/backups}"
REPO="${REPO:-/home/jatt/BookStore-v2}"
KEEP="${BACKUP_KEEP:-10}"
LOCK=/tmp/bookstore-backup.lock

# Some schedulers invoke with a stripped environment. `set -u` then aborts on $HOME
# before any work happens, which would look like a silent cron failure.
: "${HOME:=/home/jatt}"
export HOME

export PATH="/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin"

mkdir -p "${XDG_STATE_HOME:-$HOME/.local/state}/bookstore" 2>/dev/null
LOG="${XDG_STATE_HOME:-$HOME/.local/state}/bookstore/backup.log"

say() { printf '%s  %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" | tee -a "$LOG"; }

# 1. Is the target actually on the external drive?
#
# Not a `mountpoint -q $BASE` test: $BASE is a plain directory *inside* the mount,
# so that check is always false and would silently skip every backup. What actually
# matters is that $BASE is not on the root filesystem - that is the failure mode
# where the drive is absent and writes land in the empty mountpoint on / instead.
base_dev=$(df --output=source "$BASE" 2>/dev/null | tail -1)
root_dev=$(df --output=source / 2>/dev/null | tail -1)
if [ -z "$base_dev" ] || [ -z "$root_dev" ]; then
  say "SKIP: cannot determine filesystem for $BASE"
  exit 0
fi
if [ "$base_dev" = "$root_dev" ]; then
  say "SKIP: $BASE is on the root filesystem ($root_dev) - the backup drive is not mounted"
  exit 0
fi

avail_gb=$(df --output=avail -BG "$BASE" | tail -1 | tr -dc '0-9')
if [ "${avail_gb:-0}" -lt 5 ]; then
  say "SKIP: only ${avail_gb}G free on the backup drive - pruning first, then reconsider"
fi

# 2. never run two at once
exec 9>"$LOCK"
if ! flock -n 9; then
  say "SKIP: a backup is already running"
  exit 0
fi

say "START backup (keep last $KEEP)"

out=$(BACKUP_BASE="$BASE" REPO="$REPO" bash "$REPO/tools/backup.sh" 2>&1)
rc=$?
printf '%s\n' "$out" | tail -12 | tee -a "$LOG"

if [ $rc -ne 0 ]; then
  say "FAIL: backup.sh exited $rc"
  exit $rc
fi

# 3. prune old backups, newest first
mapfile -t dirs < <(find "$BASE" -maxdepth 1 -type d -name 'bookstore-*' | sort -r)
removed=0
for d in "${dirs[@]:$KEEP}"; do
  [ -z "$d" ] && continue
  rm -rf "$d" && removed=$((removed + 1))
done

say "DONE: ${#dirs[@]} backup(s) on disk, pruned $removed old"
df -h "$BASE" | tail -1 | awk '{print "       free: " $4}' | tee -a "$LOG"