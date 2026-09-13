<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\Blog\BlogArticleStatusEnum;
use App\Enums\Blog\BlogContextEnum;
use App\Filament\Resources\BlogMediaAssetResource;
use App\Models\Activity\ActivityEvent;
use App\Models\Blog\BlogArticle;
use App\Models\Blog\BlogCategory;
use App\Models\Blog\BlogMediaAsset;
use App\Models\LandingAdmin;
use App\Models\SystemAdmin;
use App\Services\Blog\BlogMediaService;
use App\Services\Blog\BlogArticleMaterialsService;
use App\Services\Blog\BlogDocumentRenderer;
use App\Services\Storage\FileService;
use App\Models\Organization;
use Illuminate\Http\UploadedFile;
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

    public function test_temporary_upload_uses_signed_local_endpoint_when_permanent_storage_is_s3(): void
    {
        config(['filesystems.default' => 's3']);
        \Illuminate\Support\Facades\Storage::fake('tmp-for-tests');
        $this->assertFalse(\Livewire\Features\SupportFileUploads\FileUploadConfiguration::isUsingS3());
        $file = UploadedFile::fake()->createWithContent('template.pdf', "%PDF-1.4\nTemplate");
        $this->post(route('livewire.upload-file'), ['files' => [$file]])->assertStatus(401);
        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute('livewire.upload-file', now()->addMinutes(5));
        $response = $this->post($url, ['files' => [$file]])->assertOk();
        $reference = $response->json('paths.0');
        $this->assertIsString($reference);
        $path = \Livewire\Features\SupportFileUploads\TemporaryUploadedFile::extractPathFromSignedPath($reference);
        $this->assertIsString($path);
        $temporary = \Livewire\Features\SupportFileUploads\TemporaryUploadedFile::createFromLivewire($path);
        $this->assertSame('template.pdf', $temporary->getClientOriginalName());
        $this->assertSame("%PDF-1.4\nTemplate", $temporary->getContent());
        $temporary->delete();
        $this->assertFalse($temporary->exists());
    }

    public function test_media_resource_declares_upload_guardrails_and_safe_replace_action(): void
    {
        $resourceSource = (string) file_get_contents(app_path('Filament/Resources/BlogMediaAssetResource.php'));

        $this->assertStringContainsString('BlogMediaService::allowedMimeTypes()', $resourceSource);
        $this->assertStringContainsString('BlogMediaService::allowedImageMimeTypes()', $resourceSource);
        $this->assertStringContainsString('maxSize(BlogMediaService::maxUploadSizeKilobytes())', $resourceSource);
        $this->assertStringContainsString('imageEditor()', $resourceSource);
        $this->assertStringContainsString("TextInput::make('alt_text')", $resourceSource);
        $this->assertStringContainsString("Action::make('safe_replace')", $resourceSource);
        $this->assertStringContainsString('replaceWithUploadedFile', $resourceSource);
    }

    public function test_media_resource_uses_business_friendly_russian_page_labels(): void
    {
        $resourceSource = (string) file_get_contents(app_path('Filament/Resources/BlogMediaAssetResource.php'));
        $listPageSource = (string) file_get_contents(app_path('Filament/Resources/BlogMediaAssetResource/Pages/ListBlogMediaAssets.php'));
        $createPageSource = (string) file_get_contents(app_path('Filament/Resources/BlogMediaAssetResource/Pages/CreateBlogMediaAsset.php'));
        $editPageSource = (string) file_get_contents(app_path('Filament/Resources/BlogMediaAssetResource/Pages/EditBlogMediaAsset.php'));

        $this->assertSame('Медиатека', BlogMediaAssetResource::getNavigationLabel());
        $this->assertSame('медиафайл', BlogMediaAssetResource::getModelLabel());
        $this->assertSame('Медиафайлы', BlogMediaAssetResource::getPluralModelLabel());
        $this->assertSame('Медиафайлы', BlogMediaAssetResource::getBreadcrumb());
        $this->assertFalse(BlogMediaAssetResource::hasTitleCaseModelLabel());

        foreach ([
            "trans_message('blog_cms.media_navigation_label')",
            "trans_message('blog_cms.media_model_label')",
            "trans_message('blog_cms.media_plural_model_label')",
            "trans_message('blog_cms.media_form_section_file')",
            "trans_message('blog_cms.media_field_file')",
            "trans_message('blog_cms.media_field_caption')",
            "trans_message('blog_cms.media_field_preview')",
            "trans_message('blog_cms.media_field_size')",
            "trans_message('blog_cms.media_field_usage_count')",
            "trans_message('blog_cms.media_field_updated_at')",
        ] as $translationCall) {
            $this->assertStringContainsString($translationCall, $resourceSource);
        }

        $this->assertStringContainsString("trans_message('blog_cms.media_list_title')", $listPageSource);
        $this->assertStringContainsString("trans_message('blog_cms.media_create_title')", $createPageSource);
        $this->assertStringContainsString("trans_message('blog_cms.media_create_breadcrumb')", $createPageSource);
        $this->assertStringContainsString("trans_message('blog_cms.media_edit_title')", $editPageSource);
        $this->assertStringNotContainsString('Blog media file is required.', $createPageSource);
    }

    public function test_article_editor_can_upload_media_without_leaving_article_form(): void
    {
        $formSource = (string) file_get_contents(app_path('Filament/Resources/BlogArticleResource/Schemas/BlogArticleForm.php'));
        $mediaResourceSource = (string) file_get_contents(app_path('Filament/Resources/BlogMediaAssetResource.php'));
        $editPageSource = (string) file_get_contents(app_path('Filament/Resources/BlogArticleResource/Pages/EditBlogArticle.php'));
        $componentSource = (string) file_get_contents(app_path('Filament/Forms/Components/BlogInlineBlockEditor.php'));
        $viewSource = (string) file_get_contents(resource_path('views/filament/forms/components/blog-inline-block-editor.blade.php'));
        $scriptSource = (string) file_get_contents(resource_path('js/filament/blog-inline-block-editor.js'));
        $formAndMediaSource = $formSource . "\n" . $mediaResourceSource;

        foreach ([
            'createOptionForm(BlogMediaAssetResource::uploadFormSchema(imagesOnly: true))',
            'createOptionUsing(fn (array $data): string => self::createMarketingImageOption($data))',
            'BlogMediaService::allowedImageMimeTypes()',
            'storeFiles(false)',
            'uploadMarketingImageAsset',
        ] as $sourceFragment) {
            $this->assertStringContainsString($sourceFragment, $formAndMediaSource);
        }

        foreach ([
            'use WithFileUploads;',
            'public mixed $inline_media_upload = null;',
            'uploadInlineMedia',
            'uploadMarketingImageAsset',
            'BlogMediaService::class',
        ] as $sourceFragment) {
            $this->assertStringContainsString($sourceFragment, $editPageSource);
        }

        $this->assertStringContainsString('acceptedImageTypes', $componentSource);
        $this->assertStringContainsString('x-on:change="uploadImage($event, index)"', $viewSource);
        $this->assertStringContainsString('x-on:change="uploadImage($event, index, imageIndex)"', $viewSource);
        $this->assertStringContainsString('this.wire.upload(', $scriptSource);
        $this->assertStringContainsString('this.wire.uploadInlineMedia', $scriptSource);
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

    public function test_article_materials_use_trusted_metadata_and_cannot_be_deleted_while_attached(): void
    {
        $admin = SystemAdmin::factory()->role('content_manager')->create();
        $asset = $this->mediaFixture('schedule.xlsx');
        $asset->update(['mime_type' => BlogMediaService::allowedDocumentMimeTypes()[2], 'file_size' => 2048]);
        $item = ['url' => $asset->public_url, 'label' => '<script>Образец</script>', 'file_size' => 1, 'format' => 'EXE'];
        $document = app(BlogArticleMaterialsService::class)->normalize([
            ['type' => 'materials', 'data' => ['items' => [$item, $item]]],
        ]);

        $this->assertCount(1, $document[0]['data']['items']);
        $this->assertSame(2048, $document[0]['data']['items'][0]['file_size']);
        $this->assertSame('XLSX', $document[0]['data']['items'][0]['format']);
        $html = app(BlogDocumentRenderer::class)->render($document);
        $this->assertStringContainsString('Материалы по статье', $html);
        $this->assertStringContainsString('XLSX · 2 КБ', $html);
        $this->assertStringContainsString('download="schedule.xlsx"', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->articleFixture($admin, BlogArticleStatusEnum::DRAFT, ['editor_document' => $document]);

        $this->expectException(ValidationException::class);
        app(BlogMediaService::class)->deleteAsset($asset);
    }

    public function test_article_materials_reject_external_urls_images_and_holding_documents(): void
    {
        $image = $this->mediaFixture('image.jpg');
        $holding = $this->mediaFixture('holding.pdf');
        $holding->update(['mime_type' => 'application/pdf', 'blog_context' => BlogContextEnum::HOLDING]);

        foreach (['https://untrusted.example.test/file.pdf', $image->public_url, $holding->public_url] as $url) {
            try {
                app(BlogArticleMaterialsService::class)->normalize([
                    ['type' => 'materials', 'data' => ['items' => [['url' => $url]]]],
                ]);
                $this->fail('An unavailable material was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('editor_document.0', $exception->errors());
            }
        }
    }

    public function test_document_upload_stores_file_through_s3_service_and_records_metadata(): void
    {
        $admin = SystemAdmin::factory()->role('content_manager')->create();
        $organization = Organization::factory()->create();
        config(['blog.platform_content_organization_id' => $organization->id]);
        $file = UploadedFile::fake()->createWithContent('sample.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
        $storagePath = 'org-' . $organization->id . '/cms/blog/media/sample.pdf';
        $url = 'https://storage.example.test/' . $storagePath;
        $this->mock(FileService::class, function ($mock) use ($file, $organization, $storagePath, $url): void {
            $mock->shouldReceive('upload')->once()->with($file, 'cms/blog/media', null, 'public', \Mockery::on(fn ($org) => $org->id === $organization->id), true)->andReturn($storagePath);
            $mock->shouldReceive('publicUrl')->once()->andReturn($url);
        });

        $asset = app(BlogMediaService::class)->uploadMarketingDocumentAsset($file, $admin);

        $this->assertSame($storagePath, $asset->storage_path);
        $this->assertSame($url, $asset->public_url);
        $this->assertSame('application/pdf', $asset->mime_type);
        $this->assertSame($file->getSize(), $asset->file_size);
    }

    public function test_document_upload_rejects_disguised_files_and_missing_permission_before_storage(): void
    {
        $admin = SystemAdmin::factory()->role('content_manager')->create();
        $this->mock(FileService::class, fn ($mock) => $mock->shouldNotReceive('upload'));
        $file = UploadedFile::fake()->createWithContent('fake.docx', '<html>not a document</html>');

        try {
            app(BlogMediaService::class)->uploadMarketingDocumentAsset($file, $admin);
            $this->fail('A disguised document was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('upload_file', $exception->errors());
        }

        $denied = \Mockery::mock(SystemAdmin::class)->makePartial();
        $denied->shouldReceive('hasSystemPermission')->with('system_admin.blog.media.upload')->andReturnFalse();
        $this->expectException(ValidationException::class);
        app(BlogMediaService::class)->uploadMarketingDocumentAsset($file, $denied);
    }

    public function test_material_renderer_does_not_render_empty_or_unsafe_downloads(): void
    {
        $renderer = app(BlogDocumentRenderer::class);
        $this->assertSame('', $renderer->render([['type' => 'materials', 'data' => ['items' => []]]]));
        $this->assertSame('', $renderer->render([['type' => 'materials', 'data' => ['items' => [
            ['url' => 'javascript:alert(1)', 'format' => 'PDF', 'label' => 'bad'],
        ]]]]));
    }

    public function test_office_documents_are_recognized_but_macros_are_rejected(): void
    {
        $admin = SystemAdmin::factory()->role('content_manager')->create();
        $organization = Organization::factory()->create();
        config(['blog.platform_content_organization_id' => $organization->id]);
        $this->mock(FileService::class, function ($mock): void {
            $mock->shouldReceive('upload')->twice()->andReturn('org-1/cms/blog/media/template');
            $mock->shouldReceive('publicUrl')->twice()->andReturn('https://storage.example.test/template');
        });

        foreach (['docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml', 'macro.xlsx' => 'xl/workbook.xml'] as $extension => $entry) {
            $path = tempnam(sys_get_temp_dir(), 'blog-document-');
            try {
                $zip = new \ZipArchive();
                $zip->open($path, \ZipArchive::OVERWRITE);
                $zip->addFromString('[Content_Types].xml', '<Types/>');
                $zip->addFromString($entry, '<document/>');
                if ($extension === 'macro.xlsx') {
                    $zip->addFromString('xl/vbaProject.bin', 'macro');
                }
                $zip->close();
                $file = new UploadedFile($path, 'template.' . $extension, null, null, true);

                if ($extension === 'macro.xlsx') {
                    $this->expectException(ValidationException::class);
                }
                $asset = app(BlogMediaService::class)->uploadMarketingDocumentAsset($file, $admin);
                $this->assertSame(BlogMediaService::allowedDocumentMimeTypes()[$extension === 'docx' ? 1 : 2], $asset->mime_type);
            } finally {
                unlink($path);
            }
        }
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
