<?php

declare(strict_types=1);

use Acadly\Kernel\Support\Ids;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each level keeps its own terms inside the school's year (the approved
 * Academic year screen): academic_periods gains the division it belongs to.
 * Terms saved before this change are given to every level the school runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_periods', function (Blueprint $table): void {
            $table->dropUnique(['year_id', 'position']);
            $table->uuid('division_id')->nullable()->after('year_id');
            $table->foreign('division_id')->references('id')->on('institution_divisions');
        });

        $divisions = DB::table('institution_divisions')->whereNull('ended_at')->orderBy('position')->pluck('id')->all();
        $shared = DB::table('academic_periods')->whereNull('division_id')->get();

        foreach ($shared as $period) {
            foreach ($divisions as $division) {
                $copy = (array) $period;
                $copy['id'] = Ids::next();
                $copy['division_id'] = $division;
                DB::table('academic_periods')->insert($copy);
            }
        }

        DB::table('academic_periods')->whereNull('division_id')->delete();

        Schema::table('academic_periods', function (Blueprint $table): void {
            $table->uuid('division_id')->nullable(false)->change();
            $table->unique(['year_id', 'division_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('academic_periods', function (Blueprint $table): void {
            $table->dropUnique(['year_id', 'division_id', 'position']);
            $table->dropForeign(['division_id']);
            $table->dropColumn('division_id');
        });
    }
};
