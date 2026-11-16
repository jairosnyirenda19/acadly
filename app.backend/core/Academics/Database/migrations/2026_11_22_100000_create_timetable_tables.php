<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The timetable (the approved Timetable screens). Typed by the school, never
 * generated.
 *
 * - academic_bell_plans: when each period and break starts and ends, for a
 *   level at a campus, on some days of the week. The days of the plans of one
 *   place never overlap. Periods are kept in order as one list.
 * - academic_timetable: what is taught in a class in one period of one day of
 *   the week, and by whom, from a day. A change ends the row in force and adds
 *   a new one, so a lesson already taught keeps what it was. The row and a
 *   date together name one lesson.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_bell_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('division_id');
            $table->uuid('campus_id');
            $table->string('name', 60);
            $table->jsonb('weekdays');
            $table->jsonb('periods');
            $table->unsignedSmallInteger('position');
            $table->foreignUuid('updated_by')->nullable()->constrained('users');
            $table->timestampsTz(6);

            $table->foreign('division_id')->references('id')->on('institution_divisions');
            $table->foreign('campus_id')->references('id')->on('institution_campuses');
            $table->index(['division_id', 'campus_id']);
        });

        Schema::create('academic_timetable', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('class_id')->constrained('academic_classes');
            $table->unsignedSmallInteger('weekday');
            $table->unsignedSmallInteger('period');
            $table->foreignUuid('subject_id')->constrained('academic_subjects');
            $table->foreignUuid('user_id')->nullable()->constrained('users');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->foreignUuid('set_by')->constrained('users');
            $table->timestampTz('set_at', 6);

            $table->index(['class_id', 'weekday']);
            $table->index(['user_id', 'weekday']);
        });

        DB::statement('CREATE UNIQUE INDEX academic_timetable_in_force ON academic_timetable (class_id, weekday, period) WHERE ends_on IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_timetable');
        Schema::dropIfExists('academic_bell_plans');
    }
};
