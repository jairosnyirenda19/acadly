<?php

declare(strict_types=1);

use Acadly\Kernel\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A morning register taken after its day, kept as it was asked for while it
 * waits for a yes (D-081). It is not a register yet: nothing counts and
 * nobody is told until the last yes submits it. Whether it waits, was
 * approved, declined or withdrawn is the approval request's state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_late_registers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('class_id');
            $table->date('day');
            $table->jsonb('marks');
            $table->string('reason', 255);
            $table->uuid('requested_by');
            $table->timestampTz('requested_at', 6);

            $table->index(['class_id', 'day']);
        });

        AppendOnly::protect('attendance_late_registers');
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_late_registers');
    }
};
