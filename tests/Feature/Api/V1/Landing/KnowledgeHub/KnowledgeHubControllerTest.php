<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing\KnowledgeHub;

use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeArticleKind;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeArticleStatus;
use App\BusinessModules\Features\KnowledgeHub\Models\KnowledgeArticle;
use App\BusinessModules\Features\KnowledgeHub\Models\KnowledgeCategory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class KnowledgeHubControllerTest extends TestCase
{
    public function refreshDatabase(): void
    {
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
        $this->createSchema();
    }

    public function test_overview_returns_published_categories_articles_and_changelog(): void
    {
        $guides = KnowledgeCategory::query()->create([
            'title' => 'Руководства',
            'slug' => 'guides',
            'description' => 'Пошаговые инструкции',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        KnowledgeArticle::query()->create([
            'category_id' => $guides->id,
            'kind' => KnowledgeArticleKind::GUIDE,
            'status' => KnowledgeArticleStatus::PUBLISHED,
            'title' => 'Как пригласить администратора',
            'slug' => 'invite-admin',
            'excerpt' => 'Настройка доступа команды',
            'content' => '<h2>Приглашение</h2><p>Откройте раздел администраторов.</p>',
            'tags' => ['доступы'],
            'published_at' => now()->subDay(),
            'reading_time' => 3,
            'is_featured' => true,
        ]);

        KnowledgeArticle::query()->create([
            'kind' => KnowledgeArticleKind::CHANGELOG,
            'status' => KnowledgeArticleStatus::PUBLISHED,
            'title' => 'Обновление личного кабинета за июнь',
            'slug' => 'lk-june-update',
            'excerpt' => 'Новые возможности кабинета',
            'content' => '<p>Добавлена база знаний.</p>',
            'release_version' => '2026.06',
            'release_date' => now()->toDateString(),
            'published_at' => now(),
            'reading_time' => 2,
        ]);

        KnowledgeArticle::query()->create([
            'category_id' => $guides->id,
            'kind' => KnowledgeArticleKind::ARTICLE,
            'status' => KnowledgeArticleStatus::DRAFT,
            'title' => 'Черновик',
            'slug' => 'draft',
            'content' => '<p>Не публиковать.</p>',
        ]);

        $response = $this->getJson('/api/v1/landing/knowledge-hub/overview');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.categories.0.slug', 'guides')
            ->assertJsonPath('data.featured_articles.0.slug', 'invite-admin')
            ->assertJsonPath('data.latest_changelog.0.slug', 'lk-june-update')
            ->assertJsonPath('data.summary.articles_count', 1)
            ->assertJsonMissing(['slug' => 'draft']);
    }

    public function test_articles_endpoint_searches_only_published_knowledge_articles(): void
    {
        $category = KnowledgeCategory::query()->create([
            'title' => 'Лучшие практики',
            'slug' => 'best-practices',
            'is_active' => true,
            'sort_order' => 20,
        ]);

        KnowledgeArticle::query()->create([
            'category_id' => $category->id,
            'kind' => KnowledgeArticleKind::BEST_PRACTICE,
            'status' => KnowledgeArticleStatus::PUBLISHED,
            'title' => 'Контроль лимитов бюджета',
            'slug' => 'budget-limits-control',
            'excerpt' => 'Как не превышать лимиты',
            'content' => '<p>Проверяйте лимиты перед оплатой.</p>',
            'tags' => ['бюджет'],
            'published_at' => now()->subHour(),
            'reading_time' => 4,
        ]);

        KnowledgeArticle::query()->create([
            'category_id' => $category->id,
            'kind' => KnowledgeArticleKind::CHANGELOG,
            'status' => KnowledgeArticleStatus::PUBLISHED,
            'title' => 'Бюджет в changelog',
            'slug' => 'budget-changelog',
            'content' => '<p>Не статья базы знаний.</p>',
            'published_at' => now()->subHour(),
        ]);

        KnowledgeArticle::query()->create([
            'category_id' => $category->id,
            'kind' => KnowledgeArticleKind::ARTICLE,
            'status' => KnowledgeArticleStatus::DRAFT,
            'title' => 'Бюджетный черновик',
            'slug' => 'budget-draft',
            'content' => '<p>Не публиковать.</p>',
        ]);

        $response = $this->getJson('/api/v1/landing/knowledge-hub/articles?q=бюджет&category=best-practices');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.slug', 'budget-limits-control')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonMissing(['slug' => 'budget-changelog'])
            ->assertJsonMissing(['slug' => 'budget-draft']);
    }

    public function test_article_detail_contains_related_items_and_table_of_contents(): void
    {
        $category = KnowledgeCategory::query()->create([
            'title' => 'Советы',
            'slug' => 'tips',
            'is_active' => true,
            'sort_order' => 30,
        ]);

        KnowledgeArticle::query()->create([
            'category_id' => $category->id,
            'kind' => KnowledgeArticleKind::TIP,
            'status' => KnowledgeArticleStatus::PUBLISHED,
            'title' => 'Быстрый старт',
            'slug' => 'quick-start',
            'excerpt' => 'Первый рабочий день в кабинете',
            'content' => '<h2>Профиль</h2><p>Заполните профиль.</p><h3>Команда</h3><p>Пригласите коллег.</p>',
            'published_at' => now()->subDays(2),
            'reading_time' => 2,
        ]);

        KnowledgeArticle::query()->create([
            'category_id' => $category->id,
            'kind' => KnowledgeArticleKind::TIP,
            'status' => KnowledgeArticleStatus::PUBLISHED,
            'title' => 'Настройка уведомлений',
            'slug' => 'notifications-setup',
            'content' => '<p>Выберите важные события.</p>',
            'published_at' => now()->subDay(),
            'reading_time' => 1,
        ]);

        $response = $this->getJson('/api/v1/landing/knowledge-hub/articles/quick-start');

        $response->assertOk()
            ->assertJsonPath('data.slug', 'quick-start')
            ->assertJsonStructure(['data' => ['plain_text']])
            ->assertJsonPath('data.table_of_contents.0.title', 'Профиль')
            ->assertJsonPath('data.related.0.slug', 'notifications-setup');
    }

    public function test_tree_endpoint_returns_nested_articles(): void
    {
        $category = KnowledgeCategory::query()->create([
            'title' => 'Заявки',
            'slug' => 'requests',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        KnowledgeArticle::query()->create([
            'category_id' => $category->id,
            'kind' => KnowledgeArticleKind::GUIDE,
            'status' => KnowledgeArticleStatus::PUBLISHED,
            'title' => '2. Заявки',
            'slug' => 'requests-root',
            'content' => '<p>Общий порядок.</p>',
            'published_at' => now(),
        ]);

        KnowledgeArticle::query()->create([
            'category_id' => $category->id,
            'parent_id' => KnowledgeArticle::query()->where('slug', 'requests-root')->value('id'),
            'kind' => KnowledgeArticleKind::ARTICLE,
            'status' => KnowledgeArticleStatus::PUBLISHED,
            'title' => '2.1. Создать заявку',
            'slug' => 'requests-create',
            'content' => '<p>Создание заявки.</p>',
            'published_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/landing/knowledge-hub/tree?category=requests');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.slug', 'requests-root')
            ->assertJsonPath('data.0.children.0.slug', 'requests-create');
    }

    public function test_search_endpoint_uses_articles_contract_and_records_event(): void
    {
        KnowledgeArticle::query()->create([
            'kind' => KnowledgeArticleKind::ARTICLE,
            'status' => KnowledgeArticleStatus::PUBLISHED,
            'title' => 'Полнотекстовый поиск по заявкам',
            'slug' => 'full-text-requests-search',
            'excerpt' => 'Материал помогает найти заявку по смыслу.',
            'content' => '<p>Поиск работает по названию, описанию и содержанию статьи.</p>',
            'published_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/landing/knowledge-hub/search?q=заявкам');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.slug', 'full-text-requests-search')
            ->assertJsonPath('meta.total', 1);

        $this->assertDatabaseHas('knowledge_search_events', [
            'surface' => 'lk',
            'query' => 'заявкам',
            'results_count' => 1,
        ]);
    }

    public function test_search_endpoint_respects_permission_targets(): void
    {
        KnowledgeArticle::query()->create([
            'kind' => KnowledgeArticleKind::ARTICLE,
            'status' => KnowledgeArticleStatus::PUBLISHED,
            'title' => 'Открытая справка по заявкам',
            'slug' => 'public-site-requests-help',
            'excerpt' => 'Открытая статья по заявкам.',
            'content' => '<p>Заявки доступны всем участникам контура.</p>',
            'surfaces' => ['lk'],
            'module_slugs' => ['site-requests'],
            'published_at' => now(),
        ]);

        KnowledgeArticle::query()->create([
            'kind' => KnowledgeArticleKind::ARTICLE,
            'status' => KnowledgeArticleStatus::PUBLISHED,
            'title' => 'Закрытая справка по заявкам',
            'slug' => 'restricted-site-requests-help',
            'excerpt' => 'Статья только для пользователей с правом управления заявками.',
            'content' => '<p>Материал содержит действия, доступные только по праву.</p>',
            'surfaces' => ['lk'],
            'module_slugs' => ['site-requests'],
            'permission_keys' => ['site_requests.manage'],
            'published_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/landing/knowledge-hub/search?q=заявкам&module_slug=site-requests');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.slug', 'public-site-requests-help')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonMissing(['slug' => 'restricted-site-requests-help']);
    }

    public function test_context_endpoint_respects_permission_targets(): void
    {
        KnowledgeArticle::query()->create([
            'kind' => KnowledgeArticleKind::GUIDE,
            'status' => KnowledgeArticleStatus::PUBLISHED,
            'title' => 'Открытая помощь по пользователям',
            'slug' => 'public-users-help',
            'content' => '<p>Базовые действия в разделе пользователей.</p>',
            'surfaces' => ['lk'],
            'module_slugs' => ['users'],
            'context_keys' => ['users.invite'],
            'help_priority' => 20,
            'published_at' => now(),
        ]);

        KnowledgeArticle::query()->create([
            'kind' => KnowledgeArticleKind::GUIDE,
            'status' => KnowledgeArticleStatus::PUBLISHED,
            'title' => 'Закрытая помощь по приглашениям',
            'slug' => 'restricted-users-invite-help',
            'content' => '<p>Инструкция для администраторов с правом приглашать пользователей.</p>',
            'surfaces' => ['lk'],
            'module_slugs' => ['users'],
            'permission_keys' => ['users.manage'],
            'context_keys' => ['users.invite'],
            'is_pinned' => true,
            'help_priority' => 1,
            'published_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/landing/knowledge-hub/context?module_slug=users&context_key=users.invite');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.primary.slug', 'public-users-help')
            ->assertJsonMissing(['slug' => 'restricted-users-invite-help']);
    }

    public function test_context_endpoint_prefers_exact_context_article_over_generic_pinned_article(): void
    {
        $category = KnowledgeCategory::query()->create([
            'title' => 'Requests',
            'slug' => 'requests-context',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        KnowledgeArticle::query()->create([
            'category_id' => $category->id,
            'kind' => KnowledgeArticleKind::ARTICLE,
            'status' => KnowledgeArticleStatus::PUBLISHED,
            'title' => 'Generic pinned help',
            'slug' => 'generic-pinned-help',
            'content' => '<p>Generic module help.</p>',
            'published_at' => now()->subDay(),
            'surfaces' => ['lk'],
            'audiences' => ['all'],
            'module_slugs' => [],
            'context_keys' => [],
            'is_pinned' => true,
            'help_priority' => 1,
        ]);

        KnowledgeArticle::query()->create([
            'category_id' => $category->id,
            'kind' => KnowledgeArticleKind::ARTICLE,
            'status' => KnowledgeArticleStatus::PUBLISHED,
            'title' => 'Exact site request help',
            'slug' => 'exact-site-request-help',
            'content' => '<p>Exact context help.</p>',
            'published_at' => now()->subDay(),
            'surfaces' => ['lk'],
            'audiences' => ['all'],
            'module_slugs' => ['site-requests'],
            'context_keys' => ['site_requests.index'],
            'is_pinned' => false,
            'help_priority' => 50,
        ]);

        $response = $this->getJson('/api/v1/landing/knowledge-hub/context?context_key=site_requests.index&module_slug=site-requests');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.primary.slug', 'exact-site-request-help');
    }

    public function test_feedback_endpoint_stores_article_reaction(): void
    {
        $article = KnowledgeArticle::query()->create([
            'kind' => KnowledgeArticleKind::ARTICLE,
            'status' => KnowledgeArticleStatus::PUBLISHED,
            'title' => 'Обратная связь по статье',
            'slug' => 'article-feedback',
            'content' => '<p>Статья для проверки.</p>',
            'published_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/landing/knowledge-hub/feedback', [
            'article_id' => $article->id,
            'reaction' => 'helpful',
            'comment' => 'Материал помог.',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('knowledge_article_feedback', [
            'article_id' => $article->id,
            'surface' => 'lk',
            'reaction' => 'helpful',
            'comment' => 'Материал помог.',
        ]);
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('knowledge_search_events');
        Schema::dropIfExists('knowledge_article_feedback');
        Schema::dropIfExists('knowledge_articles');
        Schema::dropIfExists('knowledge_categories');

        Schema::create('knowledge_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->string('color')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('knowledge_articles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->nullable();
            $table->foreignId('parent_id')->nullable();
            $table->unsignedSmallInteger('depth')->default(0);
            $table->string('path')->nullable();
            $table->string('kind');
            $table->string('status');
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('excerpt')->nullable();
            $table->longText('content')->nullable();
            $table->text('content_plain_text')->nullable();
            $table->json('tags')->nullable();
            $table->json('audiences')->nullable();
            $table->json('surfaces')->nullable();
            $table->json('module_slugs')->nullable();
            $table->json('permission_keys')->nullable();
            $table->json('context_keys')->nullable();
            $table->string('release_version')->nullable();
            $table->date('release_date')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedSmallInteger('reading_time')->default(1);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_pinned')->default(false);
            $table->unsignedSmallInteger('help_priority')->default(100);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('knowledge_article_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id');
            $table->foreignId('user_id')->nullable();
            $table->foreignId('organization_id')->nullable();
            $table->string('surface');
            $table->string('context_key')->nullable();
            $table->string('reaction');
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        Schema::create('knowledge_search_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->foreignId('organization_id')->nullable();
            $table->foreignId('clicked_article_id')->nullable();
            $table->string('surface');
            $table->string('query');
            $table->string('module_slug')->nullable();
            $table->string('context_key')->nullable();
            $table->unsignedInteger('results_count')->default(0);
            $table->timestamps();
        });
    }
}
