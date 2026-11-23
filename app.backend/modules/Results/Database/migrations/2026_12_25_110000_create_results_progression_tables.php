<?php

declare(strict_types=1);

use Acadly\Kernel\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-106. The board's rules (a set never changes; a change is a new set from
 * a named semester), the end-of-year progression list of each programme and
 * year (journey `results.progression`) with the board's choices and the
 * decisions, the graduation list of each programme (journey `results.award`)
 * with each student's award, and transcripts asked for and issued. Every
 * choice, decision, award and transcript is a row, append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('results_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('division_id');
            $table->uuid('from_period_id');
            $table->date('from_on');
            $table->decimal('good_cgpa', 3, 2);
            $table->unsignedSmallInteger('carry_credits');
            $table->unsignedSmallInteger('max_attempts');
            $table->unsignedSmallInteger('time_factor');
            // {"diploma": [{name, from}], "degree": [{name, from}]}
            $table->jsonb('classes');
            $table->boolean('hold_certificate');
            $table->uuid('created_by');
            $table->timestampTz('created_at', 6);
            $table->index(['division_id', 'from_on']);
        });

        Schema::create('results_progression_lists', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('division_id');
            // The last semester of the year the list closes.
            $table->uuid('period_id');
            $table->uuid('programme_id');
            $table->unsignedSmallInteger('year_of_study');
            $table->string('state', 16);
            $table->string('journey_ref', 64);
            $table->string('outcome', 24)->nullable();
            $table->uuid('created_by');
            $table->timestampsTz(6);
            $table->unique(['period_id', 'programme_id', 'year_of_study']);
        });

        Schema::create('results_progression_choices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('list_id')->constrained('results_progression_lists');
            $table->uuid('learner_id');
            // 'proceed', 'carry', 'repeat_year', 'discontinue', 'complete' or 'continue'.
            $table->string('decision', 16);
            $table->string('reason', 255);
            $table->uuid('by_user_id');
            $table->timestampTz('created_at', 6);
            $table->index(['list_id', 'learner_id']);
        });

        Schema::create('results_progression_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('list_id')->constrained('results_progression_lists');
            $table->uuid('learner_id');
            $table->string('decision', 16);
            $table->jsonb('carried');
            $table->decimal('cgpa', 4, 2)->nullable();
            $table->unsignedSmallInteger('failed_credits');
            $table->string('standing', 12)->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestampTz('created_at', 6);
            $table->index(['list_id', 'learner_id']);
            $table->index('learner_id');
        });

        Schema::create('results_award_lists', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('division_id');
            $table->uuid('period_id');
            $table->uuid('programme_id');
            $table->date('graduation_on')->nullable();
            $table->string('state', 16);
            $table->string('journey_ref', 64);
            $table->string('outcome', 24)->nullable();
            $table->uuid('created_by');
            $table->timestampsTz(6);
            $table->unique(['period_id', 'programme_id']);
        });

        Schema::create('results_awards', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('list_id')->constrained('results_award_lists');
            $table->uuid('learner_id');
            $table->decimal('cgpa', 4, 2)->nullable();
            $table->unsignedSmallInteger('credits');
            $table->string('class', 24)->nullable();
            $table->boolean('complete');
            $table->jsonb('missing');
            $table->timestampTz('created_at', 6);
            $table->index('learner_id');
        });

        Schema::create('results_transcript_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('division_id');
            $table->uuid('learner_id');
            // 'self' or 'organisation'.
            $table->string('for_kind', 12);
            $table->string('organisation', 160)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('purpose', 255)->nullable();
            $table->timestampTz('created_at', 6);
            $table->index('learner_id');
        });

        Schema::create('results_transcripts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('division_id');
            $table->uuid('learner_id');
            $table->uuid('request_id')->nullable();
            $table->string('reference', 40);
            $table->uuid('issued_by');
            $table->timestampTz('issued_at', 6);
            $table->index('learner_id');
            $table->index('request_id');
        });

        foreach (['results_rules', 'results_progression_choices', 'results_progression_decisions', 'results_awards', 'results_transcript_requests', 'results_transcripts'] as $table) {
            AppendOnly::protect($table);
        }
    }

    public function down(): void
    {
        foreach (['results_transcripts', 'results_transcript_requests', 'results_awards', 'results_award_lists', 'results_progression_decisions', 'results_progression_choices',
            'results_progression_lists', 'results_rules'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
