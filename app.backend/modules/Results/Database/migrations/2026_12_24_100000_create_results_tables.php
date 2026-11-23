<?php

declare(strict_types=1);

use Acadly\Kernel\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-105. Results: the grading scales of a division (a scale never changes; a
 * change is a new scale from a named semester), its settings, a result sheet
 * for each course and sitting that travels `results.sheet`, the exam marks
 * (append-only: the latest row counts), the lines frozen when a sheet is
 * sent for moderation, the attendance bars lifted, and each release.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('results_scales', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('division_id');
            // The first semester it applies to; null for every semester before the next scale.
            $table->uuid('from_period_id')->nullable();
            $table->date('from_on');
            $table->string('label', 80);
            $table->unsignedSmallInteger('pass_mark');
            // [{grade, min, max, points}], from the top band down.
            $table->jsonb('bands');
            $table->uuid('created_by');
            $table->timestampTz('created_at', 6);
            $table->index(['division_id', 'from_on']);
        });

        Schema::create('results_settings', function (Blueprint $table): void {
            $table->uuid('division_id')->primary();
            $table->unsignedSmallInteger('bar_percent');
            $table->unsignedSmallInteger('bar_days');
            $table->boolean('hold_slip_for_fees');
            $table->uuid('updated_by');
            $table->timestampTz('updated_at', 6);
        });

        Schema::create('results_sheets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('division_id');
            $table->uuid('period_id');
            $table->uuid('offering_id');
            // 'main' or 'supplementary'.
            $table->string('sitting', 16);
            $table->string('state', 16);
            $table->string('journey_ref', 64);
            $table->string('outcome', 24)->nullable();
            $table->date('exam_on')->nullable();
            $table->timestampTz('released_at', 6)->nullable();
            $table->uuid('release_id')->nullable();
            $table->uuid('created_by');
            $table->timestampsTz(6);
            $table->unique(['offering_id', 'sitting']);
            $table->index(['division_id', 'period_id']);
        });

        Schema::create('results_marks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('sheet_id')->constrained('results_sheets');
            $table->uuid('learner_id');
            $table->decimal('exam', 5, 1)->nullable();
            // Null, 'absent' (counts 0) or 'deferred' (an absence the board accepted).
            $table->string('absence', 10)->nullable();
            // 'entry', 'moderation', 'board' or 'correction'.
            $table->string('stage', 12);
            $table->string('reason', 255)->nullable();
            $table->uuid('by_user_id');
            $table->timestampTz('created_at', 6);
            $table->index(['sheet_id', 'learner_id']);
            $table->index('learner_id');
        });

        Schema::create('results_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('sheet_id')->constrained('results_sheets');
            $table->uuid('learner_id');
            $table->decimal('ca', 5, 1);
            $table->unsignedSmallInteger('ca_share');
            $table->unsignedSmallInteger('held');
            $table->unsignedSmallInteger('attended');
            $table->boolean('barred');
            $table->timestampTz('created_at', 6);
            $table->index(['sheet_id', 'learner_id']);
        });

        Schema::create('results_bar_lifts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('offering_id');
            $table->uuid('learner_id');
            $table->string('reason', 255);
            $table->uuid('by_user_id');
            $table->timestampTz('created_at', 6);
            $table->unique(['offering_id', 'learner_id']);
        });

        Schema::create('results_releases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('division_id');
            $table->uuid('period_id');
            $table->string('sitting', 16);
            $table->unsignedInteger('sheets');
            $table->boolean('sms');
            $table->uuid('released_by');
            $table->timestampTz('released_at', 6);
            $table->index(['division_id', 'period_id']);
        });

        foreach (['results_scales', 'results_marks', 'results_lines', 'results_bar_lifts', 'results_releases'] as $table) {
            AppendOnly::protect($table);
        }
    }

    public function down(): void
    {
        foreach (['results_releases', 'results_bar_lifts', 'results_lines', 'results_marks', 'results_sheets', 'results_settings', 'results_scales'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
