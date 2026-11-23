<?php

declare(strict_types=1);

use Acadly\Kernel\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-104. Classroom: each course offering's page. Resources and
 * announcements; each course's share for continuous assessment; assignments
 * and exam papers; hand-ins and withdrawals (each a new row: the rows say
 * what stood at the deadline); and marks (each a new row: the latest counts,
 * and a change after release keeps its reason). Files are documents in the
 * kernel's store, owned by these rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classroom_resources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('division_id');
            $table->uuid('period_id');
            $table->uuid('offering_id');
            $table->string('section', 80);
            $table->string('title', 160);
            // 'file' or 'link'.
            $table->string('kind', 6);
            $table->string('url', 500)->nullable();
            $table->uuid('document_id')->nullable();
            $table->date('shows_from')->nullable();
            $table->boolean('pinned');
            $table->uuid('created_by');
            $table->timestampTz('created_at', 6);
            $table->timestampTz('removed_at', 6)->nullable();
            $table->uuid('removed_by')->nullable();
            $table->index('offering_id');
        });

        Schema::create('classroom_announcements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('division_id');
            $table->uuid('period_id');
            $table->uuid('offering_id');
            $table->string('title', 160);
            $table->text('body');
            $table->boolean('sms');
            $table->uuid('by_user_id');
            $table->timestampTz('created_at', 6);
            $table->index('offering_id');
        });

        Schema::create('classroom_announcement_reads', function (Blueprint $table): void {
            $table->uuid('announcement_id');
            $table->uuid('user_id');
            $table->timestampTz('read_at', 6);
            $table->primary(['announcement_id', 'user_id']);
        });

        Schema::create('classroom_shares', function (Blueprint $table): void {
            $table->uuid('offering_id')->primary();
            $table->uuid('division_id');
            $table->unsignedSmallInteger('ca_share');
            $table->uuid('updated_by');
            $table->timestampTz('updated_at', 6);
        });

        Schema::create('classroom_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('division_id');
            $table->uuid('period_id');
            $table->uuid('offering_id');
            // 'assignment' or 'exam'.
            $table->string('kind', 10);
            $table->string('title', 160);
            $table->text('instructions');
            $table->timestampTz('shows_at', 6);
            $table->timestampTz('due_at', 6);
            $table->timestampTz('late_until', 6)->nullable();
            $table->unsignedSmallInteger('out_of');
            $table->decimal('weight', 5, 2);
            $table->jsonb('accepts');
            $table->unsignedSmallInteger('max_files');
            $table->unsignedSmallInteger('max_mb');
            $table->timestampTz('released_at', 6)->nullable();
            $table->uuid('released_by')->nullable();
            $table->uuid('created_by');
            $table->timestampsTz(6);
            $table->timestampTz('removed_at', 6)->nullable();
            $table->index('offering_id');
            $table->index(['division_id', 'due_at']);
        });

        Schema::create('classroom_hand_ins', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('task_id')->constrained('classroom_tasks');
            $table->uuid('learner_id');
            $table->uuid('user_id');
            // 'hand_in' or 'withdraw'.
            $table->string('action', 8);
            $table->boolean('late');
            $table->timestampTz('created_at', 6);
            $table->index(['task_id', 'learner_id']);
            $table->index('learner_id');
        });

        Schema::create('classroom_marks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('task_id')->constrained('classroom_tasks');
            $table->uuid('learner_id');
            $table->decimal('mark', 6, 2);
            $table->text('feedback')->nullable();
            $table->string('reason', 255)->nullable();
            $table->uuid('by_user_id');
            $table->timestampTz('created_at', 6);
            $table->index(['task_id', 'learner_id']);
        });

        foreach (['classroom_hand_ins', 'classroom_marks', 'classroom_announcements'] as $table) {
            AppendOnly::protect($table);
        }
    }

    public function down(): void
    {
        foreach (['classroom_marks', 'classroom_hand_ins', 'classroom_tasks', 'classroom_shares', 'classroom_announcement_reads', 'classroom_announcements', 'classroom_resources'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
