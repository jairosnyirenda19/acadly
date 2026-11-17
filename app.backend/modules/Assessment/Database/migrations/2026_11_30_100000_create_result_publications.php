<?php

declare(strict_types=1);

use Acadly\Kernel\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Publishing results and report cards (the approved design, D-080).
 *
 * - `result_comments`: what the class teacher or the head teacher wrote for a
 *   learner's term. Append-only: the latest row of a learner, term and author
 *   is the comment in force.
 * - `result_publications`: one row per version of a class's term results.
 *   Never changed: a correction makes the next version.
 * - `result_publication_learners`: what each learner's report card says in
 *   that version, kept as it was published, with its reference.
 * - `result_card_settings`: what report cards show. Append-only: the latest
 *   row is in force.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('result_comments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('learner_id')->constrained('learners');
            $table->uuid('period_id');
            $table->uuid('class_id');
            $table->string('author', 20);
            $table->string('text', 300);
            $table->uuid('written_by');
            $table->timestampTz('written_at', 6);

            $table->index(['class_id', 'period_id']);
            $table->index(['learner_id', 'period_id']);
        });

        Schema::create('result_publications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('class_id');
            $table->uuid('period_id');
            $table->unsignedSmallInteger('version');
            $table->string('reason', 255)->nullable();
            $table->string('fingerprint', 64);
            $table->jsonb('settings');
            $table->uuid('published_by');
            $table->timestampTz('published_at', 6);

            $table->unique(['class_id', 'period_id', 'version']);
        });

        Schema::create('result_publication_learners', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('publication_id')->constrained('result_publications');
            $table->foreignUuid('learner_id')->constrained('learners');
            $table->string('reference', 20)->unique();
            $table->jsonb('card');

            $table->unique(['publication_id', 'learner_id']);
            $table->index('learner_id');
        });

        Schema::create('result_card_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->boolean('show_position');
            $table->boolean('head_comment');
            $table->uuid('saved_by');
            $table->timestampTz('saved_at', 6);
        });

        // The newest row wins: a number that only grows, whatever the clock says.
        DB::statement('alter table result_comments add column seq bigserial');
        DB::statement('alter table result_card_settings add column seq bigserial');
        AppendOnly::protect('result_comments');
        AppendOnly::protect('result_publications');
        AppendOnly::protect('result_publication_learners');
        AppendOnly::protect('result_card_settings');
    }

    public function down(): void
    {
        Schema::dropIfExists('result_card_settings');
        Schema::dropIfExists('result_publication_learners');
        Schema::dropIfExists('result_publications');
        Schema::dropIfExists('result_comments');
    }
};
