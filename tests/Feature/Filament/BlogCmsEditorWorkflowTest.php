<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\Blog\BlogArticleStatusEnum;
use App\Enums\Blog\BlogContextEnum;
use App\Filament\Resources\BlogArticleResource;
use App\Models\Blog\BlogArticle;
use App\Models\Blog\BlogCategory;
use App\Models\Blog\BlogMediaAsset;
use App\Models\LandingAdmin;
use App\Models\SystemAdmin;
use App\Services\Blog\BlogCmsService;
use App\Services\Security\SystemAdminRoleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BlogCmsEditorWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(SystemAdminRoleService::class)->clearCache();
    }

    protected function tearDown(): void
    {
        Auth::guard('system_admin')->logout();
        app(SystemAdminRoleService::class)->clearCache();

        parent::tearDown();
    }

    public function test_article_resource_delegates_large_schemas_to_dedicated_classes(): void
    {
        $resourceSource = file_get_contents(app_path('Filament/Resources/BlogArticleResource.php'));

        $this->assertIsString($resourceSource);
        $this->assertStringContainsString('BlogArticleForm::configure($schema)', $resourceSource);
        $this->assertStringContainsString('BlogArticleTable::configure($table)', $resourceSource);
        $this->assertStringContainsString('BlogArticleInfolist::configure($schema)', $resourceSource);
        $this->assertFileExists(app_path('Filament/Resources/BlogArticleResource/Schemas/BlogArticleForm.php'));
        $this->assertFileExists(app_path('Filament/Resources/BlogArticleResource/Schemas/BlogArticleTable.php'));
        $this->assertFileExists(app_path('Filament/Resources/BlogArticleResource/Schemas/BlogArticleInfolist.php'));
        $this->assertFileExists(app_path('Filament/Resources/BlogArticleResource/Schemas/BlogEditorBlocks.php'));
        $this->assertFileExists(app_path('Filament/Resources/BlogArticleResource/Pages/ViewBlogArticle.php'));
    }

    public function test_content_manager_can_open_create_and_edit_article_editor(): void
    {
        $admin = SystemAdmin::factory()->role('content_manager')->create([
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'system_admin');

        $article = $this->articleFixture($admin);

        $this->get(BlogArticleResource::getUrl('create'))->assertSuccessful();
        $this->get(BlogArticleResource::getUrl('view', ['record' => $article]))->assertSuccessful();
        $this->get(BlogArticleResource::getUrl('edit', ['record' => $article]))->assertSuccessful();
    }

    public function test_content_manager_can_open_editorial_calendar_with_safe_bulk_operations(): void
    {
        $admin = SystemAdmin::factory()->role('content_manager')->create([
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'system_admin');

        $this->assertTrue(Route::has('filament.admin.resources.blog-articles.calendar'));
        $this->assertStringContainsString('/calendar', BlogArticleResource::getUrl('calendar'));

        $tableSource = (string) file_get_contents(app_path('Filament/Resources/BlogArticleResource/Schemas/BlogArticleTable.php'));

        $this->assertStringContainsString("BulkAction::make('assign_category')", $tableSource);
        $this->assertStringContainsString("BulkAction::make('assign_author')", $tableSource);
        $this->assertStringContainsString("BulkAction::make('move_scheduled_date')", $tableSource);
        $this->assertStringContainsString("BulkAction::make('archive_drafts')", $tableSource);
        $this->assertStringNotContainsString("BulkAction::make('publish", $tableSource);
        $this->assertStringNotContainsString('DeleteBulkAction', $tableSource);
    }

    public function test_cms_service_generates_slug_and_keeps_manual_slug_explicit(): void
    {
        $admin = SystemAdmin::factory()->role('content_manager')->create();
        $category = $this->categoryFixture('editorial-workflow');
        $landingAdmin = $this->landingAdminFixture($admin);

        $article = app(BlogCmsService::class)->createDraft([
            'author_id' => $landingAdmin->id,
            'category_id' => $category->id,
            'title' => 'Big ProHelper Release',
            'excerpt' => 'Короткое описание релиза.',
            'editor_document' => $this->documentFixture(),
            'featured_image' => 'https://cdn.example.test/blog/release.jpg',
            'meta_title' => 'Big ProHelper Release',
            'meta_description' => 'Что изменилось в ProHelper для команд.',
        ], $admin);

        $this->assertSame('big-prohelper-release', $article->slug);

        $updated = app(BlogCmsService::class)->updateArticle($article, [
            'title' => 'Новый заголовок без ручного slug',
        ], $admin);

        $this->assertSame('novyi-zagolovok-bez-rucnogo-slug', $updated->slug);

        $updated = app(BlogCmsService::class)->updateArticle($updated, [
            'slug' => 'custom-release-url',
        ], $admin);

        $this->assertSame('custom-release-url', $updated->slug);
    }

    public function test_cms_service_rejects_duplicate_manual_slug_invalid_canonical_url_and_past_schedule(): void
    {
        $admin = SystemAdmin::factory()->role('content_manager')->create();
        $category = $this->categoryFixture('validation');

        $existing = $this->articleFixture($admin, $category, 'existing-slug');

        try {
            app(BlogCmsService::class)->createDraft([
                'author_id' => $existing->author_id,
                'category_id' => $category->id,
                'title' => 'Duplicate slug article',
                'slug' => $existing->slug,
                'canonical_url' => 'not-a-url',
                'status' => BlogArticleStatusEnum::SCHEDULED->value,
                'scheduled_at' => CarbonImmutable::yesterday(),
                'editor_document' => $this->documentFixture(),
            ], $admin);

            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();

            $this->assertArrayHasKey('slug', $errors);
            $this->assertArrayHasKey('canonical_url', $errors);
            $this->assertArrayHasKey('scheduled_at', $errors);
        }
    }

    public function test_cms_service_status_transitions_cover_schedule_publish_archive_and_draft(): void
    {
        $admin = SystemAdmin::factory()->role('content_manager')->create();
        $category = $this->categoryFixture('status-transitions');
        $article = $this->articleFixture($admin, $category, 'status-transitions-article');

        $scheduledAt = CarbonImmutable::now()->addDays(2)->startOfMinute();
        $scheduled = app(BlogCmsService::class)->scheduleArticle($article, $admin, $scheduledAt->toDateTimeString());

        $this->assertSame(BlogArticleStatusEnum::SCHEDULED, $scheduled->status);
        $this->assertTrue($scheduled->scheduled_at?->equalTo($scheduledAt));

        $draft = app(BlogCmsService::class)->draftArticle($scheduled, $admin);

        $this->assertSame(BlogArticleStatusEnum::DRAFT, $draft->status);
        $this->assertNull($draft->scheduled_at);
        $this->assertNull($draft->published_at);

        $published = app(BlogCmsService::class)->publishArticle($draft, $admin);

        $this->assertSame(BlogArticleStatusEnum::PUBLISHED, $published->status);
        $this->assertNotNull($published->published_at);

        $archived = app(BlogCmsService::class)->archiveArticle($published, $admin);

        $this->assertSame(BlogArticleStatusEnum::ARCHIVED, $archived->status);
        $this->assertGreaterThanOrEqual(4, $archived->revisions()->count());
        $this->assertDatabaseHas('blog_article_revisions', [
            'article_id' => $archived->id,
            'revision_type' => 'schedule',
        ]);
        $this->assertDatabaseHas('blog_article_revisions', [
            'article_id' => $archived->id,
            'revision_type' => 'unpublish',
        ]);
        $this->assertDatabaseHas('blog_article_revisions', [
            'article_id' => $archived->id,
            'revision_type' => 'publish',
        ]);
        $this->assertDatabaseHas('blog_article_revisions', [
            'article_id' => $archived->id,
            'revision_type' => 'archive',
        ]);
    }

    public function test_cms_service_rejects_editorial_length_limits(): void
    {
        $admin = SystemAdmin::factory()->role('content_manager')->create();
        $category = $this->categoryFixture('editorial-limits');
        $landingAdmin = $this->landingAdminFixture($admin);

        try {
            app(BlogCmsService::class)->createDraft([
                'author_id' => $landingAdmin->id,
                'category_id' => $category->id,
                'title' => str_repeat('A', 256),
                'slug' => 'editorial-limits',
                'excerpt' => str_repeat('x', 501),
                'canonical_url' => 'ftp://example.test/article',
                'editor_document' => $this->documentFixture(),
            ], $admin);

            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();

            $this->assertArrayHasKey('title', $errors);
            $this->assertArrayHasKey('excerpt', $errors);
            $this->assertArrayHasKey('canonical_url', $errors);
        }
    }

    public function test_preview_action_is_permission_guarded_and_uses_signed_public_url(): void
    {
        $admin = SystemAdmin::factory()->role('content_manager')->create();
        $restrictedAdmin = SystemAdmin::factory()->role('unknown_role')->create();
        $article = $this->articleFixture($admin, slug: 'preview-article');

        $previewUrl = app(BlogCmsService::class)->makePreviewUrl($article);

        $this->assertTrue($admin->hasSystemPermission('system_admin.blog.preview.view'));
        $this->assertFalse($restrictedAdmin->hasSystemPermission('system_admin.blog.preview.view'));
        $this->assertStringStartsWith(rtrim((string) config('blog.marketing_frontend_url'), '/') . '/blog/preview/' . $article->id, $previewUrl);
        $this->assertStringContainsString('signature=', $previewUrl);
        $this->assertStringContainsString(
            'system_admin.blog.preview.view',
            (string) file_get_contents(app_path('Filament/Resources/BlogArticleResource/Pages/EditBlogArticle.php')),
        );
    }

    public function test_publish_is_blocked_by_editorial_checklist_but_draft_save_is_allowed(): void
    {
        $admin = SystemAdmin::factory()->role('content_manager')->create();
        $article = $this->articleFixture($admin, slug: 'checklist-blocked-article');
        $article->forceFill([
            'featured_image' => 'https://cdn.example.test/blog/missing-alt.jpg',
            'editor_document' => [
                [
                    'type' => 'paragraph',
                    'data' => ['content' => 'Short body'],
                ],
            ],
            'content' => '<p>Short body</p>',
        ])->save();

        $draft = app(BlogCmsService::class)->updateArticle($article, [
            'title' => 'Черновик можно сохранять до завершения чеклиста',
            'status' => BlogArticleStatusEnum::DRAFT->value,
        ], $admin);

        $this->assertSame(BlogArticleStatusEnum::DRAFT, $draft->status);

        try {
            app(BlogCmsService::class)->publishArticle($draft, $admin);

            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('editorial_checklist', $exception->errors());
        }
    }

    private function articleFixture(?SystemAdmin $admin = null, ?BlogCategory $category = null, ?string $slug = null): BlogArticle
    {
        $admin ??= SystemAdmin::factory()->role('content_manager')->create();
        $category ??= $this->categoryFixture('product-updates');
        $landingAdmin = $this->landingAdminFixture($admin);
        $coverUrl = 'https://cdn.example.test/blog/article-' . str()->random(8) . '.jpg';

        BlogMediaAsset::query()->create([
            'blog_context' => BlogContextEnum::MARKETING->value,
            'filename' => basename($coverUrl),
            'storage_path' => 'blog/' . basename($coverUrl),
            'public_url' => $coverUrl,
            'mime_type' => 'image/jpeg',
            'file_size' => 120000,
            'alt_text' => 'Скриншот редактора статьи ProHelper',
        ]);

        return BlogArticle::query()->create([
            'blog_context' => BlogContextEnum::MARKETING->value,
            'category_id' => $category->id,
            'author_id' => $landingAdmin->id,
            'author_system_admin_id' => $admin->id,
            'last_edited_by_system_admin_id' => $admin->id,
            'title' => 'How to manage projects',
            'slug' => $slug ?? 'how-to-manage-projects-' . $admin->id,
            'excerpt' => 'Short article summary',
            'content' => '<p>Article body</p>',
            'editor_document' => $this->documentFixture(),
            'editor_version' => 1,
            'featured_image' => $coverUrl,
            'meta_title' => 'How to manage projects',
            'meta_description' => 'Short article summary for publishing.',
            'status' => BlogArticleStatusEnum::DRAFT->value,
            'is_featured' => false,
            'allow_comments' => true,
            'is_published_in_rss' => true,
            'noindex' => false,
            'sort_order' => 0,
        ]);
    }

    private function landingAdminFixture(SystemAdmin $admin): LandingAdmin
    {
        return LandingAdmin::query()->create([
            'name' => 'Editor',
            'email' => 'editor-' . $admin->id . '@example.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
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

    /**
     * @return array<int, array{type: string, data: array<string, string>}>
     */
    private function documentFixture(): array
    {
        return [
            [
                'type' => 'heading',
                'data' => ['content' => 'Практический сценарий', 'level' => 2],
            ],
            [
                'type' => 'paragraph',
                'data' => ['content' => str_repeat('Редактор проверяет структуру, пользу, SEO и визуальный контекст статьи. ', 12)],
            ],
        ];
    }
}
