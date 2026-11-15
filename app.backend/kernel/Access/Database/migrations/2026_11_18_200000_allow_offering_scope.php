<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** A role can be held at an offering: one division taught at one campus. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE access_role_assignments DROP CONSTRAINT access_role_assignments_scope_check');
        DB::statement(<<<'SQL'
            ALTER TABLE access_role_assignments ADD CONSTRAINT access_role_assignments_scope_check CHECK (
                (scope_type = 'institution' AND scope_id IS NULL)
                OR (scope_type IN ('division', 'campus', 'offering', 'class') AND scope_id IS NOT NULL)
            )
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE access_role_assignments DROP CONSTRAINT access_role_assignments_scope_check');
        DB::statement(<<<'SQL'
            ALTER TABLE access_role_assignments ADD CONSTRAINT access_role_assignments_scope_check CHECK (
                (scope_type = 'institution' AND scope_id IS NULL)
                OR (scope_type IN ('division', 'campus', 'class') AND scope_id IS NOT NULL)
            )
            SQL);
    }
};
