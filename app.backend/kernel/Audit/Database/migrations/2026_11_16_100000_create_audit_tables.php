<?php

declare(strict_types=1);

use Acadly\Kernel\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail (docs/architecture/10-audit-trail.md §3–5).
 *
 *  - audit_events: one chained line per audited action. Append-only. No
 *    foreign keys: removing an account or a record never touches its history.
 *  - audit_payloads: encrypted personal values. Erasable, so not append-only;
 *    the chain covers each payload's hash, not the payload.
 *  - audit_seals: the signed head of the chain, taken nightly. Append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->bigInteger('seq')->unique();
            $table->char('prev_hash', 64);
            $table->char('hash', 64)->unique();
            $table->timestampTz('occurred_at', 6);
            $table->timestampTz('recorded_at', 6);

            $table->uuid('division_id')->nullable();
            $table->uuid('campus_id')->nullable();

            $table->string('module', 60);
            $table->string('action', 120);
            $table->string('outcome', 10);
            $table->string('refusal', 200)->nullable();

            $table->uuid('actor_user_id')->nullable();
            $table->string('actor_kind', 20);
            $table->string('actor_label')->nullable();
            $table->jsonb('actor_roles');
            $table->uuid('acting_for_user_id')->nullable();
            $table->uuid('support_grant_id')->nullable();

            $table->string('session_ref', 64)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->string('device_id', 64)->nullable();
            $table->string('ip', 64)->nullable();
            $table->string('user_agent', 400)->nullable();
            $table->string('channel', 20);

            $table->string('subject_type', 60)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->string('subject_label')->nullable();
            $table->uuid('about_person_id')->nullable();

            $table->jsonb('changes');
            $table->uuid('payload_id')->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->text('reason')->nullable();
            $table->string('journey_ref', 64)->nullable();
            $table->string('journey_step', 120)->nullable();
            $table->string('journey_outcome', 120)->nullable();
            $table->jsonb('meta');

            $table->index('occurred_at');
            $table->index(['actor_user_id', 'occurred_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('about_person_id');
            $table->index('session_ref');
            $table->index(['action', 'occurred_at']);
        });

        Schema::create('audit_payloads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('payload_hash', 64);
            $table->text('ciphertext')->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('erased_at', 6)->nullable();
            $table->string('erasure_reason')->nullable();
        });

        Schema::create('audit_seals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->timestampTz('sealed_at', 6);
            $table->bigInteger('last_seq');
            $table->char('head_hash', 64);
            $table->bigInteger('line_count');
            $table->char('signature', 64);
        });

        AppendOnly::protect('audit_events');
        AppendOnly::protect('audit_seals');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_seals');
        Schema::dropIfExists('audit_payloads');
        Schema::dropIfExists('audit_events');
    }
};
