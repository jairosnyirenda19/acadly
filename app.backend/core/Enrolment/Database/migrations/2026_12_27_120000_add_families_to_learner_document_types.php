<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-110. A learner document type the family sees in the parent portal. Off
 * until the school ticks it, and only for a type every staff member who sees
 * the learner may open.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learner_document_types', function (Blueprint $table): void {
            $table->boolean('families')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('learner_document_types', function (Blueprint $table): void {
            $table->dropColumn('families');
        });
    }
};
