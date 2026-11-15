<?php

declare(strict_types=1);

use Acadly\Kernel\Support\Ids;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 03 §1. The school is one row: a deployment serves one school.
 *
 * - institution: the school. Exactly one row, made here, named as the
 *   deployment's configuration names it until setup saves the details.
 * - institution_divisions: the levels the school runs, each pinned to a profile.
 * - institution_campuses: the sites of the school.
 * - institution_offerings: which division is taught at which campus.
 * A division, a campus or an offering that is removed is ended, not deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institution', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('short_name', 16)->nullable();
            $table->string('code', 6)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('website')->nullable();
            $table->string('district', 60)->nullable();
            $table->char('country', 2)->nullable();
            $table->text('physical_address')->nullable();
            $table->text('postal_address')->nullable();
            $table->uuid('logo_document_id')->nullable();
            $table->timestampTz('opened_at', 6)->nullable();
            $table->uuid('opened_by')->nullable();
            $table->timestampsTz(6);
        });

        DB::statement('CREATE UNIQUE INDEX institution_one_row ON institution ((true))');

        DB::table('institution')->insert([
            'id' => Ids::next(),
            'name' => mb_substr(config()->string('acadly.school_name'), 0, 120),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::create('institution_divisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 80);
            $table->string('profile_ref', 64);
            $table->unsignedSmallInteger('position');
            $table->timestampsTz(6);
            $table->timestampTz('ended_at', 6)->nullable();
        });

        Schema::create('institution_campuses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 80);
            $table->string('place', 120);
            $table->text('address')->nullable();
            $table->unsignedSmallInteger('position');
            $table->timestampsTz(6);
            $table->timestampTz('ended_at', 6)->nullable();
        });

        Schema::create('institution_offerings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('division_id')->constrained('institution_divisions');
            $table->foreignUuid('campus_id')->constrained('institution_campuses');
            $table->timestampTz('started_at', 6);
            $table->timestampTz('ended_at', 6)->nullable();
        });

        DB::statement('CREATE UNIQUE INDEX institution_offerings_one_active ON institution_offerings (division_id, campus_id) WHERE ended_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('institution_offerings');
        Schema::dropIfExists('institution_campuses');
        Schema::dropIfExists('institution_divisions');
        Schema::dropIfExists('institution');
    }
};
