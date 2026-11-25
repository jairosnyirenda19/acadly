#!/usr/bin/env bash
# Puts a release in place for one school (D-112). Run as root on the school's server:
#
#   infra/release.sh sunrise v2026.12.1
#
# Layout:
#   /srv/acadly/<school>/releases/<tag>   one folder per release (a clean checkout of the tag)
#   /srv/acadly/<school>/shared/.env      the school's settings
#   /srv/acadly/<school>/shared/storage   documents, logs, audit seals
#   /srv/acadly/<school>/shared/python    the document engine's Python
#   /srv/acadly/<school>/current          the release in use
#
# Steps: build, check, migrate as the owner, sync, switch, restart the workers, check again.
# If a step fails before the switch, the school keeps running the release it had.
set -euo pipefail

school="${1:?Give the school, e.g. sunrise.}"
tag="${2:?Give the release tag, e.g. v2026.12.1.}"
repo="${ACADLY_REPO:-https://github.com/jairosnyirenda19/acadly.git}"
base="/srv/acadly/$school"
user="acadly-$school"
release="$base/releases/$tag"
as() { runuser -u "$user" -- "$@"; }

[ -f "$base/shared/.env" ] || { echo "Missing $base/shared/.env. Copy app.backend/.env.production.example and fill it in." >&2; exit 1; }

# 1. A clean checkout of the tag.
if [ ! -d "$release" ]; then
  as git clone --quiet --depth 1 --branch "$tag" "$repo" "$release"
fi
ln -sfn "$base/shared/.env" "$release/app.backend/.env"
rm -rf "$release/app.backend/storage"
ln -sfn "$base/shared/storage" "$release/app.backend/storage"

# 2. Build: the API's packages, the three apps, the document engine.
cd "$release/app.backend"
as composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --quiet
cd "$release/app.frontend"
as corepack pnpm install --frozen-lockfile --silent
as corepack pnpm build
[ -x "$base/shared/python/bin/python" ] || as python3 -m venv "$base/shared/python"
as "$base/shared/python/bin/pip" install --quiet -r "$release/app.backend/workers/requirements.txt"

# 3. Bring the database into line, as the owner. The school keeps working meanwhile:
#    migrations only add (columns, tables), never remove what the running release reads.
cd "$release/app.backend"
as sed -i "s/^ACADLY_VERSION=.*/ACADLY_VERSION=$tag/" "$base/shared/.env"
as php artisan migrate --database=pgsql_owner --force
as php artisan acadly:sync
as php artisan config:cache
as php artisan route:cache
as php artisan event:cache

# 4. Check this release before it serves anyone.
as php artisan acadly:preflight

# 5. Switch, then restart PHP and the queue worker on the new code.
ln -sfn "$release" "$base/current"
systemctl reload php8.4-fpm
as php artisan queue:restart
systemctl restart "acadly-queue@$school"

# 6. Check again with the workers running; keep the five newest releases.
sleep 70
as php artisan acadly:preflight --running
ls -1dt "$base/releases"/*/ | tail -n +6 | xargs -r rm -rf
echo "School $school now runs $tag."
