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
