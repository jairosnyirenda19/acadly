<?php

declare(strict_types=1);

use Acadly\Kernel\Blueprint\Journey\JourneyColumns;
use Acadly\Kernel\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The morning register (the approved Attendance screens, first release).
 *
 * - attendance_registers: one register of a class for one school day, made
 *   when it is submitted. Submitted once.
 * - attendance_marks: the marks, append-only. The first mark of each learner
 *   is written at submission; an approved correction adds a later mark and
 *   points at its correction. The mark in force is the latest.
 * - attendance_corrections: a request to change a mark, travelling the
 *   `attendance.correction` journey.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_registers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('class_id')->constrained('academic_classes');
            $table->date('day');
            $table->string('kind', 20);
            $table->uuid('submitted_by');
            $table->timestampTz('submitted_at', 6);

            $table->unique(['class_id', 'day', 'kind']);
            $table->index('day');
        });

        Schema::create('attendance_marks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('register_id')->constrained('attendance_registers');
            $table->foreignUuid('learner_id')->constrained('learners');
            $table->string('status', 10);
            $table->string('note', 255)->nullable();
            $table->string('method', 20);
            $table->uuid('captured_by');
            $table->timestampTz('captured_at', 6);
            $table->timestampTz('received_at', 6);
            $table->uuid('correction_id')->nullable();

            $table->index(['learner_id', 'register_id']);
        });

        DB::statement('create unique index attendance_marks_first on attendance_marks (register_id, learner_id) where correction_id is null');
        AppendOnly::protect('attendance_marks');

        Schema::create('attendance_corrections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('register_id')->constrained('attendance_registers');
            $table->uuid('class_id');
            $table->foreignUuid('learner_id')->constrained('learners');
            $table->string('from_status', 10);
            $table->string('to_status', 10);
            $table->string('reason', 255);
            $table->uuid('requested_by');
            $table->string('state', 30);
            $table->string('journey_ref', 64);
            $table->timestampsTz(6);

            $table->index(['register_id', 'state']);
        });

        DB::statement("create unique index attendance_corrections_one_open on attendance_corrections (register_id, learner_id) where state in ('drafted', 'requested')");
        JourneyColumns::protect('attendance_corrections');
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_corrections');
        Schema::dropIfExists('attendance_marks');
        Schema::dropIfExists('attendance_registers');
    }
};
