<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\Blog\BlogArticleStatusEnum;
use App\Enums\Blog\BlogContextEnum;
use App\Models\Activity\ActivityEvent;
use App\Models\Blog\BlogArticle;
use App\Models\Blog\BlogCategory;
use App\Models\Blog\BlogMediaAsset;
use App\Models\LandingAdmin;
use App\Models\SystemAdmin;
use App\Services\Blog\BlogMediaService;
use App\Services\Security\SystemAdminRoleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BlogMediaLibraryTest extends TestCase
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

    public function test_media_resource_declares_upload_guardrails_and_safe_replace_action(): void
    {
        $resourceSource = (string) file_get_contents(app_path('Filament/Resources/BlogMediaAssetResource.php'));

        $this->assertStringContainsString('acceptedFileTypes(BlogMediaService::allowedMimeTypes())', $resourceSource);
        $this->assertStringContainsString('maxSize(BlogMediaService::maxUploadSizeKilobytes())', $resourceSource);
        $this->assertStringContainsString('imageEditor()', $resourceSource);
        $this->assertStringContainsString("TextInput::make('alt_text')", $resourceSource);
        $this->assertStringContainsString("Action::make('safe_replace')", $resourceSource);
        $this->assertStringContainsString('replaceWithUploadedFile', $resourceSource);
    }

    public function test_usage_metadata_finds_references_and_delete_is_blocked_when_asset_is_used(): void
    {
        $admin = SystemAdmin::factory()->role('content_manager')->create();
        $asset = $this->mediaFixture('used-cover.jpg');
        $article = $this->articleFixture($admin, BlogArticleStatusEnum::PUBLISHED, [
            'featured_image' => $asset->public_url,
            'og_image' => $asset->public_url,
            'gallery_images' => [$asset->public_url],
            'editor_document' => [
                [
                    'type' => 'image',
                    'data' => ['url' => $asset->public_url, 'alt' => 'Скриншот'],
                ],
            ],
        ]);

        $usage = app(BlogMediaService::class)->refreshUsageMetadata($asset);

        $this->assertNotEmpty($usage);
        $this->assertSame(count($usage), $asset->fresh()->usage_metadata['count']);
        $this->assertContains($article->id, collect($usage)->pluck('article_id')->all());

        $this->expectException(ValidationException::class);

        app(BlogMediaService::class)->deleteAsset($asset);
    }

    public function test_safe_replace_updates_draft_references_and_records_audit_event(): void
    {
        $admin = SystemAdmin::factory()->role('content_manager')->create();
        $oldAsset = $this->mediaFixture('old-cover.jpg');
        $newAsset = $this->mediaFixture('new-cover.jpg');
        $draft = $this->articleFixture($admin, BlogArticleStatusEnum::DRAFT, [
            'featured_image' => $oldAsset->public_url,
            'og_image' => $oldAsset->public_url,
            'gallery_images' => [$oldAsset->public_url],
            'editor_document' => [
                [
                    'type' => 'image',
                    'data' => ['url' => $oldAsset->public_url, 'alt' => 'Старая обложка'],
                ],
            ],
        ]);

        $updatedCount = app(BlogMediaService::class)->replaceDraftReferences($oldAsset, $newAsset, $admin);
        $draft->refresh();

        $this->assertSame(1, $updatedCount);
        $this->assertSame($newAsset->public_url, $draft->featured_image);
        $this->assertSame($newAsset->public_url, $draft->og_image);
        $this->assertSame([$newAsset->public_url], $draft->gallery_images);
        $this->assertSame($newAsset->public_url, $draft->editor_document[0]['data']['url']);
        $this->assertDatabaseHas(ActivityEvent::class, [
            'event_type' => 'system_admin.blog_media.replaced',
            'subject_type' => BlogMediaAsset::class,
            'subject_id' => $oldAsset->id,
        ]);
    }

    public function test_safe_replace_refuses_to_touch_published_articles(): void
    {
        $admin = SystemAdmin::factory()->role('content_manager')->create();
        $oldAsset = $this->mediaFixture('published-cover.jpg');
        $newAsset = $this->mediaFixture('replacement-cover.jpg');
        $published = $this->articleFixture($admin, BlogArticleStatusEnum::PUBLISHED, [
            'featured_image' => $oldAsset->public_url,
        ]);

        try {
            app(BlogMediaService::class)->replaceDraftReferences($oldAsset, $newAsset, $admin);

            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('media_asset', $exception->errors());
        }

        $this->assertSame($oldAsset->public_url, $published->fresh()->featured_image);
    }

    private function mediaFixture(string $filename): BlogMediaAsset
    {
        return BlogMediaAsset::query()->create([
            'blog_context' => BlogContextEnum::MARKETING->value,
            'filename' => $filename,
            'storage_path' => 'cms/blog/media/' . $filename,
            'public_url' => 'https://cdn.example.test/blog/' . $filename,
            'mime_type' => 'image/jpeg',
            'file_size' => 120000,
            'width' => 1200,
            'height' => 800,
            'alt_text' => 'Обложка статьи',
            'usage_metadata' => [],
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function articleFixture(SystemAdmin $admin, BlogArticleStatusEnum $status, array $overrides): BlogArticle
    {
        $category = BlogCategory::query()->firstOrCreate(
            ['slug' => 'media-library'],
            [
                'blog_context' => BlogContextEnum::MARKETING->value,
                'name' => 'Media Library',
                'is_active' => true,
            ],
        );
        $landingAdmin = LandingAdmin::query()->create([
            'name' => 'Media Editor',
            'email' => 'media-editor-' . $admin->id . '-' . str()->random(8) . '@example.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        return BlogArticle::query()->create(array_merge([
            'blog_context' => BlogContextEnum::MARKETING->value,
            'category_id' => $category->id,
            'author_id' => $landingAdmin->id,
            'author_system_admin_id' => $admin->id,
            'last_edited_by_system_admin_id' => $admin->id,
            'title' => 'Media library article ' . str()->random(8),
            'slug' => 'media-library-article-' . str()->random(8),
            'excerpt' => 'Article summary',
            'content' => '<p>Article body</p>',
            'editor_document' => [],
            'editor_version' => 1,
            'status' => $status->value,
            'published_at' => $status === BlogArticleStatusEnum::PUBLISHED ? now() : null,
            'is_featured' => false,
            'allow_comments' => true,
            'is_published_in_rss' => true,
            'noindex' => false,
            'sort_order' => 0,
        ], $overrides));
    }
}
