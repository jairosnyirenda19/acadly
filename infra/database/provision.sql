-- Sets up the database for one school (D-112). Safe to run again.
--
-- Run it once per school, as the PostgreSQL superuser, on the database server:
--
--   psql -U postgres -v ON_ERROR_STOP=1 \
--        -v db=acadly_db \
--        -v owner_password='…' -v app_password='…' \
--        -f infra/database/provision.sql
--
-- Two roles (rule 7):
--   acadly_owner  owns the schema and runs migrations at each release.
--   acadly_app    is what the application connects as. It reads and writes
--                 rows and can never change the structure.
-- Choose long random passwords; they go only into the school's .env.

\set ON_ERROR_STOP on

-- The roles. A second run only resets the passwords.
SELECT format('CREATE ROLE acadly_owner LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS PASSWORD %L', :'owner_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'acadly_owner') \gexec
SELECT format('CREATE ROLE acadly_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS PASSWORD %L', :'app_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'acadly_app') \gexec
SELECT format('ALTER ROLE acadly_owner PASSWORD %L', :'owner_password') \gexec
SELECT format('ALTER ROLE acadly_app PASSWORD %L', :'app_password') \gexec

-- The school's database, owned by the owner role.
SELECT format('CREATE DATABASE %I OWNER acadly_owner ENCODING ''UTF8'' TEMPLATE template0', :'db')
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'db') \gexec

\connect :db

-- Search needs these. Only the superuser may install them.
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS unaccent;
CREATE EXTENSION IF NOT EXISTS btree_gin;

-- The owner owns the schema. Nobody else may create in it.
ALTER SCHEMA public OWNER TO acadly_owner;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
REVOKE ALL ON DATABASE :"db" FROM PUBLIC;
GRANT CONNECT ON DATABASE :"db" TO acadly_owner, acadly_app;
GRANT USAGE ON SCHEMA public TO acadly_app;

-- Rows only, on every table the owner makes from now on. (The first
-- migration sets the same; this covers a database restored before it ran.)
ALTER DEFAULT PRIVILEGES FOR ROLE acadly_owner IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO acadly_app;
ALTER DEFAULT PRIVILEGES FOR ROLE acadly_owner IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO acadly_app;

-- Never grant on existing tables here: append-only tables (history) have
-- had their update and delete rights taken away, and a restore from a
-- backup brings every table's rights back exactly as they were.

\echo 'Database ready. Next: php artisan migrate --database=pgsql_owner --force'
