<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invitations to the parent portal (D-083): one row each time the office
 * invites a phone number. Append-only; the newest row is the date the
 * Families screen shows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('phone', 20);
            $table->uuid('invited_by');
            $table->timestampTz('invited_at', 6);

            $table->index(['phone', 'invited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_invitations');
    }
};
