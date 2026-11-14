<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Runs first. Gives the application role its working rights on every table
 * the owner creates from now on, and installs the function that append-only
 * tables use to refuse a change.
 *
 * Migrations run as the owner role: `php artisan migrate --database=pgsql_owner`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO acadly_app');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO acadly_app');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION acadly_reject_change() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Rows of % are append-only.', TG_TABLE_NAME USING ERRCODE = 'restrict_violation';
            END;
            $$
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS acadly_reject_change()');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON TABLES FROM acadly_app');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON SEQUENCES FROM acadly_app');
    }
};
