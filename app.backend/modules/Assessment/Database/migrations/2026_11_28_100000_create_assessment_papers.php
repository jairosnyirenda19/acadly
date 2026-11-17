<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marked papers (the approved design): which document is which page of
 * which learner's paper for an assessment. The file itself is a kernel
 * document of kind `assessment.paper`, owned by the assessment. A page
 * removed keeps its row, with who removed it and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_papers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('assessment_id')->constrained('assessments');
            $table->foreignUuid('learner_id')->constrained('learners');
            $table->uuid('document_id');
            $table->unsignedSmallInteger('position');
            $table->uuid('added_by');
            $table->timestampTz('added_at', 6);
            $table->uuid('removed_by')->nullable();
            $table->timestampTz('removed_at', 6)->nullable();

            $table->index(['assessment_id', 'learner_id']);
            $table->index('learner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_papers');
    }
};
