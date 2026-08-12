<?php

declare(strict_types=1);

namespace App\Console\Commands\Blog;

use App\Content\Blog\MarketingSeoArticleCatalog;
use App\Enums\Blog\BlogArticleStatusEnum;
use App\Enums\Blog\BlogContextEnum;
use App\Models\Blog\BlogArticle;
use App\Models\Blog\BlogCategory;
use App\Models\Blog\BlogTag;
use App\Models\LandingAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

final class RefreshMarketingSeoArticlesCommand extends Command
{
    protected $signature = 'blog:refresh-marketing-seo-articles {--dry-run : Show articles without saving}';

    protected $description = 'Refresh high-intent marketing blog articles';

    public function handle(): int
    {
        $articles = MarketingSeoArticleCatalog::articles();

        if ((bool) $this->option('dry-run')) {
            $this->table(
                ['slug', 'title', 'words', 'reading_time'],
                array_map(fn (array $article): array => [
                    $article['slug'],
                    $article['title'],
                    $this->countWords($article['content']),
                    $this->calculateReadingTime($article['content']),
                ], $articles)
            );

            $this->line('Legacy test articles to archive: ' . count(MarketingSeoArticleCatalog::legacySlugs()));

            return Command::SUCCESS;
        }

        try {
            $updatedCount = 0;
            $archivedCount = 0;

            DB::transaction(function () use ($articles, &$updatedCount, &$archivedCount): void {
                $author = $this->getAuthor();
                $categories = $this->refreshCategories();

                foreach ($articles as $articleData) {
                    $category = $categories[$articleData['category_slug']];
                    $article = BlogArticle::query()
                        ->where('slug', $articleData['slug'])
                        ->firstOrNew(['slug' => $articleData['slug']]);

                    $article->fill([
                        'blog_context' => BlogContextEnum::MARKETING->value,
                        'category_id' => $category->id,
                        'author_id' => $author->id,
                        'author_system_admin_id' => null,
                        'title' => $articleData['title'],
                        'excerpt' => $articleData['excerpt'],
                        'content' => $articleData['content'],
                        'editor_document' => null,
                        'editor_version' => max(1, (int) ($article->editor_version ?? 1) + 1),
                        'featured_image' => $articleData['featured_image'],
                        'gallery_images' => [],
                        'meta_title' => $articleData['meta_title'],
                        'meta_description' => $articleData['meta_description'],
                        'meta_keywords' => $articleData['meta_keywords'],
                        'og_title' => $articleData['og_title'],
                        'og_description' => $articleData['og_description'],
                        'og_image' => $articleData['og_image'],
                        'structured_data' => $this->faqStructuredData($articleData['faq']),
                        'status' => BlogArticleStatusEnum::PUBLISHED->value,
                        'scheduled_at' => null,
                        'reading_time' => $this->calculateReadingTime($articleData['content']),
                        'is_featured' => true,
                        'allow_comments' => true,
                        'is_published_in_rss' => true,
                        'noindex' => false,
                        'sort_order' => $articleData['sort_order'],
                    ]);

                    if ($article->published_at === null) {
                        $article->published_at = now();
                    }

                    $article->save();
                    $this->syncTags($article, $articleData['tags']);
                    $updatedCount++;
                }

                $archivedCount = $this->archiveLegacyArticles();
            });

            $this->info("Marketing blog articles refreshed: {$updatedCount}; legacy articles archived: {$archivedCount}");

            return Command::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Marketing blog refresh failed: ' . $exception->getMessage());

            return Command::FAILURE;
        }
    }

    private function getAuthor(): LandingAdmin
    {
        return LandingAdmin::query()->firstOrCreate(
            ['email' => 'blog@1мост.рф'],
            [
                'name' => 'Команда МОСТ',
                'password' => Hash::make(Str::random(48)),
                'role' => 'admin',
            ]
        );
    }

    private function refreshCategories(): array
    {
        $categories = [];

        foreach ($this->categories() as $categoryData) {
            $category = BlogCategory::query()->where('slug', $categoryData['slug'])->firstOrNew([
                'slug' => $categoryData['slug'],
            ]);

            $category->fill([
                'blog_context' => BlogContextEnum::MARKETING->value,
                'name' => $categoryData['name'],
                'description' => $categoryData['description'],
                'meta_title' => $categoryData['meta_title'],
                'meta_description' => $categoryData['meta_description'],
                'color' => $categoryData['color'],
                'sort_order' => $categoryData['sort_order'],
                'is_active' => true,
            ]);

            $category->save();
            $categories[$category->slug] = $category;
        }

        return $categories;
    }

