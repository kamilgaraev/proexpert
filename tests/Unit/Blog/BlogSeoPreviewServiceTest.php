<?php

declare(strict_types=1);

namespace Tests\Unit\Blog;

use App\Models\Blog\BlogArticle;
use App\Services\Blog\BlogSeoPreviewService;
use Tests\TestCase;

class BlogSeoPreviewServiceTest extends TestCase
{
    public function test_preview_reports_title_description_and_canonical_url_state(): void
    {
        config(['blog.marketing_frontend_url' => 'https://front.example.test']);

        $preview = app(BlogSeoPreviewService::class)->preview([
            'title' => 'Как управлять строительными проектами без потери контроля',
            'slug' => 'project-control',
            'excerpt' => 'Короткое описание для карточек.',
            'canonical_url' => 'https://canonical.example.test/project-control',
            'meta_title' => 'Управление строительными проектами без потери контроля',
            'meta_description' => 'Практический разбор управления строительными проектами, сроками, подрядчиками и коммуникациями в ProHelper.',
        ]);

        $this->assertSame('Управление строительными проектами без потери контроля', $preview['title']);
        $this->assertSame('https://canonical.example.test/project-control', $preview['url']);
        $this->assertSame('ok', $this->checkStatus($preview, 'title_length'));
        $this->assertSame('ok', $this->checkStatus($preview, 'description_length'));
        $this->assertSame('ok', $this->checkStatus($preview, 'canonical_url'));
    }

    public function test_preview_uses_open_graph_fallbacks_and_warns_about_noindex(): void
    {
        config(['blog.marketing_frontend_url' => 'https://front.example.test']);

        $preview = app(BlogSeoPreviewService::class)->preview([
            'title' => 'Release',
            'slug' => 'release',
            'excerpt' => 'Short',
            'featured_image' => 'https://cdn.example.test/blog/release.jpg',
            'noindex' => true,
        ]);

        $this->assertSame('Release', $preview['og_title']);
        $this->assertSame('Short', $preview['og_description']);
        $this->assertSame('https://cdn.example.test/blog/release.jpg', $preview['og_image']);
        $this->assertSame('https://front.example.test/blog/release', $preview['url']);
        $this->assertSame('warning', $this->checkStatus($preview, 'title_length'));
        $this->assertSame('warning', $this->checkStatus($preview, 'description_length'));
        $this->assertSame('ok', $this->checkStatus($preview, 'og_image'));
        $this->assertSame('warning', $this->checkStatus($preview, 'noindex'));
    }

    public function test_preview_accepts_blog_article_model(): void
    {
        config(['blog.marketing_frontend_url' => 'https://front.example.test']);

        $article = new BlogArticle([
            'title' => 'Гайд по платформе',
            'slug' => 'platform-guide',
            'meta_title' => 'Гайд по платформе ProHelper',
            'meta_description' => 'Подробный обзор возможностей платформы ProHelper для операционной команды.',
            'featured_image' => 'https://cdn.example.test/blog/platform.jpg',
        ]);

        $preview = app(BlogSeoPreviewService::class)->preview($article);

        $this->assertSame('Гайд по платформе ProHelper', $preview['title']);
        $this->assertSame('https://front.example.test/blog/platform-guide', $preview['url']);
        $this->assertSame('https://cdn.example.test/blog/platform.jpg', $preview['og_image']);
    }

    public function test_article_editor_contains_seo_preview_panel(): void
    {
        $formSource = (string) file_get_contents(app_path('Filament/Resources/BlogArticleResource/Schemas/BlogArticleForm.php'));

        $this->assertFileExists(app_path('Filament/Resources/BlogArticleResource/Components/BlogSeoPreview.php'));
        $this->assertFileExists(resource_path('views/filament/blog/article-editor/seo-preview.blade.php'));
        $this->assertStringContainsString("->view('filament.blog.article-editor.seo-preview')", $formSource);
        $this->assertStringContainsString("ViewField::make('seo_preview')", $formSource);
    }

    /**
     * @param array{checks: array<int, array{key: string, status: string, message: string}>} $preview
     */
    private function checkStatus(array $preview, string $key): string
    {
        $check = collect($preview['checks'])->firstWhere('key', $key);

        $this->assertIsArray($check);

        return (string) $check['status'];
    }
}
