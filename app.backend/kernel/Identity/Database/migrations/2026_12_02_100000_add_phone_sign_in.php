<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sign-in by phone number and a code sent by SMS (D-082), for parents and
 * guardians, who have no email address and no password.
 *
 * - users.phone: the international form, such as +265991234567. An account
 *   has an email address, a phone number, or both.
 * - sign_in_codes: one row per code sent. The code itself is never kept,
 *   only a keyed hash. A code is used once, expires after ten minutes, and
 *   allows five attempts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
            $table->string('phone', 20)->nullable()->unique();
        });

        DB::statement('ALTER TABLE users ADD CONSTRAINT users_reachable_check CHECK (email IS NOT NULL OR phone IS NOT NULL)');

        Schema::create('sign_in_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('phone', 20);
            $table->string('code_hash', 64);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('ip', 45)->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('expires_at', 6);
            $table->timestampTz('used_at', 6)->nullable();

            $table->index(['phone', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sign_in_codes');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_reachable_check');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('phone');
        });
    }
};
