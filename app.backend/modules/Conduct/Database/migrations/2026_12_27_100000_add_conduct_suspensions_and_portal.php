<?php

declare(strict_types=1);

use Acadly\Kernel\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-111. A suspension ended early: the last day it covers, with a reason.
 * Added, never changed. The watch rule with missed lessons, and whether
 * families see in the portal the conduct they were told about (D-110).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conduct_suspension_ends', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('outcome_id')->constrained('conduct_outcomes');
            $table->date('ended_after');
            $table->text('reason');
            $table->uuid('ended_by');
            $table->timestampTz('created_at', 6);
            $table->index('outcome_id');
        });

        AppendOnly::protect('conduct_suspension_ends');

        Schema::table('conduct_watch', function (Blueprint $table): void {
            // 0 incidents turns the rule off.
            $table->unsignedSmallInteger('missed_incidents')->default(2);
            $table->unsignedSmallInteger('missed_lessons')->default(4);
            $table->unsignedSmallInteger('missed_days')->default(14);
            $table->boolean('portal')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('conduct_watch', function (Blueprint $table): void {
            $table->dropColumn(['missed_incidents', 'missed_lessons', 'missed_days', 'portal']);
        });
        Schema::dropIfExists('conduct_suspension_ends');
    }
};
