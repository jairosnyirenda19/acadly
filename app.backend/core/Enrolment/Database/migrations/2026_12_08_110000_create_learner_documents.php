<?php

declare(strict_types=1);

use Acadly\Kernel\Support\Ids;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-090. The school's own list of the documents it keeps on a learner, and
 * which type each kept document is. The document and its files are the
 * kernel's (D-053); this says only which type it is and the date it bears.
 *
 * A type says who may open its documents: staff who see the learner, the
 * office only, or confidential. A type in use is retired, never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learner_document_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 80);
            $table->string('access', 16);
            $table->boolean('expected')->default(false);
            $table->unsignedInteger('position');
            $table->timestampTz('retired_at', 6)->nullable();
            $table->timestampsTz(6);
        });

        DB::statement('CREATE UNIQUE INDEX learner_document_types_one_name ON learner_document_types (lower(name)) WHERE retired_at IS NULL');

        Schema::create('learner_documents', function (Blueprint $table): void {
            $table->foreignUuid('document_id')->primary()->constrained('documents');
            $table->foreignUuid('learner_id')->constrained('learners');
            $table->foreignUuid('type_id')->constrained('learner_document_types');
            $table->date('document_date')->nullable();
            $table->timestampTz('created_at', 6);

            $table->index(['learner_id', 'type_id']);
        });

        // The ready list a school starts from. It changes them in Settings.
        $now = now();
        $ready = [
            ['Birth certificate', 'office', true],
            ['Transfer letter', 'office', false],
            ['Immunisation card', 'office', false],
            ['Previous school reports', 'staff', false],
            ['Consent forms', 'staff', false],
            ['Medical', 'confidential', false],
            ['Safeguarding', 'confidential', false],
            ['Special needs assessment', 'confidential', false],
            ['Other', 'office', false],
        ];

        foreach ($ready as $i => [$name, $access, $expected]) {
            DB::table('learner_document_types')->insert(['id' => Ids::next(), 'name' => $name, 'access' => $access, 'expected' => $expected,
                'position' => $i + 1, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('learner_documents');
        Schema::dropIfExists('learner_document_types');
    }
};
