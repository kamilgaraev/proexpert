<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\Blog\BlogArticleStatusEnum;
use App\Enums\Blog\BlogContextEnum;
use App\Filament\Resources\BlogArticleResource;
use App\Models\Blog\BlogArticle;
use App\Models\Blog\BlogCategory;
use App\Models\LandingAdmin;
use App\Models\SystemAdmin;
use App\Services\Security\SystemAdminRoleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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
    }

    public function test_content_manager_can_open_create_and_edit_article_editor(): void
    {
        $admin = SystemAdmin::factory()->role('content_manager')->create([
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'system_admin');

        $article = $this->articleFixture($admin);

        $this->get(BlogArticleResource::getUrl('create'))->assertSuccessful();
        $this->get(BlogArticleResource::getUrl('edit', ['record' => $article]))->assertSuccessful();
    }

    private function articleFixture(SystemAdmin $admin): BlogArticle
    {
        $category = BlogCategory::query()->create([
            'blog_context' => BlogContextEnum::MARKETING->value,
            'name' => 'Product updates',
            'slug' => 'product-updates',
            'is_active' => true,
        ]);
        $landingAdmin = LandingAdmin::query()->create([
            'name' => 'Editor',
            'email' => 'editor-' . $admin->id . '@example.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        return BlogArticle::query()->create([
            'blog_context' => BlogContextEnum::MARKETING->value,
            'category_id' => $category->id,
            'author_id' => $landingAdmin->id,
            'author_system_admin_id' => $admin->id,
            'last_edited_by_system_admin_id' => $admin->id,
            'title' => 'How to manage projects',
            'slug' => 'how-to-manage-projects-' . $admin->id,
            'excerpt' => 'Short article summary',
            'content' => '<p>Article body</p>',
            'editor_document' => [
                [
                    'type' => 'paragraph',
                    'data' => ['content' => 'Article body'],
                ],
            ],
            'editor_version' => 1,
            'status' => BlogArticleStatusEnum::DRAFT->value,
            'is_featured' => false,
            'allow_comments' => true,
            'is_published_in_rss' => true,
            'noindex' => false,
            'sort_order' => 0,
        ]);
    }
}
