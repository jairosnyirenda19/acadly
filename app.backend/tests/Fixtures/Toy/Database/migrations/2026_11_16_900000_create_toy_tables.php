<?php

declare(strict_types=1);

use Acadly\Kernel\Blueprint\Journey\JourneyColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tables of the toy module, migrated only in the test database. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('toy_errands', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->string('state', 60);
            $table->string('journey_ref', 64);
            $table->uuid('requested_by')->nullable();
            $table->string('requester_phone', 32)->nullable();
            $table->timestampsTz(6);
        });

        Schema::create('toy_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('errand_id');
            $table->string('outcome', 60);
            $table->text('reason')->nullable();
            $table->timestampTz('created_at', 6);
        });

        JourneyColumns::protect('toy_errands');
    }

    public function down(): void
    {
        Schema::dropIfExists('toy_decisions');
        Schema::dropIfExists('toy_errands');
    }
};
