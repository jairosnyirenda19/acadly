<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-112. The last time the scheduler and the queue worker were seen working.
 * The readiness check fails when either has stopped, so a school is never
 * told "ready" while no message can reach a family.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_heartbeats', function (Blueprint $table): void {
            $table->string('name', 40)->primary();
            $table->timestampTz('beat_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_heartbeats');
    }
};
