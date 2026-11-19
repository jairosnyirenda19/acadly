<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-098. Semester registration: students placed in programmes (each change
 * a new row; the row in force has no end), the registration dates of each
 * semester, and one registration a student a semester that travels
 * `programmes.registration`. Its courses are kept by version: a change in
 * add and drop is a new version, and the earlier one stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programme_students', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('division_id');
            $table->uuid('learner_id');
            $table->foreignUuid('programme_id')->constrained('programmes');
            $table->foreignUuid('curriculum_id')->constrained('programme_curricula');
            $table->unsignedSmallInteger('year_of_study');
            $table->unsignedSmallInteger('entry_year');
            $table->unsignedSmallInteger('entry_semester');
            $table->string('mode', 12);
            $table->string('status', 12);
            $table->string('reason', 255)->nullable();
            $table->uuid('created_by');
            $table->timestampTz('created_at', 6);
            $table->timestampTz('ended_at', 6)->nullable();
            $table->index(['division_id', 'status']);
        });

        DB::statement('create unique index programme_students_one_in_force on programme_students (learner_id) where ended_at is null');

        Schema::create('programme_registration_windows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('division_id');
            $table->uuid('period_id')->unique();
            $table->date('opens_on');
            $table->date('closes_on');
            $table->date('late_until');
            $table->date('add_drop_until');
            $table->unsignedInteger('late_fee_minor');
            $table->unsignedSmallInteger('fees_share');
            $table->timestampTz('started_at', 6)->nullable();
            $table->uuid('started_by')->nullable();
            $table->timestampsTz(6);
        });

        Schema::create('programme_registrations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('division_id');
            $table->uuid('period_id');
            $table->uuid('learner_id');
            $table->foreignUuid('student_id')->constrained('programme_students');
            $table->unsignedSmallInteger('year_of_study');
            $table->unsignedSmallInteger('semester');
            $table->string('state', 20);
            $table->string('journey_ref', 80);
            $table->string('outcome', 20)->nullable();
            $table->boolean('late');
            $table->unsignedSmallInteger('version');
            $table->uuid('fees_allowed_by')->nullable();
            $table->string('fees_allowed_reason', 255)->nullable();
            $table->timestampTz('fees_allowed_at', 6)->nullable();
            $table->uuid('opened_by');
            $table->timestampsTz(6);
            $table->unique(['period_id', 'learner_id']);
            $table->index(['division_id', 'period_id', 'state']);
        });

        Schema::create('programme_registration_courses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('registration_id')->constrained('programme_registrations');
            $table->unsignedSmallInteger('version');
            $table->foreignUuid('offering_id')->constrained('programme_offerings');
            $table->foreignUuid('course_id')->constrained('programme_courses');
            // 'core', 'elective' or 'repeat'.
            $table->string('kind', 10);
            $table->uuid('elective_group_id')->nullable();
            $table->unsignedSmallInteger('credits');
            $table->unique(['registration_id', 'version', 'course_id']);
            $table->index('offering_id');
        });
    }

    public function down(): void
    {
        foreach (['programme_registration_courses', 'programme_registrations', 'programme_registration_windows', 'programme_students'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
