<?php

declare(strict_types=1);

namespace Tests\Unit\Content\Blog;

use App\Content\Blog\MarketingSeoArticleCatalog;
use PHPUnit\Framework\TestCase;

final class MarketingSeoArticleCatalogTest extends TestCase
{
    public function testCatalogContainsThreeDistinctAudienceArticles(): void
    {
        $articles = $this->articles();

        self::assertCount(3, $articles);
        self::assertSame(
            [
                'sistema-upravleniya-stroitelstvom',
                'grafik-proizvodstva-rabot-v-stroitelstve',
                'ispolnitelnaya-dokumentaciya-v-stroitelstve',
            ],
            array_column($articles, 'slug')
        );
    }

    public function testEveryArticleIsSubstantialAndIncludesSearchIntentSections(): void
    {
        foreach ($this->articles() as $article) {
            $plainText = trim(strip_tags($article['content']));
            $wordCount = preg_match_all('/[\p{L}\p{N}]+/u', $plainText);

            self::assertGreaterThanOrEqual(1800, $wordCount, $article['slug']);
            self::assertStringContainsString('<h2>', $article['content'], $article['slug']);
            self::assertStringContainsString('<h2>Частые вопросы', $article['content'], $article['slug']);
            self::assertStringContainsString('<figure>', $article['content'], $article['slug']);
        }
    }

    public function testEveryArticleIntroducesMostAndLinksToRelevantProductPage(): void
    {
        $expectedProductLinks = [
            'sistema-upravleniya-stroitelstvom' => '/construction-erp',
            'grafik-proizvodstva-rabot-v-stroitelstve' => '/construction-erp',
            'ispolnitelnaya-dokumentaciya-v-stroitelstve' => '/construction-documents',
        ];

        foreach ($this->articles() as $article) {
            self::assertStringContainsString('МОСТ</a> — система управления строительством', $article['content'], $article['slug']);
            self::assertStringContainsString(
                'href="' . $expectedProductLinks[$article['slug']] . '"',
                $article['content'],
                $article['slug']
            );
        }
    }

    public function testSourcesArePresentedOnlyWhereTheySupportTheArticle(): void
    {
        $articles = array_column($this->articles(), null, 'slug');

        self::assertStringNotContainsString(
            '<h2>Источники и данные для проверки</h2>',
            $articles['sistema-upravleniya-stroitelstvom']['content']
        );
        self::assertStringContainsString(
            'Стратегическое направление цифровой трансформации',
            $articles['sistema-upravleniya-stroitelstvom']['content']
        );
        self::assertStringNotContainsString(
            '<h2>Источники и данные для проверки</h2>',
            $articles['grafik-proizvodstva-rabot-v-stroitelstve']['content']
        );
        self::assertStringContainsString(
            '<h2>Нормативная база</h2>',
            $articles['ispolnitelnaya-dokumentaciya-v-stroitelstve']['content']
        );
    }

    public function testEveryArticleHasCompleteSeoAndVisualMetadata(): void
    {
        foreach ($this->articles() as $article) {
            self::assertNotEmpty($article['meta_title'], $article['slug']);
            self::assertNotEmpty($article['meta_description'], $article['slug']);
            self::assertNotEmpty($article['meta_keywords'], $article['slug']);
            self::assertSame($article['featured_image'], $article['og_image'], $article['slug']);
            self::assertStringEndsWith('.jpg', $article['featured_image'], $article['slug']);
            self::assertNotEmpty($article['faq'], $article['slug']);
        }
    }

    public function testLegacyTestArticlesAreExplicitlyListedForArchiving(): void
    {
        self::assertSame(
            [
                'kak-prorabu-derzhat-obekt-bez-haosa',
                'chto-dolzhno-byt-u-pto-v-odnoy-sisteme',
                'kak-snabzhentsu-perestat-sobirat-zayavki-iz-chatov',
                'chto-rukovoditel-stroitelstva-dolzhen-videt-kazhdoe-utro',
                'kak-kontrolirovat-podryadchikov-na-obekte-bez-razborok',
            ],
            MarketingSeoArticleCatalog::legacySlugs()
        );
    }

    private function articles(): array
    {
        $catalogPath = dirname(__DIR__, 4) . '/app/Content/Blog/MarketingSeoArticleCatalog.php';

        self::assertFileExists($catalogPath);
        require_once $catalogPath;

        return MarketingSeoArticleCatalog::articles();
    }
}
