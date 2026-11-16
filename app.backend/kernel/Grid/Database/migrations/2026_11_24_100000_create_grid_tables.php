<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The data grid (the approved design).
 *
 * - grid_views: a person's own named views of a list: filters, sort, columns.
 * - grid_columns: the columns a person chose to see in a list.
 * - grid_exports: an export a person asked for, built in the background. The
 *   file is kept for a week, then deleted; the row stays as the record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grid_views', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('grid', 40);
            $table->string('name', 60);
            $table->jsonb('state');
            $table->timestampsTz(6);

            $table->unique(['user_id', 'grid', 'name']);
        });

        Schema::create('grid_columns', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('grid', 40);
            $table->jsonb('shown');
            $table->timestampTz('updated_at', 6);

            $table->primary(['user_id', 'grid']);
        });

        Schema::create('grid_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('grid', 40);
            $table->jsonb('state');
            $table->jsonb('columns');
            $table->jsonb('only')->nullable();
            $table->string('status', 20);
            $table->unsignedInteger('rows')->nullable();
            $table->string('path', 255)->nullable();
            $table->timestampTz('requested_at', 6);
            $table->timestampTz('finished_at', 6)->nullable();
            $table->timestampTz('expires_at', 6)->nullable();

            $table->index(['user_id', 'requested_at']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grid_exports');
        Schema::dropIfExists('grid_columns');
        Schema::dropIfExists('grid_views');
    }
};
