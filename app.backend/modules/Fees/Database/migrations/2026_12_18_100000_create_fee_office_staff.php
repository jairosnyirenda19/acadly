<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-100. Each finance office names the people who work in it. A fees role
 * alone does not put a person in an office: they must be on its list. The
 * school's own office is keyed 'school', a named office by its id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_office_staff', function (Blueprint $table): void {
            $table->string('office_id', 40);
            $table->foreignUuid('user_id')->constrained('users');
            $table->uuid('added_by')->nullable();
            $table->timestampTz('added_at', 6);
            $table->primary(['office_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_office_staff');
    }
};
