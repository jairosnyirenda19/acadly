<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-101. News. A change after publishing is a new version that supersedes
 * the last; the first stays. Withdrawing hides the news and keeps it. Reads
 * are counted per post, never listed by name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_posts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('root_id');
            $table->unsignedSmallInteger('version');
            $table->uuid('division_id');
            $table->string('title', 160);
            $table->text('body');
            $table->string('audience', 16);
            $table->uuid('audience_id')->nullable();
            $table->unsignedSmallInteger('year_of_study')->nullable();
            $table->date('pinned_until')->nullable();
            $table->timestampTz('publish_at', 6);
            $table->uuid('author_id');
            $table->timestampTz('created_at', 6);
            $table->timestampTz('withdrawn_at', 6)->nullable();
            $table->uuid('withdrawn_by')->nullable();
            $table->string('withdrawn_reason', 255)->nullable();
            $table->unique(['root_id', 'version']);
            $table->index(['division_id', 'publish_at']);
        });

        Schema::create('news_reads', function (Blueprint $table): void {
            $table->uuid('root_id');
            $table->uuid('user_id');
            $table->timestampTz('read_at', 6);
            $table->primary(['root_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_reads');
        Schema::dropIfExists('news_posts');
    }
};
