<?php

declare(strict_types=1);

use Acadly\Kernel\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-106. A course a student carries into a later year after the board's
 * progression decision. It joins the student's next registration that
 * offers it, as Repeat, and stays a row: append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programme_carried_courses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('division_id');
            $table->uuid('learner_id');
            $table->foreignUuid('course_id')->constrained('programme_courses');
            $table->string('reason', 255);
            $table->uuid('created_by');
            $table->timestampTz('created_at', 6);
            $table->index('learner_id');
        });

        AppendOnly::protect('programme_carried_courses');
    }

    public function down(): void
    {
        Schema::dropIfExists('programme_carried_courses');
    }
};
