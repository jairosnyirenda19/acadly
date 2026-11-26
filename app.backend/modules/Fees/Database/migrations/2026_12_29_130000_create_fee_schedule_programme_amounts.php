<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-116. A college's term fees by programme and mode of study: an amount per
 * year of study, or per registered credit. It replaces the term's default
 * amount for that fee item, for the students of that programme and mode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_schedule_programme_amounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('schedule_id')->constrained('fee_schedules');
            $table->foreignUuid('item_id')->constrained('fee_items');
            $table->uuid('programme_id');
            $table->string('mode', 12);
            // A year of study ('Y1'), or 'credit' for an amount per registered credit.
            $table->string('level_code', 20);
            $table->bigInteger('amount_minor');
            $table->unique(['schedule_id', 'item_id', 'programme_id', 'mode', 'level_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_schedule_programme_amounts');
    }
};
