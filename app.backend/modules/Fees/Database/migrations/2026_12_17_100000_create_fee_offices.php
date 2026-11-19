<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-099. Finance offices: the school chooses one office for the whole school
 * (no rows here), or splits the offerings (a division at a campus) among named
 * offices. An offering no office names belongs to the school's own office,
 * keyed 'school'. A cash day is closed by each office for itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_offices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 80);
            $table->unsignedSmallInteger('position');
            $table->timestampsTz(6);
        });

        Schema::create('fee_office_offerings', function (Blueprint $table): void {
            $table->foreignUuid('office_id')->constrained('fee_offices');
            $table->uuid('offering_id')->primary();
        });

        Schema::table('fee_days', function (Blueprint $table): void {
            $table->string('office_id', 40)->default('school');
        });
        DB::statement('alter table fee_days drop constraint fee_days_pkey');
        DB::statement('alter table fee_days add primary key (day, office_id)');
    }

    public function down(): void
    {
        DB::statement('alter table fee_days drop constraint fee_days_pkey');
        DB::statement("delete from fee_days where office_id <> 'school'");
        DB::statement('alter table fee_days add primary key (day)');
        Schema::table('fee_days', function (Blueprint $table): void {
            $table->dropColumn('office_id');
        });
        Schema::dropIfExists('fee_office_offerings');
        Schema::dropIfExists('fee_offices');
    }
};
