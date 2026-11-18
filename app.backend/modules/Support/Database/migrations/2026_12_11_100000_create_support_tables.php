<?php

declare(strict_types=1);

use Acadly\Kernel\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-093. Support: the school's values for the signal rules, signals set
 * aside with a reason, and support plans. A plan travels `support.plan`;
 * its goals, actions and reviews are added, never changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The school's value for each rule a module declares, and the plan defaults.
        Schema::create('support_rules', function (Blueprint $table): void {
            $table->string('key', 60)->primary();
            $table->integer('value');
            $table->uuid('set_by')->nullable();
            $table->timestampTz('updated_at', 6);
        });

        Schema::create('support_set_asides', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->date('until');
            $table->text('reason');
            // How many signals there were: more, and the learner is back on the list.
            $table->unsignedSmallInteger('signals');
            $table->uuid('set_by');
            $table->timestampTz('created_at', 6);
            $table->index(['learner_id', 'until']);
        });

        Schema::create('support_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('learner_id');
            $table->uuid('class_id');
            $table->string('title', 120);
            $table->text('concern');
            $table->text('signals')->nullable();
            $table->uuid('lead_by');
            $table->boolean('confidential');
            $table->boolean('tell_family');
            $table->unsignedSmallInteger('review_days');
            $table->date('next_review_on');
            $table->string('state', 20);
            $table->string('journey_ref', 80);
            $table->string('outcome', 20)->nullable();
            $table->text('closing_note')->nullable();
            $table->uuid('opened_by');
            $table->timestampsTz(6);
            $table->index(['learner_id', 'state']);
        });

        Schema::create('support_goals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained('support_plans');
            $table->string('text', 255);
            $table->string('measure', 255)->nullable();
            $table->string('by_when', 60)->nullable();
            $table->uuid('added_by');
            $table->timestampTz('created_at', 6);
        });

        Schema::create('support_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained('support_plans');
            $table->string('who', 120);
            $table->string('what', 255);
            $table->string('when', 60)->nullable();
            $table->uuid('added_by');
            $table->timestampTz('created_at', 6);
        });

        Schema::create('support_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained('support_plans');
            $table->text('notes')->nullable();
            $table->date('next_review_on')->nullable();
            $table->uuid('reviewed_by');
            $table->timestampTz('created_at', 6);
        });

        Schema::create('support_review_goals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('review_id')->constrained('support_reviews');
            $table->foreignUuid('goal_id')->constrained('support_goals');
            $table->string('state', 10);
            $table->unique(['review_id', 'goal_id']);
        });

        foreach (['support_set_asides', 'support_goals', 'support_actions', 'support_reviews', 'support_review_goals'] as $table) {
            AppendOnly::protect($table);
        }

        $now = now();
        DB::table('support_rules')->insert(['key' => 'plan.review_days', 'value' => 14, 'updated_at' => $now]);
        DB::table('support_rules')->insert(['key' => 'plan.tell_family', 'value' => 1, 'updated_at' => $now]);
    }

    public function down(): void
    {
        foreach (['support_review_goals', 'support_reviews', 'support_actions', 'support_goals', 'support_plans', 'support_set_asides', 'support_rules'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
