<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-090. A small picture of the first page of one version of a document,
 * kept as a blob like any file. It is made by the server from an image, or
 * from a picture of the first page that the browser drew from a PDF and the
 * server decoded and drew again. A version has at most one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_thumbnails', function (Blueprint $table): void {
            $table->foreignUuid('file_id')->primary()->constrained('document_files');
            $table->char('blob_sha256', 64);
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->string('source', 16);
            $table->timestampTz('created_at', 6);

            $table->foreign('blob_sha256')->references('sha256')->on('blobs');
            $table->index('blob_sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_thumbnails');
    }
};
