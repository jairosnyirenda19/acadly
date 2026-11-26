<?php

declare(strict_types=1);

use Acadly\Kernel\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-115. Notices to families: for the whole school, a division, a level or a
 * class. Families read them in the parent portal; a notice may also go by
 * text message, written as its own short version. A notice is withdrawn with
 * a reason, never deleted. Who read it is counted, never listed to families.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_notices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('audience', 10);
            $table->uuid('division_id')->nullable();
            $table->string('level_code', 16)->nullable();
            $table->uuid('class_id')->nullable();
            $table->string('title', 120);
            $table->text('body');
            $table->string('sms_text', 160)->nullable();
            $table->date('pinned_until')->nullable();
            $table->timestampTz('publish_at', 6);
            $table->uuid('author_id');
            $table->timestampTz('created_at', 6);
            $table->index('publish_at');
        });

        Schema::create('family_notice_withdrawals', function (Blueprint $table): void {
            $table->foreignUuid('notice_id')->primary()->constrained('family_notices');
            $table->string('reason', 255);
            $table->uuid('withdrawn_by');
            $table->timestampTz('created_at', 6);
        });

        Schema::create('family_notice_reads', function (Blueprint $table): void {
            $table->foreignUuid('notice_id')->constrained('family_notices');
            $table->uuid('user_id');
            $table->timestampTz('read_at', 6);
            $table->primary(['notice_id', 'user_id']);
        });

        foreach (['family_notices', 'family_notice_withdrawals'] as $table) {
            AppendOnly::protect($table);
        }
    }

    public function down(): void
    {
        foreach (['family_notice_reads', 'family_notice_withdrawals', 'family_notices'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
