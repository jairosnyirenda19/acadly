<?php

declare(strict_types=1);

use Acadly\Kernel\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lesson registers, cover and staff away (the approved Timetable and lesson
 * register screens).
 *
 * - attendance_registers gains the lesson a `lesson` register is for: the
 *   timetable row and the day name it; the subject, period, times and the
 *   timetabled teacher are kept as they were, and `cover` says it was taken
 *   by someone else. The morning register stays one a class a day; a lesson
 *   register is one a lesson.
 * - attendance_away: a member of staff away on a day, or back. Append-only;
 *   the latest row for a person and a day is in force.
 * - attendance_cover_asks: someone asked to cover a lesson. Append-only; the
 *   latest row for a lesson is in force.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_registers', function (Blueprint $table): void {
            $table->dropUnique(['class_id', 'day', 'kind']);
            $table->uuid('slot_id')->nullable();
            $table->uuid('subject_id')->nullable();
            $table->unsignedSmallInteger('period')->nullable();
            $table->string('period_name', 40)->nullable();
            $table->string('starts', 5)->nullable();
            $table->string('ends', 5)->nullable();
            $table->uuid('teacher_id')->nullable();
            $table->boolean('cover')->default(false);
        });

        DB::statement("CREATE UNIQUE INDEX attendance_registers_morning ON attendance_registers (class_id, day) WHERE kind = 'morning'");
        DB::statement("CREATE UNIQUE INDEX attendance_registers_lesson ON attendance_registers (slot_id, day) WHERE kind = 'lesson'");

        Schema::create('attendance_away', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->date('day');
            $table->boolean('away');
            $table->string('reason', 255)->nullable();
            $table->foreignUuid('marked_by')->constrained('users');
            $table->timestampTz('marked_at', 6);

            $table->index(['day', 'user_id']);
        });
        AppendOnly::protect('attendance_away');

        Schema::create('attendance_cover_asks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('slot_id');
            $table->date('day');
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('asked_by')->constrained('users');
            $table->timestampTz('asked_at', 6);

            $table->index(['day', 'slot_id']);
            $table->index(['day', 'user_id']);
        });
        AppendOnly::protect('attendance_cover_asks');
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_cover_asks');
        Schema::dropIfExists('attendance_away');
        DB::statement('DROP INDEX IF EXISTS attendance_registers_lesson');
        DB::statement('DROP INDEX IF EXISTS attendance_registers_morning');

        Schema::table('attendance_registers', function (Blueprint $table): void {
            $table->dropColumn(['slot_id', 'subject_id', 'period', 'period_name', 'starts', 'ends', 'teacher_id', 'cover']);
            $table->unique(['class_id', 'day', 'kind']);
        });
    }
};
