<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-110. The part of a support plan written for the family: what the school
 * is doing and what the family can do at home, and whether the family sees
 * it in the portal. Goals, actions and review notes stay in the school.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_plans', function (Blueprint $table): void {
            $table->string('family_school', 600)->nullable();
            $table->string('family_home', 600)->nullable();
            $table->boolean('family_shown')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('support_plans', function (Blueprint $table): void {
            $table->dropColumn(['family_school', 'family_home', 'family_shown']);
        });
    }
};
