<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_articles', function (Blueprint $table): void {
            $table->foreignId('parent_id')
                ->nullable()
                ->after('category_id')
                ->constrained('knowledge_articles')
                ->nullOnDelete();
            $table->unsignedSmallInteger('depth')->default(0)->after('parent_id');
            $table->text('path')->nullable()->after('depth');
            $table->jsonb('audiences')->nullable()->after('tags');
            $table->jsonb('surfaces')->nullable()->after('audiences');
            $table->jsonb('module_slugs')->nullable()->after('surfaces');
            $table->jsonb('permission_keys')->nullable()->after('module_slugs');
            $table->jsonb('context_keys')->nullable()->after('permission_keys');
            $table->boolean('is_pinned')->default(false)->after('is_featured');
            $table->unsignedSmallInteger('help_priority')->default(100)->after('is_pinned');
            $table->text('content_plain_text')->nullable()->after('content');

            $table->index(['parent_id', 'sort_order']);
            $table->index(['is_pinned', 'help_priority']);
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_articles', function (Blueprint $table): void {
            $table->dropIndex(['parent_id', 'sort_order']);
            $table->dropIndex(['is_pinned', 'help_priority']);
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn([
                'depth',
                'path',
                'audiences',
                'surfaces',
                'module_slugs',
                'permission_keys',
                'context_keys',
                'is_pinned',
                'help_priority',
                'content_plain_text',
            ]);
        });
    }
};