    private function syncTags(BlogArticle $article, array $tags): void
    {
        $tagIds = [];

        foreach ($tags as $tagData) {
            $tag = $this->resolveTag($tagData);

            $tag->fill([
                'blog_context' => BlogContextEnum::MARKETING->value,
                'name' => $tagData['name'],
                'description' => $tagData['description'] ?? null,
                'color' => $tagData['color'] ?? '#64748b',
                'is_active' => true,
            ]);

            $tag->save();
            $tagIds[] = $tag->id;
        }

        $article->tags()->sync($tagIds);

        BlogTag::query()
            ->whereIn('id', $tagIds)
            ->get()
            ->each(function (BlogTag $tag): void {
                $tag->usage_count = $tag->articles()->count();
                $tag->save();
            });
    }

    private function resolveTag(array $tagData): BlogTag
    {
        $tagByName = BlogTag::query()->where('name', $tagData['name'])->first();

        if ($tagByName instanceof BlogTag) {
            return $tagByName;
        }

        $tagBySlug = BlogTag::query()->where('slug', $tagData['slug'])->first();

        if ($tagBySlug instanceof BlogTag) {
            return $tagBySlug;
        }

        $tag = new BlogTag();
        $tag->slug = $tagData['slug'];

        return $tag;
    }

    private function archiveLegacyArticles(): int
    {
        return BlogArticle::query()
            ->where('blog_context', BlogContextEnum::MARKETING->value)
            ->whereIn('slug', MarketingSeoArticleCatalog::legacySlugs())
            ->update([
                'status' => BlogArticleStatusEnum::ARCHIVED->value,
                'is_featured' => false,
                'is_published_in_rss' => false,
                'noindex' => true,
                'updated_at' => now(),
            ]);
    }

    private function calculateReadingTime(string $content): int
    {
        return max(1, (int) ceil($this->countWords($content) / 180));
    }

    private function countWords(string $content): int
    {
        $plainText = trim(strip_tags($content));
        $wordCount = preg_match_all('/[\p{L}\p{N}]+/u', $plainText);

        return is_int($wordCount) ? $wordCount : 0;
    }

    private function faqStructuredData(array $faq): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(
                static fn (array $item): array => [
                    '@type' => 'Question',
                    'name' => $item['question'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $item['answer'],
                    ],
                ],
                $faq
            ),
        ];
    }

    private function categories(): array
    {
        return [
            [
                'slug' => 'upravlenie-obektom',
                'name' => 'Управление объектом',
                'description' => 'Практические материалы для прорабов, руководителей проектов и строительных команд.',
                'meta_title' => 'Управление строительным объектом | Блог МОСТ',
                'meta_description' => 'Как держать под контролем график, задачи, заявки, сроки, материалы и коммуникацию на строительном объекте.',
                'color' => '#ea580c',
                'sort_order' => 10,
            ],
            [
                'slug' => 'pto-i-dokumenty',
                'name' => 'ПТО и документы',
                'description' => 'Материалы об исполнительной документации, актах, версиях файлов и согласованиях.',
                'meta_title' => 'ПТО и исполнительная документация | Блог МОСТ',
                'meta_description' => 'Практика для ПТО: исполнительная документация, акты, схемы, журналы, фотофиксация и согласования.',
                'color' => '#2563eb',
                'sort_order' => 20,
            ],
            [
                'slug' => 'upravlencheskiy-kontrol',
                'name' => 'Управленческий контроль',
                'description' => 'Материалы для руководителей строительства: сроки, деньги, подрядчики, риски и состояние проектов.',
                'meta_title' => 'Управленческий контроль в строительстве | Блог МОСТ',
                'meta_description' => 'Что руководителю строительной компании важно видеть по проектам: сроки, бюджет, снабжение, подрядчики и риски.',
                'color' => '#7c3aed',
                'sort_order' => 40,
            ],
        ];
    }
}
