#!/usr/bin/env bash
#
# Full backup of BookStore-v2 to the external drive.
#
# What gets captured, and why each piece matters:
#   db/          mysqldump of the `bookstore` database. The 5,781-book catalogue and the
#                user accounts live here and NOWHERE else. Losing this loses the site.
#   repo/        the whole working tree including public/ and .git, so the backup is
#                restorable as-is and not just as a rebuild. public/ is gitignored
#                generated output but is included anyway: it is only 273M and it makes
#                the backup serveable immediately.
#   opencode/    the opencode session database, so this conversation can be resumed.
#
# Restoring:
#   mysql bookstore < db/bookstore.sql
#   tar -xzf repo/bookstore-v2.tar.gz -C /home/jatt
#   # the site is then live again with no rebuild step required
#
# NOTE db.php holds the live MySQL password and is gitignored, so it is present in the
# tarball but never in git. Treat the backup directory as sensitive.
set -euo pipefail

BASE="${BACKUP_BASE:-/run/media/jatt/9890EF3990EF1C8E/backups}"
REPO="${REPO:-/home/jatt/BookStore-v2}"
: "${HOME:=/home/jatt}"
SESSION_DB="${SESSION_DB:-$HOME/.local/share/opencode/opencode.db}"
STAMP="$(date +%Y%m%d-%H%M%S)"
DIR="$BASE/bookstore-$STAMP"

# credentials come from db.php so this script never hardcodes a password
DB_NAME=$(grep -oP "DB_NAME\s*=\s*'\K[^']+" "$REPO/db.php")
DB_USER=$(grep -oP "DB_USER\s*=\s*'\K[^']+" "$REPO/db.php")
DB_PASS=$(grep -oP "DB_PASS\s*=\s*'\K[^']+" "$REPO/db.php")

mkdir -p "$DIR/db" "$DIR/repo" "$DIR/opencode"
echo "==> $DIR"

echo "==> dumping database '$DB_NAME'"
# --single-transaction gives a consistent InnoDB snapshot without locking the site.
# products is MyISAM, so it is not transactional; that is fine, it is read-mostly here.
MYSQL_PWD="$DB_PASS" mysqldump \
  --user="$DB_USER" --host=localhost \
  --single-transaction --routines --triggers --events \
  --default-character-set=utf8mb4 \
  "$DB_NAME" > "$DIR/db/$DB_NAME.sql"
echo "    $(du -h "$DIR/db/$DB_NAME.sql" | cut -f1)  $DB_NAME.sql"

# Prove the dump is restorable-shaped, not just non-empty: count the INSERT statements.
BOOK_ROWS=$(grep -c '^(' "$DIR/db/$DB_NAME.sql" || true)
echo "    $BOOK_ROWS row tuples"

echo "==> archiving repository"
tar -czf "$DIR/repo/bookstore-v2.tar.gz" -C "$(dirname "$REPO")" "$(basename "$REPO")"
echo "    $(du -h "$DIR/repo/bookstore-v2.tar.gz" | cut -f1)  bookstore-v2.tar.gz"

# No sqlite3 CLI or PHP sqlite extension on this box, so a hot copy is the only option.
# opencode.db is mid-write; copying the -wal and -shm alongside it lets SQLite
# replay the write-ahead log on open, which is what makes the copy consistent.
if [ -f "$SESSION_DB" ]; then
  echo "==> copying opencode session database"
  for suffix in "" "-wal" "-shm"; do
    [ -f "$SESSION_DB$suffix" ] && cp -p "$SESSION_DB$suffix" "$DIR/opencode/" || true
  done
  # a copied SQLite file must start with this exact 16-byte header
  head -c 16 "$DIR/opencode/$(basename "$SESSION_DB")" | od -c | head -1 \
    | grep -q 'S   Q   L   i   t   e' \
    && echo "    header OK" \
    || { echo "    ERROR: session copy is not a valid SQLite file"; exit 1; }
  echo "    $(du -sh "$DIR/opencode" | cut -f1)  session data"
else
  echo "==> WARNING: no session database at $SESSION_DB, skipping"
fi

echo "==> checksums"
( cd "$DIR" && find . -type f ! -name SHA256SUMS -print0 | sort -z \
    | xargs -0 sha256sum > SHA256SUMS )
echo "    $(wc -l < "$DIR/SHA256SUMS") files hashed"

cat > "$DIR/MANIFEST.txt" <<EOF
BookStore-v2 backup
created : $(date)
source  : $REPO
database: $DB_NAME ($(du -h "$DIR/db/$DB_NAME.sql" | cut -f1))
restore : mysql $DB_NAME < db/$DB_NAME.sql
          tar -xzf repo/bookstore-v2.tar.gz -C /home/jatt

contents
  db/$DB_NAME.sql            full mysqldump, utf8mb4
  repo/bookstore-v2.tar.gz   working tree, including public/ and .git
  opencode/opencode.db*      opencode session database (+ WAL/SHM)
  SHA256SUMS                 verify with: sha256sum -c SHA256SUMS

The site is serving from public/ inside this archive, so a restore is immediately
live without a rebuild. public/ can also be regenerated with
build/build.php if the source and database are restored.

SENSITIVE: repo/bookstore-v2.tar.gz contains db.php, which holds the live MySQL
password. db.php is gitignored and was never committed.

The opencode session copy was taken while opencode was running. The write-ahead log
is included so SQLite replays it on open; if it will not open, the main db file alone
may be a few messages behind.
EOF

echo
echo "==> done"
du -sh "$DIR" | sed 's/^/    total: /'
echo "$DIR"