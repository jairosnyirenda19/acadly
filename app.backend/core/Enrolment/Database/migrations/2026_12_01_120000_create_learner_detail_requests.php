<?php

declare(strict_types=1);

use Acadly\Kernel\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A correction of a learner's name, date of birth or sex, kept as it was
 * asked for while it waits for a yes (D-081). The details in force stay
 * until the last yes applies it. Its state is the approval request's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learner_detail_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('learner_id')->constrained('learners');
            $table->jsonb('details');
            $table->jsonb('was');
            $table->string('reason', 255);
            $table->uuid('requested_by');
            $table->timestampTz('requested_at', 6);

            $table->index('learner_id');
        });

        AppendOnly::protect('learner_detail_requests');
    }

    public function down(): void
    {
        Schema::dropIfExists('learner_detail_requests');
    }
};
