<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a code sent by SMS is for (D-087): `sign_in` opens the parent portal;
 * `link:<account id>` confirms a phone number a member of staff adds to
 * their own account. A code for one purpose never serves the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sign_in_codes', function (Blueprint $table): void {
            $table->string('purpose', 60)->default('sign_in');
        });

        // A parent account closed because its number moved to a staff account keeps neither.
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_reachable_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_reachable_check CHECK (email IS NOT NULL OR phone IS NOT NULL OR status = 'disabled')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_reachable_check');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_reachable_check CHECK (email IS NOT NULL OR phone IS NOT NULL)');

        Schema::table('sign_in_codes', function (Blueprint $table): void {
            $table->dropColumn('purpose');
        });
    }
};
