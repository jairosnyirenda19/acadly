<?php

declare(strict_types=1);

use Acadly\Kernel\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - system_licences: every licence installed in the deployment, as signed.
 *   The newest one that verifies is in force. Append-only.
 * - module_switches: every time the school switched a module on or off, with
 *   who and why. The newest row per module is in force. Append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_licences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('serial', 120)->nullable();
            $table->text('document');
            $table->timestampTz('installed_at', 6);
        });

        Schema::create('module_switches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('module_key', 60);
            $table->boolean('enabled');
            $table->text('reason');
            $table->uuid('switched_by')->nullable();
            $table->timestampTz('switched_at', 6);

            $table->index(['module_key', 'switched_at']);
        });

        AppendOnly::protect('system_licences');
        AppendOnly::protect('module_switches');
    }

    public function down(): void
    {
        Schema::dropIfExists('module_switches');
        Schema::dropIfExists('system_licences');
    }
};
