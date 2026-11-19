<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-096. A check code for every document the school issues: printed with its
 * QR, it lets anyone confirm the document is genuine on the public check page,
 * which shows its kind, number, date and whether it was cancelled, nothing else.
 * One code per document: printing it again gives the same code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_checks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 14)->unique();
            $table->string('kind', 60);
            $table->string('reference', 40);
            $table->date('issued_on');
            $table->uuid('issued_by')->nullable();
            $table->timestampTz('created_at', 6);
            $table->date('cancelled_on')->nullable();
            $table->string('cancelled_by_reference', 40)->nullable();
            $table->unique(['kind', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_checks');
    }
};
