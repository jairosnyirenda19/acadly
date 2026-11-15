<?php

declare(strict_types=1);

use Acadly\Kernel\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-053. Every document in the school is a row in one table, with exactly
 * one owner record. Every uploaded file is a row in one table, as one
 * version of one document.
 *
 * - blobs: the stored bytes, once per content, encrypted. Internal to the
 *   kernel's blob store; no module reads it.
 * - documents: kind, owner, title, classification, current version, trash and hold.
 * - document_files: one row per version. Append-only.
 * - document_links: other records a document concerns. A link never widens access.
 * - system_flags: small named values the kernel keeps, such as the storage warning level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blobs', function (Blueprint $table): void {
            $table->char('sha256', 64)->primary();
            $table->string('storage_path', 160);
            $table->unsignedBigInteger('byte_size');
            $table->unsignedBigInteger('stored_size');
            $table->string('mime_type', 120);
            $table->unsignedInteger('chunk_size');
            $table->text('wrapped_key');
            $table->string('wrap_nonce', 32);
            $table->string('key_id', 32);
            $table->timestampTz('created_at', 6);
            $table->timestampTz('released_at', 6)->nullable();
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('kind', 120);
            $table->string('owner_type', 60);
            $table->string('owner_id', 64);
            $table->string('title');
            $table->string('classification', 16);
            $table->uuid('current_file_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz(6);
            $table->timestampTz('trashed_at', 6)->nullable();
            $table->uuid('trashed_by')->nullable();
            $table->uuid('trash_batch')->nullable();
            $table->text('trash_reason')->nullable();
            $table->timestampTz('held_at', 6)->nullable();
            $table->uuid('held_by')->nullable();
            $table->text('hold_reason')->nullable();
            $table->timestampTz('purged_at', 6)->nullable();

            $table->index(['owner_type', 'owner_id']);
            $table->index('kind');
            $table->index('trash_batch');
        });

        Schema::create('document_files', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('documents');
            $table->unsignedInteger('version_no');
            $table->char('blob_sha256', 64);
            $table->string('original_name');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('byte_size');
            $table->text('note')->nullable();
            $table->uuid('uploaded_by')->nullable();
            $table->timestampTz('uploaded_at', 6);

            $table->foreign('blob_sha256')->references('sha256')->on('blobs');
            $table->unique(['document_id', 'version_no']);
            $table->index('blob_sha256');
        });

        Schema::create('document_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('documents');
            $table->string('record_type', 60);
            $table->string('record_id', 64);
            $table->uuid('linked_by')->nullable();
            $table->timestampTz('linked_at', 6);
            $table->timestampTz('unlinked_at', 6)->nullable();
            $table->uuid('unlinked_by')->nullable();

            $table->index(['record_type', 'record_id']);
        });

        DB::statement('CREATE UNIQUE INDEX document_links_one_active ON document_links (document_id, record_type, record_id) WHERE unlinked_at IS NULL');

        Schema::create('system_flags', function (Blueprint $table): void {
            $table->string('name', 60)->primary();
            $table->string('value', 255);
            $table->timestampTz('updated_at', 6);
        });

        AppendOnly::protect('document_files');
    }

    public function down(): void
    {
        Schema::dropIfExists('system_flags');
        Schema::dropIfExists('document_links');
        Schema::dropIfExists('document_files');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('blobs');
    }
};
