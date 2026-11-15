<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permissions (a mirror of the catalogue in code, written by acadly:sync),
 * roles, the permissions in each role, and role assignments held at a scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_permissions', function (Blueprint $table): void {
            $table->string('key', 120)->primary();
            $table->string('owner', 60);
            $table->string('label');
            $table->boolean('is_sight')->default(false);
            $table->timestampsTz();
        });

        Schema::create('access_roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key', 80)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestampsTz();
        });

        Schema::create('access_role_permissions', function (Blueprint $table): void {
            $table->foreignUuid('role_id')->constrained('access_roles')->cascadeOnDelete();
            $table->string('permission_key', 120);
            $table->foreign('permission_key')->references('key')->on('access_permissions')->cascadeOnDelete();
            $table->primary(['role_id', 'permission_key']);
        });

        Schema::create('access_role_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('role_id')->constrained('access_roles');
            $table->string('scope_type', 20);
            $table->uuid('scope_id')->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->foreignUuid('granted_by')->nullable()->constrained('users');
            $table->text('reason')->nullable();
            $table->foreignUuid('ended_by')->nullable()->constrained('users');
            $table->text('end_reason')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'ends_at']);
            $table->index(['role_id', 'scope_type']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE access_role_assignments ADD CONSTRAINT access_role_assignments_scope_check CHECK (
                (scope_type = 'institution' AND scope_id IS NULL)
                OR (scope_type IN ('division', 'campus', 'class') AND scope_id IS NOT NULL)
            )
            SQL);

        DB::statement('ALTER TABLE access_role_assignments ADD CONSTRAINT access_role_assignments_period_check CHECK (ends_at IS NULL OR ends_at >= starts_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('access_role_assignments');
        Schema::dropIfExists('access_role_permissions');
        Schema::dropIfExists('access_roles');
        Schema::dropIfExists('access_permissions');
    }
};
