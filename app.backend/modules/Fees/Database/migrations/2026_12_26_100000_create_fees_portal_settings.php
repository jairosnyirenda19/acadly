<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-108. Fees in the parent portal. One row: whether the confirmed parents of
 * each child see the child's fees (on unless the school turns it off), and the
 * words that tell families how to pay, written once by the school.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fees_portal_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('id')->primary();
            $table->boolean('show_to_families')->default(true);
            $table->text('how_to_pay')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampTz('updated_at', 6)->nullable();
        });

        DB::statement('alter table fees_portal_settings add constraint fees_portal_settings_one check (id = 1)');
        DB::statement('alter table fees_portal_settings add constraint fees_portal_settings_how_to_pay check (how_to_pay is null or char_length(how_to_pay) <= 600)');
        DB::table('fees_portal_settings')->insert(['id' => 1, 'show_to_families' => true, 'how_to_pay' => null, 'updated_by' => null, 'updated_at' => null]);
    }

    public function down(): void
    {
        Schema::dropIfExists('fees_portal_settings');
    }
};
