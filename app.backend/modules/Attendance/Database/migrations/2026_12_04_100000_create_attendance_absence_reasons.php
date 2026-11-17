<?php

declare(strict_types=1);

use Acadly\Kernel\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reason a family gave for a morning absence, in the parent portal
 * (D-084). Append-only: a later reason is a new row, and staff see the
 * latest. A reason never changes the mark.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_absence_reasons', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('register_id')->constrained('attendance_registers');
            $table->uuid('learner_id');
            $table->string('kind', 16);
            $table->string('note', 255)->nullable();
            $table->uuid('given_by');
            $table->timestampTz('given_at', 6);

            $table->index(['register_id', 'learner_id']);
            $table->index(['learner_id', 'given_at']);
        });

        AppendOnly::protect('attendance_absence_reasons');
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_absence_reasons');
    }
};
