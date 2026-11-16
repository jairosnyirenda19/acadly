<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Activities on the timetable (the approved design): a period of a class may
 * hold an activity, such as games or library, instead of a subject. It has a
 * name and, optionally, who is on duty. Bell times keep activities for the
 * whole level and which periods need a register in their list of periods.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_timetable', function (Blueprint $table): void {
            $table->uuid('subject_id')->nullable()->change();
            $table->string('activity', 60)->nullable();
        });

        DB::statement('ALTER TABLE academic_timetable ADD CONSTRAINT academic_timetable_subject_or_activity CHECK ((subject_id IS NULL) <> (activity IS NULL))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE academic_timetable DROP CONSTRAINT IF EXISTS academic_timetable_subject_or_activity');

        Schema::table('academic_timetable', function (Blueprint $table): void {
            $table->dropColumn('activity');
        });
    }
};
