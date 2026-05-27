<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blog_articles', function (Blueprint $table): void {
            $table->string('canonical_url', 2048)->nullable()->after('excerpt');
            $table->text('editor_notes')->nullable()->after('canonical_url');
        });

        Schema::table('blog_article_revisions', function (Blueprint $table): void {
            $table->string('canonical_url', 2048)->nullable()->after('excerpt');
            $table->text('editor_notes')->nullable()->after('canonical_url');
        });
    }

    public function down(): void
    {
        Schema::table('blog_article_revisions', function (Blueprint $table): void {
            $table->dropColumn(['canonical_url', 'editor_notes']);
        });

        Schema::table('blog_articles', function (Blueprint $table): void {
            $table->dropColumn(['canonical_url', 'editor_notes']);
        });
    }
};
