<?php

declare(strict_types=1);

use Acadly\Kernel\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-108. The school's words for the three review steps, in English and in
 * Chichewa. Each save is a new row; the latest is in force. The strokes and
 * the stored step values never change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observation_step_words', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('starting', 24);
            $table->string('growing', 24);
            $table->string('can_do', 24);
            $table->string('starting_ny', 24)->nullable();
            $table->string('growing_ny', 24)->nullable();
            $table->string('can_do_ny', 24)->nullable();
            $table->uuid('saved_by');
            $table->timestampTz('saved_at', 6);
        });

        AppendOnly::protect('observation_step_words');
    }

    public function down(): void
    {
        Schema::dropIfExists('observation_step_words');
    }
};
