<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-step sign-in and the password's age (D-089).
 *
 * - users.two_step_secret: the authenticator key, encrypted; set while it is
 *   being turned on, kept once it is on.
 * - users.two_step_on_at: when it was turned on; null while it is off.
 * - users.two_step_last_step: the last 30-second step a code was accepted
 *   for, so a code is never accepted twice.
 * - users.password_changed_at: shown on Your account.
 * - two_step_recovery_codes: ten per turn-on, each used once, only a hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('two_step_secret')->nullable();
            $table->timestampTz('two_step_on_at', 6)->nullable();
            $table->unsignedBigInteger('two_step_last_step')->nullable();
            $table->timestampTz('password_changed_at', 6)->nullable();
        });

        Schema::create('two_step_recovery_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('code_hash', 64);
            $table->timestampTz('created_at', 6);
            $table->timestampTz('used_at', 6)->nullable();
            $table->timestampTz('retired_at', 6)->nullable();

            $table->index(['user_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('two_step_recovery_codes');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['two_step_secret', 'two_step_on_at', 'two_step_last_step', 'password_changed_at']);
        });
    }
};
