<?php

declare(strict_types=1);

use Acadly\Kernel\Blueprint\Journey\JourneyColumns;
use Acadly\Kernel\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assessment, first release (11 §9).
 *
 * - assessment_scales: how a percentage becomes a grade. Bands are percent,
 *   inclusive, covering 0 to 100. `seed_key` names a scale Acadly seeded, so
 *   `acadly:sync` adds it once; a scale the school adds has none.
 * - assessment_level_scales: the scale each level of a division uses.
 * - grading_plans: how a term grade is made, append-only. A change is a new
 *   version; the highest version is in force.
 * - assessments: one piece of assessed work for one class and one subject,
 *   travelling `assessment.marks`.
 * - assessment_marks: append-only. The mark in force is the latest of each
 *   learner; a correction points at the row it replaces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_scales', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('seed_key', 30)->nullable()->unique();
            $table->string('name', 60);
            $table->boolean('lower_is_better')->default(false);
            $table->string('pass_from', 16)->nullable();
            $table->jsonb('bands');
            $table->string('track', 16);
            $table->unsignedSmallInteger('position');
            $table->timestampsTz(6);
            $table->timestampTz('retired_at', 6)->nullable();
        });

        Schema::create('assessment_level_scales', function (Blueprint $table): void {
            $table->foreignUuid('division_id')->constrained('institution_divisions');
            $table->string('level_code', 16);
            $table->foreignUuid('scale_id')->constrained('assessment_scales');
            $table->uuid('set_by')->nullable();
            $table->timestampTz('set_at', 6);

            $table->primary(['division_id', 'level_code']);
        });

        Schema::create('grading_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('division_id')->constrained('institution_divisions');
            $table->string('level_code', 16);
            $table->foreignUuid('period_id')->constrained('academic_periods');
            $table->foreignUuid('subject_id')->nullable()->constrained('academic_subjects');
            $table->unsignedInteger('version');
            $table->string('mode', 16);
            $table->jsonb('parts');
            $table->string('reason', 255)->nullable();
            $table->uuid('created_by');
            $table->timestampTz('created_at', 6);

            $table->index(['division_id', 'level_code', 'period_id']);
        });

        DB::statement("create unique index grading_plans_version on grading_plans (division_id, level_code, period_id, coalesce(subject_id, '00000000-0000-0000-0000-000000000000'::uuid), version)");
        AppendOnly::protect('grading_plans');

        Schema::create('assessments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('class_id')->constrained('academic_classes');
            $table->foreignUuid('subject_id')->constrained('academic_subjects');
            $table->foreignUuid('period_id')->constrained('academic_periods');
            $table->string('kind', 20);
            $table->string('title', 80);
            $table->date('held_on');
            $table->unsignedSmallInteger('out_of');
            $table->boolean('counts');
            $table->string('part_key', 30)->nullable();
            $table->uuid('created_by');
            $table->string('state', 30);
            $table->string('journey_ref', 64);
            $table->timestampsTz(6);

            $table->index(['class_id', 'period_id', 'subject_id']);
            $table->index(['period_id', 'state']);
            $table->index('created_by');
        });

        DB::statement('alter table assessments add constraint assessments_out_of check (out_of between 1 and 1000)');
        JourneyColumns::protect('assessments');

        Schema::create('assessment_marks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('assessment_id')->constrained('assessments');
            $table->foreignUuid('learner_id')->constrained('learners');
            $table->decimal('value', 7, 2)->nullable();
            $table->string('reason', 10)->nullable();
            $table->uuid('entered_by');
            $table->timestampTz('entered_at', 6);
            $table->uuid('replaces_id')->nullable();
            $table->string('note', 255)->nullable();

            $table->index(['assessment_id', 'learner_id', 'entered_at']);
            $table->index('learner_id');
        });

        DB::statement('alter table assessment_marks add constraint assessment_marks_value_or_reason check ((value is null) <> (reason is null))');
        AppendOnly::protect('assessment_marks');
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_marks');
        Schema::dropIfExists('assessments');
        Schema::dropIfExists('grading_plans');
        Schema::dropIfExists('assessment_level_scales');
        Schema::dropIfExists('assessment_scales');
    }
};
