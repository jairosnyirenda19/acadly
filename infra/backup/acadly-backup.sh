#!/usr/bin/env bash
# Backs up one school (D-112): the database and the documents, then copies
# both off this server. Run nightly by acadly-backup.timer.
#
#   ACADLY_BACKUP_ENV=/etc/acadly/sunrise.backup.env infra/backup/acadly-backup.sh
#
# The settings file holds (see backup.env.example):
#   SCHOOL            short name, used in file names
#   APP_DIR           the release folder (holds storage/app/documents)
#   PGHOST PGPORT PGDATABASE PGUSER PGPASSWORD   the owner role (it can read every table)
#   BACKUP_DIR        where backups are kept on this server
#   OFFSITE           an rclone remote and folder, e.g. "offsite:acadly/sunrise"
#   KEEP_DAILY        nightly backups to keep here (default 14)
#
# The two keys (APP_KEY, ACADLY_DOCUMENTS_KEY) are NOT in the backup. Keep a
# copy of the school's .env in the password store: without it, a restored
# school cannot read its documents.
set -euo pipefail

: "${ACADLY_BACKUP_ENV:?Set ACADLY_BACKUP_ENV to the school backup settings file.}"
# shellcheck disable=SC1090
source "$ACADLY_BACKUP_ENV"
: "${SCHOOL:?}" "${APP_DIR:?}" "${PGDATABASE:?}" "${PGUSER:?}" "${BACKUP_DIR:?}"
export PGHOST="${PGHOST:-127.0.0.1}" PGPORT="${PGPORT:-5432}" PGDATABASE PGUSER PGPASSWORD
KEEP_DAILY="${KEEP_DAILY:-14}"

stamp="$(date -u +%Y%m%dT%H%MZ)"
target="$BACKUP_DIR/$SCHOOL/$stamp"
mkdir -p "$target"
chmod 700 "$BACKUP_DIR/$SCHOOL"

# 1. The database: the public schema in custom format, with every table's
#    rights, so append-only tables stay append-only after a restore.
pg_dump --format=custom --compress=6 --schema=public --no-owner --file="$target/database.dump"

# 2. The documents: stored encrypted already; copied as they are.
tar --create --gzip --file="$target/documents.tar.gz" --directory="$APP_DIR/storage/app" documents

# 3. The audit seals, which prove the history was not changed.
if [ -d "$APP_DIR/storage/app/private/audit-seals" ]; then
  tar --create --gzip --file="$target/audit-seals.tar.gz" --directory="$APP_DIR/storage/app/private" audit-seals
fi

# 4. A check file, so a restore can tell a broken copy.
( cd "$target" && sha256sum ./* > SHA256SUMS )

# 5. Off this server.
if [ -n "${OFFSITE:-}" ]; then
  rclone copy "$target" "$OFFSITE/$stamp" --immutable
fi

# 6. Keep the newest KEEP_DAILY here; the off-site copy keeps its own history.
ls -1dt "$BACKUP_DIR/$SCHOOL"/*/ | tail -n +"$((KEEP_DAILY + 1))" | xargs -r rm -rf

echo "Backup $SCHOOL $stamp done."
