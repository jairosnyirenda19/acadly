<?php

declare(strict_types=1);

use Acadly\Kernel\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-114. A learner left during the year and the office asked to waive the
 * fees not yet due. What would be waived, per charge, waits for the
 * approval in `fees.waive_on_leaving`; the last yes makes the waivers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_leaving_waivers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->date('last_day');
            $table->jsonb('lines');
            $table->bigInteger('total_minor');
            $table->uuid('requested_by');
            $table->timestampTz('created_at', 6);
            $table->index('learner_id');
        });

        AppendOnly::protect('fee_leaving_waivers');
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_leaving_waivers');
    }
};
