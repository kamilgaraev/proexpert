<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blog_article_revisions', function (Blueprint $table): void {
            $table->foreignId('author_id')
                ->nullable()
                ->after('structured_data')
                ->constrained('landing_admins')
                ->nullOnDelete();
            $table->foreignId('author_system_admin_id')
                ->nullable()
                ->after('author_id')
                ->constrained('system_admins')
                ->nullOnDelete();
            $table->jsonb('author_snapshot')->nullable()->after('author_system_admin_id');
            $table->string('body_hash', 64)->nullable()->after('content_html');
        });
    }

    public function down(): void
    {
        Schema::table('blog_article_revisions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('author_system_admin_id');
            $table->dropConstrainedForeignId('author_id');
            $table->dropColumn(['author_snapshot', 'body_hash']);
        });
    }
};
