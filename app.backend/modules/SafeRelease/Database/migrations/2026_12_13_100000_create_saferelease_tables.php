<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-094. Safe pick-up. The learner's contacts may collect unless barred.
 * Others are added as collectors: for good, or for one day with a code the
 * family passes on (only its hash is kept). A pickup travels
 * `saferelease.pickup` and is never deleted: a refusal stays recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('release_collectors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->string('name', 120);
            $table->string('relationship', 60);
            $table->string('phone', 30)->nullable();
            // One day only, with a code; null for good.
            $table->date('valid_on')->nullable();
            $table->string('code_hash', 64)->nullable();
            $table->string('added_as', 10);
            $table->uuid('added_by');
            $table->timestampTz('created_at', 6);
            $table->timestampTz('ended_at', 6)->nullable();
            $table->uuid('ended_by')->nullable();
            $table->index(['learner_id', 'ended_at']);
        });

        Schema::create('release_bars', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('contact_id');
            $table->text('reason');
            $table->uuid('set_by');
            $table->timestampTz('created_at', 6);
            $table->timestampTz('ended_at', 6)->nullable();
            $table->uuid('ended_by')->nullable();
            $table->index(['learner_id', 'ended_at']);
        });

        Schema::create('pickups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('class_id');
            $table->date('day');
            $table->string('state', 20);
            $table->string('journey_ref', 80);
            // contact, collector, or unknown for someone not on the list.
            $table->string('collector_kind', 10);
            $table->uuid('collector_ref')->nullable();
            $table->string('collector_name', 120);
            $table->string('collector_relationship', 60)->nullable();
            $table->boolean('code_checked')->default(false);
            $table->text('note')->nullable();
            $table->uuid('created_by');
            $table->timestampTz('released_at', 6)->nullable();
            $table->timestampsTz(6);
            $table->index(['class_id', 'day']);
            $table->index(['learner_id', 'day']);
        });
    }

    public function down(): void
    {
        foreach (['pickups', 'release_bars', 'release_collectors'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
