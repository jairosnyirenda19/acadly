# Restore drills

Each drill restores a real backup into a new, empty database and checks it (D-112). Do one before a school opens and once a term after.

| Date | School | Backup | Took | Result |
|---|---|---|---|---|
| 2026-11-25 | Development school (225 learners, 196 tables, 25 MB) | `20261125T0024Z` (database 1.3 MB, documents 0.8 MB) | 54 s restore, 11 s backup | **Passed.** Same row counts in all nine tables compared (learners, users, attendance marks, payments, audit lines, documents, conduct, support, assessment marks). History still append-only for the app role. The app role still cannot change the structure. `audit:verify`: 1,148 lines verify. Documents restored byte for byte from the backup. |

## What the first drill changed

- The restore stopped at `CREATE SCHEMA public`, because `provision.sql` had already made the schema. Nothing was half restored (it runs as one transaction). `acadly-restore.sh` now leaves out the schema's own entries.
- `psql -W` asks for the superuser password twice: once for the server, once after `\connect` to the new database.
