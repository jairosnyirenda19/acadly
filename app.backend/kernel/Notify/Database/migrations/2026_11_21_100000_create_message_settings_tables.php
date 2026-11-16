<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The school's message settings (04 §5, §8, §10).
 *
 * - notification_routes: the provider of each channel. The SMS access key is
 *   kept encrypted and never returned. Delivery reports arrive at an address
 *   that names `receipt_token`, so nobody can post a report without it.
 * - notification_limits: quiet hours and the school's own SMS allowance.
 * - notice_wordings: the school's own words for a notice. Null keeps the
 *   declared words.
 * - institution_topic_settings: a notice switched off, and which contacts of
 *   a party hear it.
 * - notification_deliveries gains the provider, the units, the moment of
 *   delivery and the delivery it repeats.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_routes', function (Blueprint $table): void {
            $table->string('channel', 20)->primary();
            $table->string('provider', 40);
            $table->string('sender', 11)->nullable();
            $table->string('username', 80)->nullable();
            $table->text('secret')->nullable();
            $table->string('secret_hint', 4)->nullable();
            $table->string('receipt_token', 64)->unique();
            $table->string('from_name', 120)->nullable();
            $table->string('reply_to', 190)->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampTz('updated_at', 6);
        });

        Schema::create('notification_limits', function (Blueprint $table): void {
            $table->string('id', 10)->primary();
            $table->time('quiet_from')->nullable();
            $table->time('quiet_to')->nullable();
            $table->unsignedInteger('sms_per_month')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampTz('updated_at', 6);
        });

        Schema::create('notice_wordings', function (Blueprint $table): void {
            $table->string('topic_key', 120);
            $table->string('locale', 10);
            $table->text('subject')->nullable();
            $table->text('lines')->nullable();
            $table->text('short')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampTz('updated_at', 6);

            $table->primary(['topic_key', 'locale']);
        });

        Schema::create('institution_topic_settings', function (Blueprint $table): void {
            $table->string('topic_key', 120)->primary();
            $table->boolean('enabled')->default(true);
            $table->string('contacts', 8)->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampTz('updated_at', 6);
        });

        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->string('provider', 40)->nullable();
            $table->unsignedSmallInteger('units')->default(0);
            $table->timestampTz('delivered_at', 6)->nullable();
            $table->uuid('resent_from')->nullable()->unique();
            $table->uuid('requested_by')->nullable();

            $table->index(['channel', 'provider_ref']);
            $table->index('queued_at');
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->dropIndex(['channel', 'provider_ref']);
            $table->dropIndex(['queued_at']);
            $table->dropUnique(['resent_from']);
            $table->dropColumn(['provider', 'units', 'delivered_at', 'resent_from', 'requested_by']);
        });
        Schema::dropIfExists('institution_topic_settings');
        Schema::dropIfExists('notice_wordings');
        Schema::dropIfExists('notification_limits');
        Schema::dropIfExists('notification_routes');
    }
};
