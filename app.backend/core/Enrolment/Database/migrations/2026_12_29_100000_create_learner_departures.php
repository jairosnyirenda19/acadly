<?php

declare(strict_types=1);

use Acadly\Kernel\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-114. A learner who leaves during the year: the last day, why, where they
 * go, and whether the office asked to waive the fees not yet due. Added,
 * never changed; a learner who comes back is placed again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learner_departures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('learner_id')->constrained('learners');
            $table->uuid('placement_id');
            $table->date('last_day');
            $table->string('kind', 20);
            $table->string('destination', 160)->nullable();
            $table->string('note', 500)->nullable();
            $table->boolean('waive_asked');
            $table->uuid('recorded_by');
            $table->timestampTz('created_at', 6);
            $table->index(['learner_id', 'last_day']);
        });

        AppendOnly::protect('learner_departures');
    }

    public function down(): void
    {
        Schema::dropIfExists('learner_departures');
    }
};
