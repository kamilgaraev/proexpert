<?php

declare(strict_types=1);

namespace App\Observers;

use App\Contracts\Seo\IndexNowPublisher;
use App\Enums\Blog\BlogArticleStatusEnum;
use App\Enums\Blog\BlogContextEnum;
use App\Models\Blog\BlogArticle;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final class BlogArticleIndexNowObserver implements ShouldHandleEventsAfterCommit
{
    private const INDEXABLE_FIELDS = [
        'blog_context',
        'title',
        'slug',
        'excerpt',
        'canonical_url',
        'content',
        'featured_image',
        'meta_title',
        'meta_description',
        'og_title',
        'og_description',
        'og_image',
        'structured_data',
        'status',
        'published_at',
        'noindex',
    ];

    public function __construct(
        private readonly IndexNowPublisher $publisher,
        private readonly Repository $config,
    ) {}

    public function created(BlogArticle $article): void
    {
        if ($this->isIndexable($article)) {
            $this->publisher->publish([$this->url((string) $article->getAttribute('slug'))]);
        }
    }

    public function updated(BlogArticle $article): void
    {
        if (! $article->wasChanged(self::INDEXABLE_FIELDS)) {
            return;
        }

        $urls = [];

        if ($this->wasIndexable($article)) {
            $urls[] = $this->url((string) $article->getRawOriginal('slug'));
        }

        if ($this->isIndexable($article)) {
            $urls[] = $this->url((string) $article->getAttribute('slug'));
        }

        $urls = array_values(array_unique($urls));

        if ($urls !== []) {
            $this->publisher->publish($urls);
        }
    }

    public function deleted(BlogArticle $article): void
    {
        if ($this->isIndexable($article)) {
            $this->publisher->publish([$this->url((string) $article->getAttribute('slug'))]);
        }
    }

    private function isIndexable(BlogArticle $article): bool
    {
        $publishedAt = $article->getAttribute('published_at');

        return $article->getAttribute('blog_context') === BlogContextEnum::MARKETING
            && $article->getAttribute('status') === BlogArticleStatusEnum::PUBLISHED
            && ! (bool) $article->getAttribute('noindex')
            && ($publishedAt === null || ($publishedAt instanceof CarbonInterface && $publishedAt->isPast()));
    }

    private function wasIndexable(BlogArticle $article): bool
    {
        $publishedAt = $article->getRawOriginal('published_at');

        return $article->getRawOriginal('blog_context') === BlogContextEnum::MARKETING->value
            && $article->getRawOriginal('status') === BlogArticleStatusEnum::PUBLISHED->value
            && ! (bool) $article->getRawOriginal('noindex')
            && ($publishedAt === null || CarbonImmutable::parse((string) $publishedAt)->isPast());
    }

    private function url(string $slug): string
    {
        return rtrim((string) $this->config->get('blog.indexnow.public_base_url'), '/')
            .'/blog/'.ltrim($slug, '/');
    }
}
