<?php

declare(strict_types=1);

use Acadly\Kernel\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-102. Lectures: rooms, the campus areas a scan must come from, each
 * semester's lecture timetable, what happened to a lecture on one day
 * (cannot come, asked, covered, cancelled, moved: each a new row), and
 * lecture attendance: a session per lecture held, its marks (each a new row;
 * the latest counts), the scans the checks refused, and each student's
 * attendance phone (a passkey's public key; never a secret).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lecture_rooms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('division_id');
            $table->string('code', 16);
            $table->string('name', 120);
            $table->unsignedSmallInteger('seats');
            // 'hall', 'room', 'laboratory' or 'seminar'.
            $table->string('kind', 12);
            $table->timestampTz('retired_at', 6)->nullable();
            $table->timestampsTz(6);
            $table->unique(['division_id', 'code']);
        });

        Schema::create('lecture_areas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('division_id');
            $table->string('name', 80);
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->unsignedSmallInteger('reach');
            $table->timestampTz('retired_at', 6)->nullable();
            $table->timestampsTz(6);
            $table->index('division_id');
        });

        Schema::create('lecture_settings', function (Blueprint $table): void {
            $table->uuid('division_id')->primary();
            $table->unsignedSmallInteger('scan_minutes');
            $table->uuid('updated_by');
            $table->timestampTz('updated_at', 6);
        });

        Schema::create('lecture_timetables', function (Blueprint $table): void {
            $table->uuid('period_id')->primary();
            $table->uuid('division_id');
            $table->timestampTz('published_at', 6);
            $table->uuid('published_by');
        });

        Schema::create('lecture_slots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('division_id');
            $table->uuid('period_id');
            $table->uuid('offering_id');
            // 'lecture', 'tutorial' or 'practical'.
            $table->string('kind', 10);
            $table->unsignedSmallInteger('weekday');
            $table->string('starts', 5);
            $table->string('ends', 5);
            $table->foreignUuid('room_id')->constrained('lecture_rooms');
            $table->uuid('lecturer_id');
            $table->timestampTz('retired_at', 6)->nullable();
            $table->timestampsTz(6);
            $table->index(['period_id', 'weekday']);
        });

        Schema::create('lecture_changes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('division_id');
            $table->foreignUuid('slot_id')->constrained('lecture_slots');
            $table->date('day');
            // 'away', 'ask', 'accept', 'decline', 'cover', 'cancel' or 'move'.
            $table->string('kind', 8);
            $table->uuid('user_id')->nullable();
            $table->string('reason', 255)->nullable();
            $table->date('moved_day')->nullable();
            $table->string('moved_starts', 5)->nullable();
            $table->string('moved_ends', 5)->nullable();
            $table->uuid('moved_room_id')->nullable();
            $table->uuid('by_user_id');
            $table->timestampTz('created_at', 6);
            $table->index(['slot_id', 'day']);
            $table->index(['division_id', 'day']);
        });

        Schema::create('lecture_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('division_id');
            $table->foreignUuid('slot_id')->constrained('lecture_slots');
            // The day of the timetable; a moved lecture keeps it, and is held on held_on.
            $table->date('day');
            $table->date('held_on');
            $table->string('ends', 5);
            $table->uuid('offering_id');
            $table->uuid('opened_by');
            $table->timestampTz('opened_at', 6);
            $table->timestampTz('scan_until', 6);
            $table->string('secret', 64);
            $table->timestampTz('closed_at', 6)->nullable();
            $table->uuid('closed_by')->nullable();
            $table->unique(['slot_id', 'day']);
            $table->index(['division_id', 'held_on']);
        });

        Schema::create('lecture_marks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('lecture_sessions');
            $table->uuid('learner_id');
            // 'present', 'late' or 'absent'.
            $table->string('mark', 8);
            // 'scan', 'hand' or 'close'.
            $table->string('source', 6);
            // 'inside' or 'outside' the campus area, for a scan; null when no area is set.
            $table->string('location', 8)->nullable();
            $table->string('reason', 255)->nullable();
            $table->uuid('by_user_id')->nullable();
            $table->timestampTz('created_at', 6);
            $table->index(['session_id', 'learner_id']);
            $table->index('learner_id');
        });

        Schema::create('lecture_refusals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('lecture_sessions');
            $table->uuid('learner_id');
            // 'code', 'closed', 'outside', 'phone' or 'location'.
            $table->string('why', 10);
            $table->timestampTz('created_at', 6);
            $table->index('session_id');
        });

        Schema::create('lecture_phones', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('division_id');
            $table->uuid('learner_id');
            $table->uuid('user_id');
            $table->string('credential_id', 512)->unique();
            $table->text('public_key');
            $table->unsignedBigInteger('sign_count');
            $table->timestampTz('created_at', 6);
            $table->timestampTz('reset_at', 6)->nullable();
            $table->uuid('reset_by')->nullable();
            $table->string('reset_reason', 255)->nullable();
        });
        foreach (['lecture_changes', 'lecture_marks', 'lecture_refusals'] as $table) {
            AppendOnly::protect($table);
        }
        DB::statement('create unique index lecture_phones_one_in_use on lecture_phones (learner_id) where reset_at is null');
    }

    public function down(): void
    {
        foreach (['lecture_phones', 'lecture_refusals', 'lecture_marks', 'lecture_sessions', 'lecture_changes', 'lecture_slots', 'lecture_timetables',
            'lecture_settings', 'lecture_areas', 'lecture_rooms'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
