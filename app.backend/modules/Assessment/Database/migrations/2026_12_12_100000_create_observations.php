<?php

declare(strict_types=1);

use Acadly\Kernel\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-094. Levels that record observations, not marks. An observation is one
 * child, one learning area, one day and one step; recording it again adds a
 * new row and the newest wins. A progress review is one per child each term
 * and travels `assessment.progress_review`; the step chosen for each
 * learning area is added, never changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_observations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('class_id');
            $table->uuid('division_id');
            $table->uuid('period_id');
            $table->uuid('subject_id');
            $table->date('observed_on');
            $table->string('focus', 120)->nullable();
            $table->string('step', 10);
            $table->string('note', 255)->nullable();
            $table->uuid('recorded_by');
            $table->timestampTz('created_at', 6);
            $table->index(['learner_id', 'period_id']);
            $table->index(['class_id', 'observed_on']);
        });

        Schema::create('progress_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('class_id');
            $table->uuid('division_id');
            $table->uuid('period_id');
            $table->string('state', 20);
            $table->string('journey_ref', 80);
            $table->text('family_note')->nullable();
            $table->text('home_note')->nullable();
            $table->uuid('drafted_by');
            $table->timestampTz('shared_at', 6)->nullable();
            $table->timestampsTz(6);
            $table->unique(['learner_id', 'period_id']);
            $table->index(['class_id', 'period_id']);
        });

        Schema::create('progress_review_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('review_id')->constrained('progress_reviews');
            $table->uuid('subject_id');
            $table->string('step', 10);
            $table->uuid('set_by');
            $table->timestampTz('created_at', 6);
            $table->index(['review_id', 'subject_id']);
        });

        AppendOnly::protect('assessment_observations');
        AppendOnly::protect('progress_review_steps');
    }

    public function down(): void
    {
        foreach (['progress_review_steps', 'progress_reviews', 'assessment_observations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
