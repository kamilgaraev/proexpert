<?php

declare(strict_types=1);

namespace Tests\Unit\Content\Blog;

use App\Content\Blog\MarketingSeoArticleCatalog;
use PHPUnit\Framework\TestCase;

final class MarketingSeoArticleCatalogTest extends TestCase
{
    public function test_catalog_contains_ten_distinct_audience_articles(): void
    {
        $articles = $this->articles();

        self::assertCount(10, $articles);
        self::assertSame(
            [
                'sistema-upravleniya-stroitelstvom',
                'grafik-proizvodstva-rabot-v-stroitelstve',
                'ispolnitelnaya-dokumentaciya-v-stroitelstve',
                'zayavka-na-materialy-v-stroitelstve',
                'plan-fakt-v-stroitelstve',
                'obshchiy-zhurnal-rabot-v-stroitelstve',
                'iskusstvennyy-intellekt-v-stroitelstve-2026',
                'neyroset-dlya-smety-po-pdf',
                'sryv-srokov-i-pereraskhod-byudzheta-v-stroitelstve',
                'kompyuternoe-zrenie-na-stroyploshchadke',
            ],
            array_column($articles, 'slug')
        );
    }

    public function test_every_article_includes_search_intent_sections(): void
    {
        foreach ($this->articles() as $article) {
            self::assertStringContainsString('<h2>', $article['content'], $article['slug']);
            self::assertStringContainsString('<h2>Частые вопросы', $article['content'], $article['slug']);
            self::assertStringContainsString('<figure>', $article['content'], $article['slug']);
        }
    }

    public function test_existing_articles_remain_long_reads_and_new_series_stays_within_requested_length(): void
    {
        $newArticleSlugs = [
            'iskusstvennyy-intellekt-v-stroitelstve-2026',
            'neyroset-dlya-smety-po-pdf',
            'sryv-srokov-i-pereraskhod-byudzheta-v-stroitelstve',
            'kompyuternoe-zrenie-na-stroyploshchadke',
        ];

        foreach ($this->articles() as $article) {
            $plainText = trim(strip_tags($article['content']));

            if (in_array($article['slug'], $newArticleSlugs, true)) {
                $characterCount = mb_strlen(preg_replace('/\s+/u', ' ', $plainText) ?? '');

                self::assertGreaterThanOrEqual(3000, $characterCount, $article['slug']);
                self::assertLessThanOrEqual(4200, $characterCount, $article['slug']);
                self::assertSame(1, substr_count($article['content'], '<img '), $article['slug']);

                continue;
            }

            $wordCount = preg_match_all('/[\p{L}\p{N}]+/u', $plainText);
            self::assertGreaterThanOrEqual(1800, $wordCount, $article['slug']);
        }
    }

    public function test_every_article_introduces_most_and_links_to_relevant_product_page(): void
    {
        $expectedProductLinks = [
            'sistema-upravleniya-stroitelstvom' => '/construction-erp',
            'grafik-proizvodstva-rabot-v-stroitelstve' => '/construction-erp',
            'ispolnitelnaya-dokumentaciya-v-stroitelstve' => '/construction-documents',
            'zayavka-na-materialy-v-stroitelstve' => '/site-requests',
            'plan-fakt-v-stroitelstve' => '/construction-budget-control',
            'obshchiy-zhurnal-rabot-v-stroitelstve' => '/construction-documents',
            'iskusstvennyy-intellekt-v-stroitelstve-2026' => '/ai-estimates',
            'neyroset-dlya-smety-po-pdf' => '/ai-estimates',
            'sryv-srokov-i-pereraskhod-byudzheta-v-stroitelstve' => '/project-pulse',
            'kompyuternoe-zrenie-na-stroyploshchadke' => '/construction-quality-control',
        ];

        foreach ($this->articles() as $article) {
            self::assertStringContainsString('МОСТ</a> — система управления строительством', $article['content'], $article['slug']);
            self::assertStringContainsString(
                'href="'.$expectedProductLinks[$article['slug']].'"',
                $article['content'],
                $article['slug']
            );
        }
    }

    public function test_sources_are_presented_only_where_they_support_the_article(): void
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

    public function test_every_article_has_complete_seo_and_visual_metadata(): void
    {
        foreach ($this->articles() as $article) {
            self::assertNotEmpty($article['meta_title'], $article['slug']);
            self::assertNotEmpty($article['meta_description'], $article['slug']);
            self::assertNotEmpty($article['meta_keywords'], $article['slug']);
            self::assertIsArray($article['meta_keywords'], $article['slug']);

            foreach ($article['meta_keywords'] as $keyword) {
                self::assertIsString($keyword, $article['slug']);
                self::assertNotSame('', trim($keyword), $article['slug']);
            }

            self::assertSame($article['featured_image'], $article['og_image'], $article['slug']);
            self::assertStringEndsWith('.jpg', $article['featured_image'], $article['slug']);
            self::assertNotEmpty($article['faq'], $article['slug']);
        }
    }

    public function test_legacy_test_articles_are_explicitly_listed_for_archiving(): void
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
        $catalogPath = dirname(__DIR__, 4).'/app/Content/Blog/MarketingSeoArticleCatalog.php';

        self::assertFileExists($catalogPath);
        require_once $catalogPath;

        return MarketingSeoArticleCatalog::articles();
    }
}
