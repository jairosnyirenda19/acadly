<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-096. The school's own look: the name the console shows (null shows the
 * product's name), a main and an accent colour (null keeps the register
 * book's), and whether the last word of the name takes the accent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institution', function (Blueprint $table): void {
            $table->string('app_name', 40)->nullable();
            $table->char('brand_main', 7)->nullable();
            $table->char('brand_accent', 7)->nullable();
            $table->boolean('accent_last_word')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('institution', function (Blueprint $table): void {
            $table->dropColumn(['app_name', 'brand_main', 'brand_accent', 'accent_last_word']);
        });
    }
};
