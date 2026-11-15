<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A role that was held and is no longer wanted is retired, not deleted: the
 * history of who held it keeps pointing at it. A role never held is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_roles', function (Blueprint $table): void {
            $table->timestampTz('retired_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('access_roles', function (Blueprint $table): void {
            $table->dropColumn('retired_at');
        });
    }
};
