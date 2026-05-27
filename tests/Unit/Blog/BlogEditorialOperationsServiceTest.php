<?php

declare(strict_types=1);

namespace Tests\Unit\Blog;

use App\Enums\Blog\BlogArticleStatusEnum;
use App\Enums\Blog\BlogContextEnum;
use App\Enums\Blog\BlogRevisionTypeEnum;
use App\Models\Blog\BlogArticle;
use App\Models\Blog\BlogCategory;
use App\Models\LandingAdmin;
use App\Models\SystemAdmin;
use App\Services\Blog\BlogEditorialOperationsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BlogEditorialOperationsServiceTest extends TestCase
{
    private SystemAdmin $systemAdmin;

    private SystemAdmin $newAuthor;

    private LandingAdmin $landingAdmin;

    private BlogCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->systemAdmin = SystemAdmin::factory()->role('content_manager')->create([
            'is_active' => true,
        ]);
        $this->newAuthor = SystemAdmin::factory()->role('content_manager')->create([
            'is_active' => true,
        ]);
        $this->landingAdmin = LandingAdmin::query()->create([
            'name' => 'Content Editor',
            'email' => 'bulk-content-editor-' . $this->systemAdmin->id . '@example.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);
        $this->category = $this->categoryFixture('initial-category');
    }

    public function test_it_assigns_category_and_author_with_revision_snapshots(): void
    {
        $article = $this->articleFixture('bulk-category-author');
        $newCategory = $this->categoryFixture('new-category');

        $categoryCount = app(BlogEditorialOperationsService::class)->assignCategory(
            collect([$article]),
            $newCategory->id,
            $this->systemAdmin,
        );
        $authorCount = app(BlogEditorialOperationsService::class)->assignSystemAuthor(
            collect([$article->fresh()]),
            $this->newAuthor->id,
            $this->systemAdmin,
        );

        $article->refresh();

        $this->assertSame(1, $categoryCount);
        $this->assertSame(1, $authorCount);
        $this->assertSame($newCategory->id, $article->category_id);
        $this->assertSame($this->newAuthor->id, $article->author_system_admin_id);
        $this->assertSame($this->systemAdmin->id, $article->last_edited_by_system_admin_id);
        $this->assertSame(
            2,
            $article->revisions()->where('revision_type', BlogRevisionTypeEnum::MANUAL->value)->count(),
        );
    }

    public function test_it_moves_only_scheduled_articles_and_archives_only_drafts(): void
    {
        $scheduledAt = CarbonImmutable::now()->addDays(5)->startOfMinute();
        $newScheduledAt = CarbonImmutable::now()->addDays(8)->startOfMinute();
        $scheduled = $this->articleFixture('bulk-scheduled', BlogArticleStatusEnum::SCHEDULED, $scheduledAt);
        $draft = $this->articleFixture('bulk-draft');
        $published = $this->articleFixture('bulk-published', BlogArticleStatusEnum::PUBLISHED, now());

        $movedCount = app(BlogEditorialOperationsService::class)->moveScheduledDate(
            collect([$scheduled]),
            $newScheduledAt,
            $this->systemAdmin,
        );
        $archivedCount = app(BlogEditorialOperationsService::class)->archiveDrafts(
            collect([$draft]),
            $this->systemAdmin,
        );

        $this->assertSame(1, $movedCount);
        $this->assertSame(1, $archivedCount);
        $this->assertTrue($scheduled->fresh()->scheduled_at?->equalTo($newScheduledAt));
        $this->assertSame(BlogArticleStatusEnum::ARCHIVED, $draft->fresh()->status);
        $this->assertDatabaseHas('blog_article_revisions', [
            'article_id' => $scheduled->id,
            'revision_type' => BlogRevisionTypeEnum::SCHEDULE->value,
        ]);
        $this->assertDatabaseHas('blog_article_revisions', [
            'article_id' => $draft->id,
            'revision_type' => BlogRevisionTypeEnum::ARCHIVE->value,
        ]);

        try {
            app(BlogEditorialOperationsService::class)->moveScheduledDate(
                collect([$published]),
                $newScheduledAt,
                $this->systemAdmin,
            );

            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('articles', $exception->errors());
        }

        try {
            app(BlogEditorialOperationsService::class)->archiveDrafts(
                collect([$scheduled->fresh()]),
                $this->systemAdmin,
            );

            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('articles', $exception->errors());
        }
    }

    private function articleFixture(
        string $slug,
        BlogArticleStatusEnum $status = BlogArticleStatusEnum::DRAFT,
        mixed $date = null,
    ): BlogArticle {
        return BlogArticle::query()->create([
            'blog_context' => BlogContextEnum::MARKETING->value,
            'category_id' => $this->category->id,
            'author_id' => $this->landingAdmin->id,
            'author_system_admin_id' => $this->systemAdmin->id,
            'last_edited_by_system_admin_id' => $this->systemAdmin->id,
            'title' => 'Bulk article',
            'slug' => $slug,
            'excerpt' => 'Bulk article excerpt',
            'content' => '<p>Bulk article body</p>',
            'editor_document' => [
                [
                    'type' => 'paragraph',
                    'data' => ['content' => 'Bulk article body'],
                ],
            ],
            'editor_version' => 1,
            'featured_image' => 'https://cdn.example.test/blog/bulk.jpg',
            'meta_title' => 'Bulk article',
            'meta_description' => 'Bulk article description',
            'status' => $status->value,
            'published_at' => $status === BlogArticleStatusEnum::PUBLISHED ? $date : null,
            'scheduled_at' => $status === BlogArticleStatusEnum::SCHEDULED ? $date : null,
            'is_featured' => false,
            'allow_comments' => true,
            'is_published_in_rss' => true,
            'noindex' => false,
            'sort_order' => 0,
        ]);
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
}
