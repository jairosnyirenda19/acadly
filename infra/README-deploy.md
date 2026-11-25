# Running a school

One school has its own application, database and storage (D-001). This guide puts one school on one Linux server (Ubuntu 24.04 LTS) and keeps it running (D-112). Several schools can share a server: each has its own folder, user, PHP pool, services and database.

## 1. What the server needs

| Part | Why |
|---|---|
| nginx | serves the three apps and passes `/api/` to PHP |
| PHP 8.4 FPM and CLI, with `pgsql`, `intl`, `sodium`, `zip`, `gd` | the API |
| Composer, Node.js 22 with corepack | building a release |
| PostgreSQL 17+ | the database (may be another server) |
| Python 3.12 with `venv` | the document engine (report cards, receipts, transcripts) |
| ClamAV daemon (`clamav-daemon`) | every upload is scanned; with no scanner, uploads are refused |
| LibreOffice (`libreoffice-core`) | page previews of Office files (optional) |
| certbot | HTTPS certificates |
| rclone | the off-site copy of backups |

## 2. One school, once

```sh
school=sunrise
adduser --system --group --home /srv/acadly/$school acadly-$school
mkdir -p /srv/acadly/$school/{releases,shared/storage/{app/documents,app/private,framework/{cache,sessions,views},logs}}
chown -R acadly-$school: /srv/acadly/$school
```

1. **Database**: run `infra/database/provision.sql` (see `README-database.md`).
2. **Settings**: copy `app.backend/.env.production.example` to `/srv/acadly/$school/shared/.env`, `chmod 600`, and fill it in. Make `APP_KEY` with `php artisan key:generate --show` and `ACADLY_DOCUMENTS_KEY` with `head -c 32 /dev/urandom | base64`. **Put a copy of the whole file in the password store now.** The two keys cannot be made again.
3. **PHP pool**: `infra/php-fpm/acadly-school.conf.example` to `/etc/php/8.4/fpm/pool.d/acadly-$school.conf`.
4. **Web server**: `infra/nginx/snippets/acadly-app.conf` to `/etc/nginx/snippets/`, and `infra/nginx/acadly-school.conf.example` to `/etc/nginx/sites-available/$school.conf`. Replace the host names, link it into `sites-enabled`, run `certbot --nginx`, then `nginx -t && systemctl reload nginx`.
5. **First release**: `infra/release.sh $school v2026.12.1` (section 3).
6. **First administrator**: `runuser -u acadly-$school -- php /srv/acadly/$school/current/app.backend/artisan acadly:administrator`, then `acadly:licence` with the school's licence.
7. **Services**:
   ```sh
   cp infra/systemd/acadly-*@.* /etc/systemd/system/ && systemctl daemon-reload
   systemctl enable --now acadly-queue@$school acadly-scheduler@$school.timer acadly-backup@$school.timer
   cp infra/logrotate/acadly /etc/logrotate.d/acadly
   ```
8. **Backups**: `infra/backup/backup.env.example` to `/etc/acadly/$school.backup.env`, `chmod 600`, owned by `acadly-$school`. Set up the rclone remote for `OFFSITE` (a bucket with object lock is best). Run one backup by hand: `systemctl start acadly-backup@$school` and check the off-site copy.
9. **Check**: `php artisan acadly:preflight --running` must end with "The server is ready for a school."

## 3. A release

```sh
infra/release.sh sunrise v2026.12.1
```

It checks out the tag in a new folder, builds the API, the three apps and the document engine, migrates as the owner, syncs, caches the settings, runs `acadly:preflight`, then switches `current`, reloads PHP, restarts the queue worker, and checks again with the workers running. If a step fails before the switch, the school keeps the release it had. Migrations only add; a release never removes what the running one reads.

## 4. What keeps running

| Service | Does | If it stops |
|---|---|---|
| `acadly-queue@school` | text messages, email, page previews, events | nothing reaches families |
| `acadly-scheduler@school.timer` | every minute: events, the message sweep, the heartbeat; each night: audit seal (01:00), document purge (02:00), exports purge (02:30), year start (00:30) | messages wait, history is not sealed |
| `acadly-backup@school.timer` | each night at 03:15: database, documents, audit seals, copied off the server | no backup |

`GET /api/v1/ready` answers `503` when the scheduler or the queue worker has stopped, when the database is behind the release, or when the application could change the database. Point the uptime monitor at it. `GET /api/v1/health` answers whether the API is up at all.

## 5. Backups and restore

`infra/backup/acadly-backup.sh` keeps, each night:

- `database.dump`: the whole school, with every table's rights (history stays append-only after a restore),
- `documents.tar.gz`: the documents, still encrypted with the school's key,
- `audit-seals.tar.gz`: the seals that prove the history was not changed,
- `SHA256SUMS`: so a broken copy is found before it is restored.

The newest 14 stay on the server; the off-site copy keeps its own history. The keys are not in the backup: they are in the password store.

**Restore** (into a new, empty database; never over a live one):

```sh
psql -U postgres -v db=acadly_sunrise_restore -v owner_password='…' -v app_password='…' -f infra/database/provision.sql
ACADLY_BACKUP_ENV=/etc/acadly/sunrise.backup.env infra/backup/acadly-restore.sh /var/backups/acadly/sunrise/<stamp> acadly_sunrise_restore
# put the school's .env from the password store in place, with DB_DATABASE=acadly_sunrise_restore
php artisan acadly:sync && php artisan audit:verify && php artisan acadly:preflight
```

Do a restore drill before the school opens and once a term after. Write down the date, the backup used, how long it took and what `audit:verify` said, in `infra/RESTORE-DRILLS.md`.

## 6. Logs

Laravel writes one log file a day (`LOG_STACK=daily`) in `shared/storage/logs`; logrotate keeps 30 days of those and of PHP-FPM's. Errors never show their details to people; they are in these files, with the request code people see.
