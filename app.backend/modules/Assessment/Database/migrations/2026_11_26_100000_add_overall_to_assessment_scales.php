<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A scale says how a learner is summed up across subjects: the average
 * percentage, the average grade (highest is best) or the best six (lowest is
 * best). This replaces the yes-or-no "lower is better", which could not say
 * the second.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_scales', function (Blueprint $table): void {
            $table->string('overall', 20)->default('average_percent');
        });

        DB::table('assessment_scales')->where('lower_is_better', true)->update(['overall' => 'best_six']);

        Schema::table('assessment_scales', function (Blueprint $table): void {
            $table->dropColumn('lower_is_better');
        });
    }

    public function down(): void
    {
        Schema::table('assessment_scales', function (Blueprint $table): void {
            $table->boolean('lower_is_better')->default(false);
        });

        DB::table('assessment_scales')->where('overall', 'best_six')->update(['lower_is_better' => true]);

        Schema::table('assessment_scales', function (Blueprint $table): void {
            $table->dropColumn('overall');
        });
    }
};
