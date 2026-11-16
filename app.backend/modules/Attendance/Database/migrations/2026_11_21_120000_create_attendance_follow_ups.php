<?php

declare(strict_types=1);

use Acadly\Kernel\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What staff did when the family of an absent learner was not reached:
 * told the next contact, or called the family. Append-only: each step is
 * a fact in the history of the absence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_follow_ups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('mark_id');
            $table->foreignUuid('register_id')->constrained('attendance_registers');
            $table->uuid('learner_id');
            $table->string('kind', 16);
            $table->string('note', 255)->nullable();
            $table->uuid('done_by');
            $table->timestampTz('done_at', 6);

            $table->index(['register_id', 'learner_id']);
        });

        AppendOnly::protect('attendance_follow_ups');
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_follow_ups');
    }
};
