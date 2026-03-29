<?php

declare(strict_types=1);

use App\Enums\Blog\BlogContextEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blog_categories', function (Blueprint $table): void {
            $table->string('blog_context', 32)
                ->default(BlogContextEnum::MARKETING->value)
                ->after('organization_group_id');
            $table->index(['blog_context', 'organization_group_id'], 'blog_categories_context_group_index');
        });

        Schema::table('blog_tags', function (Blueprint $table): void {
            $table->string('blog_context', 32)
                ->default(BlogContextEnum::MARKETING->value)
                ->after('organization_group_id');
            $table->index(['blog_context', 'organization_group_id'], 'blog_tags_context_group_index');
        });

        Schema::table('blog_articles', function (Blueprint $table): void {
            $table->string('blog_context', 32)
                ->default(BlogContextEnum::MARKETING->value)
                ->after('organization_group_id');
            $table->jsonb('editor_document')->nullable()->after('content');
            $table->unsignedInteger('editor_version')->default(1)->after('editor_document');
            $table->foreignId('author_system_admin_id')->nullable()->after('author_id')->constrained('system_admins')->nullOnDelete();
            $table->foreignId('last_edited_by_system_admin_id')->nullable()->after('updated_by_user_id')->constrained('system_admins')->nullOnDelete();
            $table->timestamp('last_autosaved_at')->nullable()->after('updated_at');
            $table->index(['blog_context', 'status', 'published_at'], 'blog_articles_context_status_index');
            $table->index(['blog_context', 'slug'], 'blog_articles_context_slug_index');
        });

        Schema::table('blog_comments', function (Blueprint $table): void {
            $table->string('blog_context', 32)
                ->default(BlogContextEnum::MARKETING->value)
                ->after('article_id');
            $table->foreignId('approved_by_system_admin_id')->nullable()->after('approved_by')->constrained('system_admins')->nullOnDelete();
            $table->index(['blog_context', 'status'], 'blog_comments_context_status_index');
        });

        Schema::table('blog_seo_settings', function (Blueprint $table): void {
            $table->string('blog_context', 32)
                ->default(BlogContextEnum::MARKETING->value)
                ->after('id');
            $table->unique('blog_context', 'blog_seo_settings_context_unique');
        });

        Schema::create('blog_article_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained('blog_articles')->cascadeOnDelete();
            $table->string('blog_context', 32)->default(BlogContextEnum::MARKETING->value);
            $table->string('revision_type', 32);
            $table->unsignedInteger('editor_version')->default(1);
            $table->string('title');
            $table->string('slug');
            $table->text('excerpt')->nullable();
            $table->longText('content_html');
            $table->jsonb('editor_document')->nullable();
            $table->string('featured_image')->nullable();
            $table->jsonb('gallery_images')->nullable();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->jsonb('meta_keywords')->nullable();
            $table->text('og_title')->nullable();
            $table->text('og_description')->nullable();
            $table->string('og_image')->nullable();
            $table->jsonb('structured_data')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->jsonb('category_snapshot')->nullable();
            $table->jsonb('tag_ids')->nullable();
            $table->jsonb('tags_snapshot')->nullable();
            $table->string('status', 32);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean('allow_comments')->default(true);
            $table->boolean('is_published_in_rss')->default(true);
            $table->boolean('noindex')->default(false);
            $table->integer('sort_order')->default(0);
            $table->foreignId('created_by_system_admin_id')->nullable()->constrained('system_admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['article_id', 'created_at'], 'blog_article_revisions_article_created_index');
            $table->index(['blog_context', 'revision_type'], 'blog_article_revisions_context_type_index');
        });

        Schema::create('blog_media_assets', function (Blueprint $table): void {
            $table->id();
            $table->string('blog_context', 32)->default(BlogContextEnum::MARKETING->value);
            $table->string('filename');
            $table->string('storage_path', 2048);
            $table->string('public_url', 2048);
            $table->string('mime_type', 255);
            $table->unsignedBigInteger('file_size')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('alt_text')->nullable();
            $table->string('caption')->nullable();
            $table->jsonb('focal_point')->nullable();
            $table->jsonb('usage_metadata')->nullable();
            $table->foreignId('uploaded_by_system_admin_id')->nullable()->constrained('system_admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['blog_context', 'created_at'], 'blog_media_assets_context_created_index');
        });

        DB::table('blog_categories')
            ->whereNotNull('organization_group_id')
            ->update(['blog_context' => BlogContextEnum::HOLDING->value]);

        DB::table('blog_tags')
            ->whereNotNull('organization_group_id')
            ->update(['blog_context' => BlogContextEnum::HOLDING->value]);

        DB::table('blog_articles')
            ->whereNotNull('organization_group_id')
            ->update(['blog_context' => BlogContextEnum::HOLDING->value]);

        DB::statement("
            UPDATE blog_comments
            SET blog_context = blog_articles.blog_context
            FROM blog_articles
            WHERE blog_comments.article_id = blog_articles.id
        ");

        DB::table('blog_seo_settings')->update(['blog_context' => BlogContextEnum::MARKETING->value]);
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_media_assets');
        Schema::dropIfExists('blog_article_revisions');

        Schema::table('blog_seo_settings', function (Blueprint $table): void {
            $table->dropUnique('blog_seo_settings_context_unique');
            $table->dropColumn('blog_context');
        });

        Schema::table('blog_comments', function (Blueprint $table): void {
            $table->dropIndex('blog_comments_context_status_index');
            $table->dropConstrainedForeignId('approved_by_system_admin_id');
            $table->dropColumn('blog_context');
        });

        Schema::table('blog_articles', function (Blueprint $table): void {
            $table->dropIndex('blog_articles_context_status_index');
            $table->dropIndex('blog_articles_context_slug_index');
            $table->dropConstrainedForeignId('last_edited_by_system_admin_id');
            $table->dropConstrainedForeignId('author_system_admin_id');
            $table->dropColumn([
                'blog_context',
                'editor_document',
                'editor_version',
                'last_autosaved_at',
            ]);
        });

        Schema::table('blog_tags', function (Blueprint $table): void {
            $table->dropIndex('blog_tags_context_group_index');
            $table->dropColumn('blog_context');
        });

        Schema::table('blog_categories', function (Blueprint $table): void {
            $table->dropIndex('blog_categories_context_group_index');
            $table->dropColumn('blog_context');
        });
    }
};
