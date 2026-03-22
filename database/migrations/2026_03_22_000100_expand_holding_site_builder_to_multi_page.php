<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holding_sites', function (Blueprint $table) {
            $table->string('default_locale', 12)->default('ru')->after('domain');
            $table->jsonb('enabled_locales')->nullable()->after('default_locale');
        });

        Schema::create('holding_site_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holding_site_id')->constrained('holding_sites')->onDelete('cascade');
            $table->string('page_type', 64)->default('custom');
            $table->string('slug', 255)->default('/');
            $table->string('navigation_label')->nullable();
            $table->string('title')->default('');
            $table->text('description')->nullable();
            $table->jsonb('seo_meta')->nullable();
            $table->jsonb('layout_config')->nullable();
            $table->jsonb('locale_content')->nullable();
            $table->string('visibility', 32)->default('public');
            $table->unsignedInteger('sort_order')->default(1);
            $table->boolean('is_home')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->constrained('users')->onDelete('restrict');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->index(['holding_site_id', 'sort_order']);
            $table->index(['holding_site_id', 'is_home'], 'holding_site_pages_site_home_index');
            $table->unique(['holding_site_id', 'slug'], 'holding_site_pages_site_slug_unique');
        });

        Schema::table('site_content_blocks', function (Blueprint $table) {
            $table->foreignId('holding_site_page_id')->nullable()->after('holding_site_id')->constrained('holding_site_pages')->nullOnDelete();
            $table->jsonb('locale_content')->nullable()->after('bindings');
            $table->jsonb('style_config')->nullable()->after('locale_content');
            $table->index(['holding_site_page_id', 'sort_order'], 'site_content_blocks_page_sort_index');
        });

        Schema::create('holding_site_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holding_site_id')->constrained('holding_sites')->onDelete('cascade');
            $table->string('kind', 32)->default('published');
            $table->string('label')->nullable();
            $table->jsonb('payload');
            $table->foreignId('created_by_user_id')->constrained('users')->onDelete('restrict');
            $table->timestamps();

            $table->index(['holding_site_id', 'kind', 'created_at']);
        });

        Schema::create('holding_site_collaborators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holding_site_id')->constrained('holding_sites')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('role', 32);
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->unique(['holding_site_id', 'user_id'], 'holding_site_collaborators_site_user_unique');
            $table->index(['holding_site_id', 'role']);
        });

        Schema::table('holding_site_leads', function (Blueprint $table) {
            $table->foreignId('holding_site_page_id')->nullable()->after('holding_site_id')->constrained('holding_site_pages')->nullOnDelete();
            $table->string('section_key')->nullable()->after('block_key');
            $table->string('locale_code', 12)->nullable()->after('section_key');
            $table->index(['holding_site_id', 'holding_site_page_id'], 'holding_site_leads_site_page_index');
        });

        Schema::table('blog_categories', function (Blueprint $table) {
            $table->foreignId('organization_group_id')->nullable()->after('id')->constrained('organization_groups')->nullOnDelete();
            $table->index(['organization_group_id', 'is_active'], 'blog_categories_group_active_index');
        });

        Schema::table('blog_tags', function (Blueprint $table) {
            $table->foreignId('organization_group_id')->nullable()->after('id')->constrained('organization_groups')->nullOnDelete();
            $table->index(['organization_group_id'], 'blog_tags_group_index');
        });

        Schema::table('blog_articles', function (Blueprint $table) {
            $table->foreignId('organization_group_id')->nullable()->after('id')->constrained('organization_groups')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->after('author_id')->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->after('created_by_user_id')->constrained('users')->nullOnDelete();
            $table->index(['organization_group_id', 'status', 'published_at'], 'blog_articles_group_status_index');
        });

        DB::statement('ALTER TABLE blog_articles ALTER COLUMN author_id DROP NOT NULL');

        $sites = DB::table('holding_sites')
            ->select(['id', 'title', 'description', 'created_by_user_id', 'updated_by_user_id', 'created_at', 'updated_at'])
            ->get();

        foreach ($sites as $site) {
            $pageId = DB::table('holding_site_pages')->insertGetId([
                'holding_site_id' => $site->id,
                'page_type' => 'home',
                'slug' => '/',
                'navigation_label' => 'Главная',
                'title' => $site->title ?? '',
                'description' => $site->description,
                'seo_meta' => json_encode([]),
                'layout_config' => json_encode(['variant' => 'default']),
                'locale_content' => json_encode([
                    'ru' => [
                        'title' => $site->title ?? '',
                        'description' => $site->description,
                    ],
                ]),
                'visibility' => 'public',
                'sort_order' => 1,
                'is_home' => true,
                'is_active' => true,
                'created_by_user_id' => $site->created_by_user_id,
                'updated_by_user_id' => $site->updated_by_user_id,
                'created_at' => $site->created_at,
                'updated_at' => $site->updated_at,
            ]);

            DB::table('site_content_blocks')
                ->where('holding_site_id', $site->id)
                ->whereNull('holding_site_page_id')
                ->update([
                    'holding_site_page_id' => $pageId,
                    'style_config' => json_encode(['spacing' => 'default']),
                ]);

            DB::table('holding_sites')
                ->where('id', $site->id)
                ->update([
                    'enabled_locales' => json_encode(['ru']),
                ]);

            if ($site->created_by_user_id) {
                DB::table('holding_site_collaborators')->updateOrInsert(
                    [
                        'holding_site_id' => $site->id,
                        'user_id' => $site->created_by_user_id,
                    ],
                    [
                        'role' => 'owner',
                        'invited_by_user_id' => $site->created_by_user_id,
                        'created_at' => $site->created_at,
                        'updated_at' => $site->updated_at,
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE blog_articles ALTER COLUMN author_id SET NOT NULL');

        Schema::table('blog_articles', function (Blueprint $table) {
            $table->dropIndex('blog_articles_group_status_index');
            $table->dropConstrainedForeignId('updated_by_user_id');
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropConstrainedForeignId('organization_group_id');
        });

        Schema::table('blog_tags', function (Blueprint $table) {
            $table->dropIndex('blog_tags_group_index');
            $table->dropConstrainedForeignId('organization_group_id');
        });

        Schema::table('blog_categories', function (Blueprint $table) {
            $table->dropIndex('blog_categories_group_active_index');
            $table->dropConstrainedForeignId('organization_group_id');
        });

        Schema::table('holding_site_leads', function (Blueprint $table) {
            $table->dropIndex('holding_site_leads_site_page_index');
            $table->dropColumn(['locale_code', 'section_key']);
            $table->dropConstrainedForeignId('holding_site_page_id');
        });

        Schema::dropIfExists('holding_site_collaborators');
        Schema::dropIfExists('holding_site_revisions');

        Schema::table('site_content_blocks', function (Blueprint $table) {
            $table->dropIndex('site_content_blocks_page_sort_index');
            $table->dropColumn(['style_config', 'locale_content']);
            $table->dropConstrainedForeignId('holding_site_page_id');
        });

        Schema::dropIfExists('holding_site_pages');

        Schema::table('holding_sites', function (Blueprint $table) {
            $table->dropColumn(['enabled_locales', 'default_locale']);
        });
    }
};
