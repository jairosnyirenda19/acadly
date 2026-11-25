#!/usr/bin/env bash
# Restores one school from a backup folder (D-112).
#
#   ACADLY_BACKUP_ENV=/etc/acadly/sunrise.backup.env \
#     infra/backup/acadly-restore.sh /var/backups/acadly/sunrise/20261125T0100Z [target_database]
#
# Before: run infra/database/provision.sql for the target database (it makes
# an empty database with the two roles and the extensions).
# After: put the school's .env (with its two keys) in place, then run
#   php artisan acadly:sync && php artisan acadly:preflight
#
# It restores into an EMPTY database only. It never overwrites a live one.
set -euo pipefail

from="${1:?Give the backup folder.}"
: "${ACADLY_BACKUP_ENV:?Set ACADLY_BACKUP_ENV to the school backup settings file.}"
# shellcheck disable=SC1090
source "$ACADLY_BACKUP_ENV"
export PGHOST="${PGHOST:-127.0.0.1}" PGPORT="${PGPORT:-5432}" PGUSER PGPASSWORD
db="${2:-$PGDATABASE}"
restore_documents="${RESTORE_DOCUMENTS_TO:-$APP_DIR/storage/app}"

# 1. The copy is whole.
( cd "$from" && sha256sum --check --quiet SHA256SUMS )

# 2. The target is empty.
tables="$(psql --dbname="$db" --tuples-only --no-align --command="SELECT count(*) FROM pg_tables WHERE schemaname = 'public'")"
if [ "$tables" != "0" ]; then
  echo "The database $db is not empty ($tables tables). Restore only into a new, empty database." >&2
  exit 1
fi

# 3. The database, as the owner, with every table's rights. The schema itself was
#    made by provision.sql, so its own entries in the backup are left out.
list="$(mktemp)"
trap 'rm -f "$list"' EXIT
pg_restore --list "$from/database.dump" | grep -v -E ' (SCHEMA|COMMENT) - (public|SCHEMA public) ' > "$list"
pg_restore --dbname="$db" --no-owner --exit-on-error --single-transaction --use-list="$list" "$from/database.dump"

# 4. The documents and the audit seals.
mkdir -p "$restore_documents"
tar --extract --gzip --file="$from/documents.tar.gz" --directory="$restore_documents"
if [ -f "$from/audit-seals.tar.gz" ]; then
  mkdir -p "$restore_documents/private"
  tar --extract --gzip --file="$from/audit-seals.tar.gz" --directory="$restore_documents/private"
fi

echo "Restored into $db. Next: put the school's .env in place, then php artisan acadly:sync && php artisan acadly:preflight && php artisan audit:verify"
