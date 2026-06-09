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
            ->assertJsonPath('data.table_of_contents.0.title', 'Профиль')
            ->assertJsonPath('data.related.0.slug', 'notifications-setup');
    }

    private function createSchema(): void
    {
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
            $table->string('kind');
            $table->string('status');
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('excerpt')->nullable();
            $table->longText('content')->nullable();
            $table->json('tags')->nullable();
            $table->string('release_version')->nullable();
            $table->date('release_date')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedSmallInteger('reading_time')->default(1);
            $table->boolean('is_featured')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }
}
