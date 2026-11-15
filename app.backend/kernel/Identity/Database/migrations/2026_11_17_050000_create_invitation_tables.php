<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invitations to staff accounts.
 *
 * - identity_invitation_tokens: the hashed token of each invitation link, as
 *   the password reset tokens are kept. One per address.
 * - users.invitation_sent_at: when the last invitation was sent. An invited
 *   account that has none is "ready to send".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_invitation_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('invitation_sent_at', 6)->nullable();
        });

        // An account may now also be invited: made by the school, waiting for its first password.
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_status_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active', 'disabled', 'invited'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_status_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active', 'disabled'))");

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('invitation_sent_at');
        });

        Schema::dropIfExists('identity_invitation_tokens');
    }
};
