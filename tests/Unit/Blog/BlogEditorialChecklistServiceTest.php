<?php

declare(strict_types=1);

namespace Tests\Unit\Blog;

use App\Enums\Blog\BlogArticleStatusEnum;
use App\Enums\Blog\BlogContextEnum;
use App\Models\Blog\BlogArticle;
use App\Models\Blog\BlogCategory;
use App\Models\Blog\BlogMediaAsset;
use App\Models\LandingAdmin;
use App\Models\SystemAdmin;
use App\Services\Blog\BlogEditorialChecklistService;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BlogEditorialChecklistServiceTest extends TestCase
{
    public function test_it_reports_blocking_editorial_gaps(): void
    {
        $admin = SystemAdmin::factory()->role('content_manager')->create();
        $category = $this->categoryFixture('checklist-gaps');
        $media = $this->mediaFixture('cover-without-alt.jpg', '');
        $article = $this->articleFixture($admin, $category, $media->public_url, [
            [
                'type' => 'paragraph',
                'data' => ['content' => 'Short body'],
            ],
        ]);

        $result = app(BlogEditorialChecklistService::class)->evaluate($article);

        $this->assertFalse($result['can_publish']);
        $this->assertChecklistFailed($result, 'cover_alt');
        $this->assertChecklistFailed($result, 'body_minimum_text');
        $this->assertChecklistFailed($result, 'body_headings');
    }

    public function test_it_allows_publish_ready_article(): void
    {
        $admin = SystemAdmin::factory()->role('content_manager')->create();
        $category = $this->categoryFixture('checklist-ready');
        $media = $this->mediaFixture('cover-ready.jpg', 'Интерфейс МОСТ на экране ноутбука');
        $article = $this->articleFixture($admin, $category, $media->public_url, $this->readyDocument(), [
            'canonical_url' => 'https://1мост.рф/blog/checklist-ready',
            'scheduled_at' => now()->addDay(),
            'status' => BlogArticleStatusEnum::SCHEDULED->value,
        ]);

        $result = app(BlogEditorialChecklistService::class)->evaluate($article);

        $this->assertTrue($result['can_publish']);
        $this->assertSame($result['total'], $result['passed']);
    }

    public function test_it_detects_duplicate_article_slug(): void
    {
        $admin = SystemAdmin::factory()->role('content_manager')->create();
        $category = $this->categoryFixture('checklist-slugs');
        $media = $this->mediaFixture('cover-slug.jpg', 'Обложка статьи');
        $this->articleFixture($admin, $category, $media->public_url, $this->readyDocument(), ['slug' => 'shared-slug']);
        $article = $this->articleFixture($admin, $category, $media->public_url, $this->readyDocument(), ['slug' => 'shared-slug-copy']);
        $article->forceFill(['slug' => 'shared-slug']);

        $result = app(BlogEditorialChecklistService::class)->evaluate($article);

        $this->assertFalse($result['can_publish']);
        $this->assertChecklistFailed($result, 'slug_unique');
    }

    /**
     * @param array<string, mixed> $overrides
     * @param array<int, array{type: string, data: array<string, mixed>}> $document
     */
    private function articleFixture(SystemAdmin $admin, BlogCategory $category, string $coverUrl, array $document, array $overrides = []): BlogArticle
    {
        $landingAdmin = LandingAdmin::query()->create([
            'name' => 'Blog Editor',
            'email' => 'blog-editor-' . $admin->id . '-' . str()->random(8) . '@example.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        return BlogArticle::query()->create(array_merge([
            'blog_context' => BlogContextEnum::MARKETING->value,
            'category_id' => $category->id,
            'author_id' => $landingAdmin->id,
            'author_system_admin_id' => $admin->id,
            'last_edited_by_system_admin_id' => $admin->id,
            'title' => 'Как редактору подготовить статью для публикации',
            'slug' => 'editorial-checklist-' . str()->random(8),
            'excerpt' => 'Понятное резюме статьи для списка, SEO и предпросмотра.',
            'content' => '<p>Article body</p>',
            'editor_document' => $document,
            'editor_version' => 1,
            'featured_image' => $coverUrl,
            'meta_title' => 'Как редактору подготовить статью',
            'meta_description' => 'Подробный чеклист подготовки статьи перед публикацией.',
            'status' => BlogArticleStatusEnum::DRAFT->value,
            'is_featured' => false,
            'allow_comments' => true,
            'is_published_in_rss' => true,
            'noindex' => false,
            'sort_order' => 0,
        ], $overrides));
    }

    private function categoryFixture(string $slug): BlogCategory
    {
        return BlogCategory::query()->create([
            'blog_context' => BlogContextEnum::MARKETING->value,
            'name' => str($slug)->replace('-', ' ')->title()->toString(),
            'slug' => $slug,
            'is_active' => true,
        ]);
    }

    private function mediaFixture(string $filename, string $altText): BlogMediaAsset
    {
        return BlogMediaAsset::query()->create([
            'blog_context' => BlogContextEnum::MARKETING->value,
            'filename' => $filename,
            'storage_path' => 'blog/' . $filename,
            'public_url' => 'https://cdn.example.test/blog/' . $filename,
            'mime_type' => 'image/jpeg',
            'file_size' => 120000,
            'alt_text' => $altText,
        ]);
    }

    /**
     * @return array<int, array{type: string, data: array<string, mixed>}>
     */
    private function readyDocument(): array
    {
        return [
            [
                'type' => 'heading',
                'data' => ['content' => 'Почему это важно', 'level' => 2],
            ],
            [
                'type' => 'paragraph',
                'data' => ['content' => str_repeat('Редактор проверяет структуру, пользу, SEO и визуальный контекст статьи. ', 12)],
            ],
        ];
    }

    /**
     * @param array{items: array<int, array{key: string, passed: bool}>} $result
     */
    private function assertChecklistFailed(array $result, string $key): void
    {
        $item = collect($result['items'])->firstWhere('key', $key);

        $this->assertIsArray($item);
        $this->assertFalse($item['passed']);
    }
}
