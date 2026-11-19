<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-097. Programmes: a division's faculties and departments, its programmes
 * with their curriculum versions, the course catalogue, and each semester's
 * offerings with their lecturers. Rows in use are retired, never deleted.
 * A released curriculum version never changes: a change is a new version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programme_faculties', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('division_id');
            $table->string('name', 120);
            $table->string('code', 12);
            $table->unsignedInteger('position');
            $table->timestampTz('retired_at', 6)->nullable();
            $table->timestampsTz(6);
            $table->index('division_id');
        });

        Schema::create('programme_departments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('faculty_id')->constrained('programme_faculties');
            $table->string('name', 120);
            $table->string('code', 12);
            // The course code prefixes the department uses, as ACC and FIN.
            $table->jsonb('prefixes');
            $table->uuid('head_user_id')->nullable();
            $table->timestampTz('retired_at', 6)->nullable();
            $table->timestampsTz(6);
        });

        Schema::create('programme_courses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('division_id');
            $table->foreignUuid('department_id')->constrained('programme_departments');
            $table->string('code', 16);
            $table->string('title', 160);
            $table->unsignedSmallInteger('credits');
            $table->unsignedSmallInteger('lecture_hours');
            $table->unsignedSmallInteger('tutorial_hours');
            $table->unsignedSmallInteger('practical_hours');
            $table->timestampTz('retired_at', 6)->nullable();
            $table->timestampsTz(6);
            $table->unique(['division_id', 'code']);
        });

        Schema::create('programme_course_needs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('course_id')->constrained('programme_courses');
            $table->foreignUuid('needs_course_id')->constrained('programme_courses');
            $table->unique(['course_id', 'needs_course_id']);
        });

        Schema::create('programmes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('division_id');
            $table->foreignUuid('department_id')->constrained('programme_departments');
            $table->string('code', 12);
            $table->string('name', 160);
            $table->string('award', 24);
            $table->unsignedSmallInteger('years');
            $table->unsignedSmallInteger('semesters');
            $table->unsignedSmallInteger('credits');
            $table->unsignedSmallInteger('min_load');
            $table->unsignedSmallInteger('max_load');
            $table->boolean('open_for_intake');
            $table->timestampTz('retired_at', 6)->nullable();
            $table->timestampsTz(6);
            $table->unique(['division_id', 'code']);
        });

        Schema::create('programme_curricula', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('programme_id')->constrained('programmes');
            $table->string('label', 16);
            $table->timestampTz('released_at', 6)->nullable();
            $table->uuid('released_by')->nullable();
            $table->timestampsTz(6);
            $table->unique(['programme_id', 'label']);
        });

        Schema::create('programme_elective_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('curriculum_id')->constrained('programme_curricula');
            $table->unsignedSmallInteger('year');
            $table->unsignedSmallInteger('semester');
            $table->string('label', 40);
            $table->unsignedSmallInteger('choose');
            $table->unsignedInteger('position');
        });

        Schema::create('programme_curriculum_courses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('curriculum_id')->constrained('programme_curricula');
            $table->unsignedSmallInteger('year');
            $table->unsignedSmallInteger('semester');
            $table->foreignUuid('course_id')->constrained('programme_courses');
            // Null for a core course; the elective group otherwise.
            $table->foreignUuid('group_id')->nullable()->constrained('programme_elective_groups');
            $table->unsignedInteger('position');
            $table->unique(['curriculum_id', 'course_id']);
        });

        Schema::create('programme_offerings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('division_id');
            $table->uuid('period_id');
            $table->foreignUuid('course_id')->constrained('programme_courses');
            $table->string('group_label', 4);
            $table->unsignedSmallInteger('size_limit')->nullable();
            $table->timestampsTz(6);
            $table->unique(['period_id', 'course_id', 'group_label']);
        });

        Schema::create('programme_offering_staff', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('offering_id')->constrained('programme_offerings');
            $table->uuid('user_id');
            // 'lecturer' or 'assistant'.
            $table->string('role', 12);
            $table->unique(['offering_id', 'user_id']);
        });
    }

    public function down(): void
    {
        foreach (['programme_offering_staff', 'programme_offerings', 'programme_curriculum_courses', 'programme_elective_groups', 'programme_curricula',
            'programmes', 'programme_course_needs', 'programme_courses', 'programme_departments', 'programme_faculties'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
