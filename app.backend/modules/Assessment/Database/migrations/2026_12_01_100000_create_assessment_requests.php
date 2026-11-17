<?php

declare(strict_types=1);

use Acadly\Kernel\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What is asked for when an assessment change needs someone else's yes
 * (D-081). Each row is what was asked, kept as asked; whether it is waiting,
 * approved, declined or withdrawn is the approval request's state. Nothing
 * changes until the last yes; then the module's own action applies it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('result_publish_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('class_id');
            $table->uuid('period_id');
            $table->string('fingerprint', 64);
            $table->uuid('requested_by');
            $table->timestampTz('requested_at', 6);

            $table->index(['class_id', 'period_id']);
        });

        Schema::create('assessment_mark_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('assessment_id')->constrained('assessments');
            $table->foreignUuid('learner_id')->constrained('learners');
            $table->decimal('value', 8, 2)->nullable();
            $table->string('reason', 20)->nullable();
            $table->string('note', 255);
            $table->decimal('was_value', 8, 2)->nullable();
            $table->string('was_reason', 20)->nullable();
            $table->uuid('requested_by');
            $table->timestampTz('requested_at', 6);

            $table->index(['assessment_id', 'learner_id']);
        });

        Schema::create('grading_plan_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('division_id');
            $table->string('level_code', 20);
            $table->uuid('period_id');
            $table->uuid('subject_id')->nullable();
            $table->jsonb('plan');
            $table->string('reason', 255);
            $table->uuid('requested_by');
            $table->timestampTz('requested_at', 6);

            $table->index(['division_id', 'level_code', 'period_id']);
        });

        AppendOnly::protect('result_publish_requests');
        AppendOnly::protect('assessment_mark_requests');
        AppendOnly::protect('grading_plan_requests');
    }

    public function down(): void
    {
        Schema::dropIfExists('grading_plan_requests');
        Schema::dropIfExists('assessment_mark_requests');
        Schema::dropIfExists('result_publish_requests');
    }
};
