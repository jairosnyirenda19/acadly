# The database of a school

One school has one PostgreSQL database (17 or newer) and two roles (rule 7, D-112).

| Role | Used by | May |
|---|---|---|
| `acadly_owner` | releases and backups | create and change tables; read everything |
| `acadly_app` | the application, every request | read and write rows only; never change the structure |

The application connects as `acadly_app` (`DB_USERNAME`). Releases run migrations as `acadly_owner` (`DB_OWNER_USERNAME`, the `pgsql_owner` connection). History tables are append-only: the app role has no update or delete right on them, and a trigger refuses a change.

## Set up a new school

Run once, as the PostgreSQL superuser, on the database server. Choose two long random passwords; they go only into the school's `.env`.

```sh
psql -U postgres -v ON_ERROR_STOP=1 \
     -v db=acadly_sunrise \
     -v owner_password='…' -v app_password='…' \
     -f infra/database/provision.sql
```

`psql` asks for the superuser password twice: once for the server, once for the new database.

It makes the two roles, the database owned by `acadly_owner`, the three extensions search needs (`pg_trgm`, `unaccent`, `btree_gin`), and gives `acadly_app` row rights on every table the owner makes. Running it again only resets the two passwords.

Then, from the release folder:

```sh
php artisan migrate --database=pgsql_owner --force
php artisan acadly:sync
php artisan acadly:administrator      # the school's first administrator
php artisan acadly:preflight
```

## Checks

- `php artisan acadly:preflight` fails when the application can change the database structure or owns a table.
- `GET /api/v1/ready` answers 503 in the same case.

## A developer's machine

`composer setup` runs the migrations as the owner too. The test database (`DB_TEST_DATABASE`) is made the same way with `-v db=acadly_test_db`.
