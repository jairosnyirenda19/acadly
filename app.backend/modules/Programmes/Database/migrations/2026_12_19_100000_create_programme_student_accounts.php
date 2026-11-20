<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-101. Student accounts: the phone a student's codes go to, and the
 * account they set up themselves. The account opens only the student portal.
 * Suspending stops sign-in; the account itself stays, with its history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programme_student_accounts', function (Blueprint $table): void {
            $table->uuid('learner_id')->primary();
            $table->uuid('division_id');
            $table->foreignUuid('user_id')->nullable()->unique()->constrained('users');
            $table->string('phone', 20)->nullable();
            $table->timestampTz('code_sent_at', 6)->nullable();
            $table->timestampTz('set_up_at', 6)->nullable();
            $table->timestampTz('suspended_at', 6)->nullable();
            $table->uuid('suspended_by')->nullable();
            $table->string('suspended_reason', 255)->nullable();
            $table->uuid('created_by');
            $table->timestampTz('created_at', 6);
            $table->index('division_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programme_student_accounts');
    }
};
