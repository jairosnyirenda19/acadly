<?php

declare(strict_types=1);

use Acadly\Kernel\Database\AppendOnly;
use Acadly\Kernel\Support\Ids;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-092. Conduct: incidents and commendations. No points and no scores:
 * an entry has a kind the school sets and, for an incident, a level.
 * Entries are facts and never change; a mistake is withdrawn with a reason.
 * A serious incident is a case that travels `conduct.serious_incident`;
 * each learner's outcome is a row of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conduct_kinds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 80);
            $table->boolean('commendation');
            $table->string('level', 12)->nullable();
            // 'always' or 'ticked': when the family is told by default.
            $table->string('tell', 8);
            $table->unsignedInteger('position');
            $table->timestampTz('retired_at', 6)->nullable();
            $table->timestampsTz(6);
        });

        Schema::create('conduct_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('kind_id');
            $table->string('kind_name', 80);
            $table->boolean('commendation');
            $table->string('level', 12)->nullable();
            $table->date('happened_on');
            $table->string('place', 120)->nullable();
            $table->text('what');
            $table->text('action')->nullable();
            $table->boolean('told_family');
            $table->uuid('recorded_by');
            $table->timestampTz('created_at', 6);
            $table->index('happened_on');
        });

        Schema::create('conduct_entry_learners', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('entry_id')->constrained('conduct_entries');
            $table->uuid('learner_id');
            $table->uuid('class_id');
            $table->unique(['entry_id', 'learner_id']);
            $table->index('learner_id');
        });

        Schema::create('conduct_withdrawals', function (Blueprint $table): void {
            $table->foreignUuid('entry_id')->primary()->constrained('conduct_entries');
            $table->text('reason');
            $table->uuid('withdrawn_by');
            $table->timestampTz('created_at', 6);
        });

        // A serious incident: its state changes only through the journey engine.
        Schema::create('conduct_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('number', 24)->unique();
            $table->foreignUuid('entry_id')->unique()->constrained('conduct_entries');
            $table->uuid('class_id');
            $table->uuid('lead_by')->nullable();
            $table->string('state', 20);
            $table->string('journey_ref', 80);
            $table->timestampsTz(6);
            $table->index('state');
        });

        Schema::create('conduct_case_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('case_id')->constrained('conduct_cases');
            $table->text('text');
            $table->uuid('written_by')->nullable();
            $table->timestampTz('created_at', 6);
        });

        Schema::create('conduct_outcomes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('case_id')->constrained('conduct_cases');
            $table->uuid('learner_id');
            $table->string('outcome', 16);
            $table->date('starts_on')->nullable();
            $table->unsignedSmallInteger('days')->nullable();
            $table->text('reason');
            $table->uuid('decided_by');
            $table->timestampTz('created_at', 6);
            $table->index(['case_id', 'learner_id']);
        });

        Schema::create('conduct_sequences', function (Blueprint $table): void {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('next_number');
        });

        // The rules for "learners to watch". One row.
        Schema::create('conduct_watch', function (Blueprint $table): void {
            $table->unsignedSmallInteger('id')->primary();
            $table->unsignedSmallInteger('incidents');
            $table->unsignedSmallInteger('days');
            $table->boolean('open_serious');
            $table->timestampTz('updated_at', 6);
        });

        foreach (['conduct_entries', 'conduct_entry_learners', 'conduct_withdrawals', 'conduct_case_notes', 'conduct_outcomes'] as $table) {
            AppendOnly::protect($table);
        }

        $now = now();
        DB::table('conduct_watch')->insert(['id' => 1, 'incidents' => 3, 'days' => 30, 'open_serious' => true, 'updated_at' => $now]);

        // The kinds a school starts from. It changes them in Settings.
        $ready = [
            ['Helping others', true, null, 'always'], ['Effort in class', true, null, 'always'], ['Leadership', true, null, 'always'],
            ['Late to class', false, 'minor', 'ticked'], ['Uniform', false, 'minor', 'ticked'], ['Homework not done', false, 'minor', 'ticked'],
            ['Disrupting a lesson', false, 'moderate', 'ticked'], ['Rudeness', false, 'moderate', 'ticked'],
            ['Fighting', false, 'serious', 'always'], ['Bullying', false, 'serious', 'always'], ['Damage to property', false, 'serious', 'always'],
        ];

        foreach ($ready as $i => [$name, $commendation, $level, $tell]) {
            DB::table('conduct_kinds')->insert(['id' => Ids::next(), 'name' => $name, 'commendation' => $commendation, 'level' => $level, 'tell' => $tell,
                'position' => $i + 1, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        foreach (['conduct_watch', 'conduct_sequences', 'conduct_outcomes', 'conduct_case_notes', 'conduct_cases', 'conduct_withdrawals', 'conduct_entry_learners', 'conduct_entries', 'conduct_kinds'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
