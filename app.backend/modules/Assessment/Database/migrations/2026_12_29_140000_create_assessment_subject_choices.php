<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-117. The electives each learner takes in a school year. Append-only: a
 * change is a new row, and the latest row for a learner and a subject is in
 * force. Choices are on for a level once any are saved for it in the year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_subject_choices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('year_id');
            $table->uuid('division_id');
            $table->string('level_code', 20);
            $table->uuid('learner_id');
            $table->uuid('subject_id');
            $table->boolean('takes');
            $table->uuid('set_by');
            $table->timestampTz('set_at', 6);
            $table->index(['year_id', 'division_id', 'level_code']);
            $table->index(['year_id', 'learner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_subject_choices');
    }
};
