<?php

declare(strict_types=1);

use Acadly\Kernel\Blueprint\Journey\JourneyColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parents and guardians, and their links to learners (03 §4, D-082).
 *
 * - guardians: one row per phone number that signed in to the parent
 *   portal. The role record of a parent: what the person is here.
 * - learner_guardian_relationships: one parent's link to one learner. It
 *   travels the `guardian.link` journey: asked for when the parent's number
 *   is a contact of the learner, active only when the parent confirms it.
 *   Access is never taken from the phone number alone. `contact_id` is the
 *   contact row that matched. `held_by` is the parent's account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guardians', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('phone', 20)->unique();
            $table->string('name', 120);
            $table->uuid('user_id')->nullable()->unique();
            $table->timestampTz('created_at', 6);
        });

        Schema::create('learner_guardian_relationships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('guardian_id')->constrained('guardians');
            $table->foreignUuid('learner_id')->constrained('learners');
            $table->foreignUuid('contact_id')->constrained('learner_contacts');
            $table->uuid('held_by');
            $table->string('state', 20);
            $table->string('journey_ref', 60);
            $table->timestampsTz(6);

            $table->index(['learner_id', 'state']);
        });

        DB::statement("create unique index learner_guardian_one_open on learner_guardian_relationships (guardian_id, learner_id) where state <> 'revoked'");

        JourneyColumns::protect('learner_guardian_relationships');
    }

    public function down(): void
    {
        Schema::dropIfExists('learner_guardian_relationships');
        Schema::dropIfExists('guardians');
    }
};
