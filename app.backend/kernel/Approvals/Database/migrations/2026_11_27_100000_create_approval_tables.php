<?php

declare(strict_types=1);

use Acadly\Kernel\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Approvals (the approved design).
 *
 * - approval_chains: append-only. The newest row of a topic is its chain in
 *   force; a row with no steps puts the topic's default back.
 * - approval_requests: one request travelling its chain, with the chain it
 *   started with, so a later change does not move it.
 * - approval_steps: the steps laid for a request: who is asked, and how it stands.
 * - approval_answers: append-only. Each yes or no, by whom, and why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_chains', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('topic_key', 80)->index();
            $table->jsonb('steps')->nullable();
            $table->uuid('fallback_role_id')->nullable();
            $table->uuid('saved_by')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestampTz('created_at', 6);
        });

        Schema::create('approval_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('topic_key', 80);
            $table->uuid('subject_id');
            $table->uuid('requester_id');
            $table->string('state', 12);
            $table->unsignedSmallInteger('current')->default(0);
            $table->jsonb('chain');
            $table->timestampTz('created_at', 6);
            $table->timestampTz('decided_at', 6)->nullable();

            $table->index(['topic_key', 'subject_id']);
            $table->index('state');
        });

        Schema::create('approval_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->constrained('approval_requests');
            $table->unsignedSmallInteger('position');
            $table->string('label', 120);
            $table->unsignedSmallInteger('needs')->default(1);
            $table->string('state', 12);
            $table->jsonb('asked');
            $table->string('note', 255)->nullable();
            $table->timestampTz('asked_at', 6)->nullable();
            $table->timestampTz('finished_at', 6)->nullable();

            $table->unique(['request_id', 'position']);
        });

        Schema::create('approval_answers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('step_id')->constrained('approval_steps');
            $table->uuid('user_id');
            $table->string('answer', 10);
            $table->string('reason', 255)->nullable();
            $table->timestampTz('created_at', 6);
        });

        // The newest row wins: a number that only grows, whatever the clock says.
        DB::statement('alter table approval_chains add column seq bigserial');
        AppendOnly::protect('approval_chains');
        AppendOnly::protect('approval_answers');
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_answers');
        Schema::dropIfExists('approval_steps');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_chains');
    }
};
