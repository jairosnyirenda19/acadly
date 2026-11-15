<?php

declare(strict_types=1);

use Acadly\Kernel\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/architecture/04-notification-catalogue.md §10.
 *
 * - notification_topics: mirror of the catalogue. Written only by acadly:sync.
 * - system_sync_marks: the hash each part of the catalogue had at the last sync.
 * - notification_requests: one row per notice sent, written in the sender's
 *   transaction. Unique per event. Append-only.
 * - notification_resolutions: a request that has been resolved into
 *   deliveries. Append-only; it is what makes resolving happen once.
 * - notification_subscriptions: the desk. User × topic × scope.
 * - notification_preferences: a person's channel choices and opt-outs.
 * - notification_deliveries: one row per recipient × channel, written
 *   before the send, then settled.
 * - in_app_notifications: the bell.
 * - notification_usage: metering of paid channels. Append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_topics', function (Blueprint $table): void {
            $table->string('key', 120)->primary();
            $table->string('owner', 60);
            $table->string('name');
            $table->string('category', 60);
            $table->boolean('desk');
            $table->jsonb('acted_on_with');
            $table->jsonb('default_channels');
            $table->jsonb('allowed_channels');
            $table->timestampTz('updated_at', 6);
        });

        Schema::create('system_sync_marks', function (Blueprint $table): void {
            $table->string('name', 60)->primary();
            $table->char('hash', 64);
            $table->timestampTz('synced_at', 6);
        });

        Schema::create('notification_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('topic_key', 120);
            $table->jsonb('payload');
            $table->string('subject_type', 60);
            $table->string('subject_id', 64)->nullable();
            $table->string('scope_type', 20);
            $table->uuid('scope_id')->nullable();
            $table->string('dedupe_key', 255)->unique();
            $table->uuid('actor_user_id')->nullable();
            $table->jsonb('people');
            $table->timestampTz('requested_at', 6);
        });

        Schema::create('notification_resolutions', function (Blueprint $table): void {
            $table->uuid('request_id')->primary();
            $table->timestampTz('resolved_at', 6);
        });

        Schema::create('notification_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('topic_key', 120);
            $table->string('scope_type', 20);
            $table->uuid('scope_id')->nullable();
            $table->jsonb('channels')->nullable();
            $table->string('source', 16);
            $table->timestampTz('started_at', 6);
            $table->timestampTz('ended_at', 6)->nullable();
            $table->uuid('ended_by')->nullable();

            $table->index(['topic_key', 'ended_at']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX notification_subscriptions_one_active ON notification_subscriptions '
            ."(user_id, topic_key, scope_type, coalesce(scope_id, '00000000-0000-0000-0000-000000000000')) WHERE ended_at IS NULL"
        );

        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('topic_key', 120);
            $table->string('channel', 20);
            $table->boolean('enabled');
            $table->timestampTz('updated_at', 6);

            $table->unique(['user_id', 'topic_key', 'channel']);
        });

        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('request_id');
            $table->string('topic_key', 120);
            $table->string('audience', 16);
            $table->string('recipient_key', 160);
            $table->uuid('recipient_user_id')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_email')->nullable();
            $table->string('recipient_phone', 32)->nullable();
            $table->string('channel', 20);
            $table->text('subject');
            $table->text('body');
            $table->text('short')->nullable();
            $table->text('action_url')->nullable();
            $table->string('subject_type', 60);
            $table->string('subject_id', 64)->nullable();
            $table->string('scope_type', 20);
            $table->uuid('scope_id')->nullable();
            $table->string('status', 16);
            $table->string('reason', 120)->nullable();
            $table->string('provider_ref', 120)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('dedupe_key', 255);
            $table->timestampTz('queued_at', 6);
            $table->timestampTz('settled_at', 6)->nullable();

            $table->unique(['dedupe_key', 'recipient_key', 'channel']);
            $table->index(['status', 'queued_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('in_app_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->uuid('delivery_id')->unique();
            $table->string('topic_key', 120);
            $table->text('subject');
            $table->text('body');
            $table->text('action_url')->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('read_at', 6)->nullable();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('notification_usage', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('delivery_id')->unique();
            $table->string('channel', 20);
            $table->unsignedSmallInteger('units');
            $table->timestampTz('used_at', 6);

            $table->index(['channel', 'used_at']);
        });

        AppendOnly::protect('notification_requests');
        AppendOnly::protect('notification_resolutions');
        AppendOnly::protect('notification_usage');
    }

    public function down(): void
    {
        foreach (['notification_usage', 'in_app_notifications', 'notification_deliveries', 'notification_preferences',
            'notification_subscriptions', 'notification_resolutions', 'notification_requests', 'system_sync_marks',
            'notification_topics'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
