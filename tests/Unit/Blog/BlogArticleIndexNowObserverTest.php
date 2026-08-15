<?php

declare(strict_types=1);

namespace Tests\Unit\Blog;

use App\Contracts\Seo\IndexNowPublisher;
use App\Enums\Blog\BlogArticleStatusEnum;
use App\Enums\Blog\BlogContextEnum;
use App\Models\Blog\BlogArticle;
use App\Observers\BlogArticleIndexNowObserver;
use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

class BlogArticleIndexNowObserverTest extends TestCase
{
    private RecordingIndexNowPublisher $publisher;

    private BlogArticleIndexNowObserver $observer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publisher = new RecordingIndexNowPublisher;
        $this->observer = new BlogArticleIndexNowObserver(
            $this->publisher,
            new Repository([
                'blog' => [
                    'indexnow' => [
                        'public_base_url' => 'https://xn--1-xtbgmf.xn--p1ai',
                    ],
                ],
            ]),
        );
    }

    public function test_it_submits_a_newly_published_marketing_article(): void
    {
        $article = $this->publishedArticle();

        $this->observer->created($article);

        self::assertSame([[
            'https://xn--1-xtbgmf.xn--p1ai/blog/project-control',
        ]], $this->publisher->submissions);
    }

    public function test_slug_change_submits_both_old_and_new_urls(): void
    {
        $article = $this->publishedArticle();
        $article->slug = 'updated-project-control';
        $article->syncChanges();

        $this->observer->updated($article);

        self::assertSame([[
            'https://xn--1-xtbgmf.xn--p1ai/blog/project-control',
            'https://xn--1-xtbgmf.xn--p1ai/blog/updated-project-control',
        ]], $this->publisher->submissions);
    }

    public function test_view_counter_change_does_not_submit_the_article(): void
    {
        $article = $this->publishedArticle();
        $article->views_count = 2;
        $article->syncChanges();

        $this->observer->updated($article);

        self::assertSame([], $this->publisher->submissions);
    }

    public function test_unpublishing_submits_the_removed_public_url(): void
    {
        $article = $this->publishedArticle();
        $article->status = BlogArticleStatusEnum::DRAFT;
        $article->syncChanges();

        $this->observer->updated($article);

        self::assertSame([[
            'https://xn--1-xtbgmf.xn--p1ai/blog/project-control',
        ]], $this->publisher->submissions);
    }

    public function test_holding_articles_are_not_submitted_for_the_marketing_domain(): void
    {
        $article = $this->publishedArticle([
            'blog_context' => BlogContextEnum::HOLDING->value,
        ]);

        $this->observer->created($article);

        self::assertSame([], $this->publisher->submissions);
    }

    public function test_deleting_a_published_article_submits_its_public_url(): void
    {
        $article = $this->publishedArticle();

        $this->observer->deleted($article);

        self::assertSame([[
            'https://xn--1-xtbgmf.xn--p1ai/blog/project-control',
        ]], $this->publisher->submissions);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function publishedArticle(array $overrides = []): BlogArticle
    {
        $article = new BlogArticle;
        $article->setRawAttributes(array_merge([
            'blog_context' => BlogContextEnum::MARKETING->value,
            'title' => 'Управление строительным проектом',
            'slug' => 'project-control',
            'status' => BlogArticleStatusEnum::PUBLISHED->value,
            'published_at' => CarbonImmutable::now()->subMinute(),
            'noindex' => false,
            'views_count' => 1,
        ], $overrides), true);
        $article->exists = true;

        return $article;
    }
}

final class RecordingIndexNowPublisher implements IndexNowPublisher
{
    /** @var array<int, array<int, string>> */
    public array $submissions = [];

    public function publish(array $urls): void
    {
        $this->submissions[] = $urls;
    }
}
