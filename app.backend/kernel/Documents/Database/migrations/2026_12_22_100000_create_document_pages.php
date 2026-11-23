<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-103. The pages of a document version drawn as pictures, as eOffice
 * does: the console shows a document by its pictures, never by the file.
 * One render row a version says how it went ('ready', 'failed' or
 * 'unsupported' for a type that cannot be drawn here); each page is a blob,
 * encrypted like any file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_renders', function (Blueprint $table): void {
            $table->foreignUuid('file_id')->primary()->constrained('document_files');
            $table->string('status', 12);
            $table->unsignedSmallInteger('page_count');
            $table->string('producer', 40);
            $table->timestampTz('created_at', 6);
        });

        Schema::create('document_pages', function (Blueprint $table): void {
            $table->foreignUuid('file_id')->constrained('document_files');
            $table->unsignedSmallInteger('page');
            $table->char('blob_sha256', 64);
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->primary(['file_id', 'page']);
            $table->foreign('blob_sha256')->references('sha256')->on('blobs');
            $table->index('blob_sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_pages');
        Schema::dropIfExists('document_renders');
    }
};
