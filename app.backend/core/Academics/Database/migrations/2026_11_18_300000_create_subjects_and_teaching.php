<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Subjects and who teaches them (the approved Subjects and Teaching screens).
 *
 * - academic_subjects: the subjects of a division, the levels that take each
 *   and whether it is core. A subject no longer taught is retired, not deleted.
 * - academic_teaching: who teaches a subject in a class, or is its class
 *   teacher (no subject), for the class's year. A change ends the row in force
 *   and adds a new one, so the history stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_subjects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('division_id');
            $table->string('short', 4);
            $table->string('name', 60);
            $table->boolean('core')->default(true);
            $table->jsonb('levels');
            $table->unsignedSmallInteger('position');
            $table->timestampsTz(6);
            $table->timestampTz('retired_at', 6)->nullable();

            $table->foreign('division_id')->references('id')->on('institution_divisions');
            $table->index(['division_id', 'retired_at']);
        });

        DB::statement('CREATE UNIQUE INDEX academic_subjects_short_in_force ON academic_subjects (division_id, short) WHERE retired_at IS NULL');

        Schema::create('academic_teaching', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('class_id')->constrained('academic_classes');
            $table->foreignUuid('subject_id')->nullable()->constrained('academic_subjects');
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('given_by')->nullable()->constrained('users');
            $table->timestampTz('given_at', 6);
            $table->foreignUuid('ended_by')->nullable()->constrained('users');
            $table->timestampTz('ended_at', 6)->nullable();

            $table->index(['class_id', 'ended_at']);
            $table->index(['user_id', 'ended_at']);
        });

        DB::statement('CREATE UNIQUE INDEX academic_teaching_subject_in_force ON academic_teaching (class_id, subject_id) WHERE ended_at IS NULL AND subject_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX academic_teaching_class_teacher_in_force ON academic_teaching (class_id) WHERE ended_at IS NULL AND subject_id IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_teaching');
        Schema::dropIfExists('academic_subjects');
    }
};
