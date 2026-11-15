<?php

declare(strict_types=1);

use Acadly\Kernel\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The transactional outbox (docs/architecture/05-data-and-api.md §5).
 *
 *  - outbox_events: one row per domain event, written in the business
 *    transaction. Publishing sets published_at; nothing else changes.
 *  - consumed_events: one row per event a consumer has handled. Append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->jsonb('payload');
            $table->timestampTz('occurred_at', 6);
            $table->timestampTz('published_at', 6)->nullable();
            $table->string('last_error', 500)->nullable();

            $table->index(['name', 'occurred_at']);
        });

        // The publisher looks only at what is waiting.
        DB::statement('CREATE INDEX outbox_events_waiting ON outbox_events (id) WHERE published_at IS NULL');

        Schema::create('consumed_events', function (Blueprint $table): void {
            $table->string('consumer', 120);
            $table->uuid('event_id');
            $table->timestampTz('consumed_at', 6);

            $table->primary(['consumer', 'event_id']);
        });

        AppendOnly::protect('consumed_events');
    }

    public function down(): void
    {
        Schema::dropIfExists('consumed_events');
        Schema::dropIfExists('outbox_events');
    }
};
