<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Each contact chooses how the school sends notices: by SMS (the default) or by email. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learner_contacts', function (Blueprint $table): void {
            $table->string('notices_by', 8)->default('sms');
        });
    }

    public function down(): void
    {
        Schema::table('learner_contacts', function (Blueprint $table): void {
            $table->dropColumn('notices_by');
        });
    }
};
