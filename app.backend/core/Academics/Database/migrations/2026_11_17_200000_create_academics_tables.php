<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The academic calendar and the classes (03 §1, "what lives at which level").
 *
 * - academic_years: the school's years. Institution-wide.
 * - academic_periods: the terms or semesters of a year, in order.
 * - academic_classes: a class of a level at a campus for a year, with its
 *   stream when the level has streams. A class removed is ended, not deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 40);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestampsTz(6);
        });

        Schema::create('academic_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('year_id')->constrained('academic_years');
            $table->string('name', 40);
            $table->unsignedSmallInteger('position');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestampsTz(6);

            $table->unique(['year_id', 'position']);
        });

        Schema::create('academic_classes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('year_id')->constrained('academic_years');
            $table->uuid('division_id');
            $table->uuid('campus_id');
            $table->string('level_code', 16);
            $table->string('stream', 30)->nullable();
            $table->string('name', 80);
            $table->unsignedSmallInteger('position');
            $table->timestampsTz(6);
            $table->timestampTz('ended_at', 6)->nullable();

            $table->foreign('division_id')->references('id')->on('institution_divisions');
            $table->foreign('campus_id')->references('id')->on('institution_campuses');
            $table->index(['year_id', 'division_id', 'campus_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_classes');
        Schema::dropIfExists('academic_periods');
        Schema::dropIfExists('academic_years');
    }
};
