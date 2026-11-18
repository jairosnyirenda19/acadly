<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The year-end run (09 §4, D-088).
 *
 * - progression_runs: one per level group (division) and school year, from
 *   this year to the next. Opened, then committed; a second commit decides
 *   only what is still proposed.
 * - progression_decisions: one per learner per run, each a journey subject
 *   (`enrolment.progression`): proposed, decided, awaiting the receiving
 *   level group's intake, effective on the first day of the next year, or
 *   cancelled. The outcome and the class stay changeable while proposed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('progression_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('division_id');
            $table->foreignUuid('from_year_id')->constrained('academic_years');
            $table->foreignUuid('to_year_id')->constrained('academic_years');
            $table->string('state', 20);
            $table->uuid('opened_by');
            $table->timestampTz('opened_at', 6);
            $table->uuid('committed_by')->nullable();
            $table->timestampTz('committed_at', 6)->nullable();

            $table->unique(['division_id', 'from_year_id']);
        });

        Schema::create('progression_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('run_id')->constrained('progression_runs');
            $table->foreignUuid('learner_id')->constrained('learners');
            $table->foreignUuid('enrolment_id')->constrained('enrolments');
            $table->foreignUuid('from_placement_id')->constrained('placements');
            $table->uuid('from_class_id');
            $table->uuid('from_division_id');
            $table->uuid('campus_id');
            $table->string('from_level', 16);
            $table->string('outcome', 20);
            $table->uuid('to_class_id')->nullable();
            $table->uuid('to_division_id')->nullable();
            $table->string('reason', 255)->nullable();
            $table->uuid('changed_by')->nullable();
            $table->string('state', 30);
            $table->string('journey_ref', 80);
            $table->timestampsTz(6);

            $table->unique(['run_id', 'learner_id']);
            $table->index(['to_division_id', 'state']);
        });

        DB::statement("ALTER TABLE progression_decisions ADD CONSTRAINT progression_decisions_outcome_check CHECK (outcome IN ('promoted', 'repeated', 'progressed', 'completed', 'left'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('progression_decisions');
        Schema::dropIfExists('progression_runs');
    }
};
