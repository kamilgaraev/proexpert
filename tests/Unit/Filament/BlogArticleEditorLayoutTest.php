<?php

declare(strict_types=1);

namespace Tests\Unit\Filament;

use App\Filament\Resources\BlogArticleResource\Pages\CreateBlogArticle;
use App\Filament\Resources\BlogArticleResource\Pages\EditBlogArticle;
use App\Filament\Resources\BlogArticleResource\Pages\ViewBlogArticle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class BlogArticleEditorLayoutTest extends TestCase
{
    #[DataProvider('articlePageProvider')]
    public function test_article_pages_use_scrollable_fullscreen_container(string $pageClass): void
    {
        $defaultProperties = (new ReflectionClass($pageClass))->getDefaultProperties();

        $this->assertSame('fi-blog-article-editor-screen', $defaultProperties['maxContentWidth'] ?? null);
    }

    public function test_theme_defines_scrollable_article_editor_screen_container(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../../resources/css/filament/admin/theme.css');

        $this->assertStringContainsString('.fi-simple-main.fi-blog-article-editor-screen', $css);
        $this->assertStringContainsString('position: static', $css);
        $this->assertStringContainsString('min-height: 100dvh', $css);
        $this->assertStringContainsString('padding-bottom: 7rem', $css);
    }

    public function test_article_form_uses_inline_block_editor_component(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../app/Filament/Resources/BlogArticleResource/Schemas/BlogArticleForm.php');

        $this->assertStringContainsString('BlogInlineBlockEditor::make(\'editor_document\')', $source);
        $this->assertStringContainsString('BlogEditorBlockCatalog::forEditor()', $source);
        $this->assertStringNotContainsString('Builder::make(\'editor_document\')', $source);
    }

    public function test_theme_defines_inline_blog_editor_layout(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../../resources/css/filament/admin/theme.css');

        $this->assertStringContainsString('.ph-blog-inline-editor', $css);
        $this->assertStringContainsString('.ph-blog-inline-editor__block', $css);
        $this->assertStringContainsString('.ph-blog-inline-editor__toolbar', $css);
        $this->assertStringContainsString('.ph-blog-inline-editor__slash-menu', $css);
        $this->assertStringContainsString('.ph-blog-inline-editor__upload-button', $css);
        $this->assertStringContainsString('.dark .ph-blog-inline-editor__upload-button', $css);
    }

    /**
     * @return iterable<string, array{pageClass: class-string}>
     */
    public static function articlePageProvider(): iterable
    {
        yield 'create article' => ['pageClass' => CreateBlogArticle::class];
        yield 'edit article' => ['pageClass' => EditBlogArticle::class];
        yield 'view article' => ['pageClass' => ViewBlogArticle::class];
    }
}
