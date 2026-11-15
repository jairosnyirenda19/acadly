<?php

declare(strict_types=1);

use Acadly\Kernel\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/architecture/02-blueprints.md §3.8.
 *
 * - blueprint_versions: every released version this deployment has run, with
 *   its compiled form and hash, as `acadly:sync` found it. Append-only.
 * - journey_step_completions: every step performed on a record. Append-only.
 * - effect_applications: every effect applied, under its idempotency key.
 *   The unique key is what makes a retry safe. Append-only.
 * - acadly_guard_journey_columns(): the trigger function behind
 *   `JourneyColumns::protect()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION acadly_guard_journey_columns() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF ((to_jsonb(NEW) -> TG_ARGV[0]) IS DISTINCT FROM (to_jsonb(OLD) -> TG_ARGV[0])
                    OR (to_jsonb(NEW) -> TG_ARGV[1]) IS DISTINCT FROM (to_jsonb(OLD) -> TG_ARGV[1]))
                   AND coalesce(current_setting('acadly.journey_commit', true), '') <> 'on' THEN
                    RAISE EXCEPTION 'A journey state changes only through the journey engine.' USING ERRCODE = '42501';
                END IF;
                RETURN NEW;
            END
            $$;
            SQL);

        Schema::create('blueprint_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('kind', 16);
            $table->string('ref', 64)->unique();
            $table->string('key', 60);
            $table->string('version', 16);
            $table->char('hash', 64);
            $table->jsonb('compiled');
            $table->timestampTz('released_at', 6);
            $table->timestampTz('recorded_at', 6);
        });

        Schema::create('journey_step_completions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('subject_type', 60);
            $table->uuid('subject_id');
            $table->unsignedInteger('seq');
            $table->string('journey_ref', 64);
            $table->string('step', 120);
            $table->string('outcome', 120);
            $table->string('from_state', 120);
            $table->string('to_state', 120);
            $table->uuid('actor_user_id')->nullable();
            $table->jsonb('input');
            $table->text('reason')->nullable();
            $table->timestampTz('occurred_at', 6);

            $table->unique(['subject_type', 'subject_id', 'seq']);
        });

        Schema::create('effect_applications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('idempotency_key', 64)->unique();
            $table->string('effect', 255);
            $table->string('boundary', 16);
            $table->string('subject_type', 60);
            $table->uuid('subject_id');
            $table->uuid('completion_id')->nullable();
            $table->string('produced', 120)->nullable();
            $table->jsonb('meta');
            $table->timestampTz('applied_at', 6);

            $table->index(['subject_type', 'subject_id']);
        });

        AppendOnly::protect('blueprint_versions');
        AppendOnly::protect('journey_step_completions');
        AppendOnly::protect('effect_applications');
    }

    public function down(): void
    {
        Schema::dropIfExists('effect_applications');
        Schema::dropIfExists('journey_step_completions');
        Schema::dropIfExists('blueprint_versions');
        DB::unprepared('DROP FUNCTION IF EXISTS acadly_guard_journey_columns()');
    }
};
