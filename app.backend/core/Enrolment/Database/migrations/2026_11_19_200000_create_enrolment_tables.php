<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Learners, their identifiers, their stays at the school, where they are
 * placed, and who to contact (09, learner lifecycle; first release).
 *
 * - learners: one record for life, with the Acadly ID that never changes.
 * - learner_identifiers: the school's own numbers. A number is never reused;
 *   a correction retires the row and issues a new one.
 * - enrolments: a stay, from admission to leaving. One active stay at a time.
 * - placements: where the learner is. A move closes one row and opens the
 *   next; one open row per stay.
 * - learner_contacts: who the school contacts about the learner. At least two
 *   in force; a removal closes the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learners', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('acadly_id', 14)->unique();
            $table->string('first_name', 80);
            $table->string('other_names', 120)->nullable();
            $table->string('surname', 80);
            $table->string('sex', 10);
            $table->date('date_of_birth');
            $table->uuid('photo_document_id')->nullable();
            $table->timestampsTz(6);

            $table->index(['surname', 'first_name']);
        });

        Schema::create('learner_identifiers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('learner_id')->constrained('learners');
            $table->string('kind', 30);
            $table->string('value', 40);
            $table->timestampTz('issued_at', 6);
            $table->uuid('issued_by')->nullable();
            $table->timestampTz('retired_at', 6)->nullable();

            $table->unique(['kind', 'value']);
            $table->index(['learner_id', 'kind']);
        });

        Schema::create('enrolments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('learner_id')->constrained('learners');
            $table->string('status', 20);
            $table->date('admitted_on');
            $table->date('ended_on')->nullable();
            $table->string('end_reason', 30)->nullable();
            $table->uuid('admitted_by')->nullable();
            $table->timestampsTz(6);
        });

        DB::statement("create unique index enrolments_one_active on enrolments (learner_id) where status = 'active'");

        Schema::create('placements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('enrolment_id')->constrained('enrolments');
            $table->foreignUuid('learner_id')->constrained('learners');
            $table->foreignUuid('year_id')->constrained('academic_years');
            $table->uuid('division_id');
            $table->uuid('campus_id');
            $table->string('level_code', 16);
            $table->foreignUuid('class_id')->constrained('academic_classes');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('entry_reason', 30);
            $table->string('exit_reason', 30)->nullable();
            $table->string('reason', 255)->nullable();
            $table->uuid('opened_by')->nullable();
            $table->uuid('closed_by')->nullable();
            $table->timestampTz('created_at', 6);

            $table->index(['class_id', 'ends_on']);
            $table->index(['learner_id', 'starts_on']);
        });

        DB::statement('create unique index placements_one_open on placements (enrolment_id) where ends_on is null');

        Schema::create('learner_contacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('learner_id')->constrained('learners');
            $table->string('name', 120);
            $table->string('relationship', 20);
            $table->string('phone', 30);
            $table->string('email', 190)->nullable();
            $table->boolean('is_main')->default(false);
            $table->unsignedSmallInteger('position');
            $table->uuid('added_by')->nullable();
            $table->timestampTz('added_at', 6);
            $table->uuid('removed_by')->nullable();
            $table->timestampTz('removed_at', 6)->nullable();
            $table->string('removed_reason', 255)->nullable();

            $table->index(['learner_id', 'removed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learner_contacts');
        Schema::dropIfExists('placements');
        Schema::dropIfExists('enrolments');
        Schema::dropIfExists('learner_identifiers');
        Schema::dropIfExists('learners');
    }
};
