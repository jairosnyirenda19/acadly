<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A teacher's lessons as a calendar feed (D-089): one private address per
 * person, kept only as a hash. Turning it off, or on again, ends the old one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_feeds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('last_used_at', 6)->nullable();
            $table->timestampTz('ended_at', 6)->nullable();

            $table->index(['user_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_feeds');
    }
};
