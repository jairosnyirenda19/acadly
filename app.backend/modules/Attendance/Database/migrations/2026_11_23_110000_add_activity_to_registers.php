<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A register taken in an activity keeps the activity's name, as a lesson register keeps its subject. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_registers', function (Blueprint $table): void {
            $table->string('activity', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_registers', function (Blueprint $table): void {
            $table->dropColumn('activity');
        });
    }
};
