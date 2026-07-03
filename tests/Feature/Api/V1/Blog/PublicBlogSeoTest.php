<?php

declare(strict_types=1);

use App\Enums\Blog\BlogArticleStatusEnum;
use App\Enums\Blog\BlogContextEnum;
use App\Models\Blog\BlogArticle;
use App\Models\Blog\BlogCategory;
use App\Models\LandingAdmin;
use Illuminate\Support\Carbon;

function createPublicBlogSeoArticle(array $attributes = []): BlogArticle
{
    $category = BlogCategory::query()->create([
        'blog_context' => $attributes['blog_context'] ?? BlogContextEnum::MARKETING->value,
        'name' => $attributes['category_name'] ?? 'SEO',
        'slug' => $attributes['category_slug'] ?? 'seo-' . fake()->unique()->slug(),
        'color' => '#0f172a',
        'is_active' => true,
    ]);

    $author = LandingAdmin::query()->create([
        'name' => 'МОСТ',
        'email' => fake()->unique()->safeEmail(),
        'password' => 'password',
    ]);

    return BlogArticle::query()->create(array_merge([
        'blog_context' => BlogContextEnum::MARKETING->value,
        'category_id' => $category->id,
        'author_id' => $author->id,
        'title' => 'Indexable article',
        'slug' => 'indexable-article-' . fake()->unique()->slug(),
        'excerpt' => 'Article excerpt',
        'content' => '<p>Article content for search engines.</p>',
        'status' => BlogArticleStatusEnum::PUBLISHED->value,
        'published_at' => Carbon::parse('2026-05-20 10:00:00'),
        'views_count' => 0,
        'likes_count' => 0,
        'comments_count' => 0,
        'is_featured' => false,
        'allow_comments' => true,
        'is_published_in_rss' => true,
        'noindex' => false,
        'sort_order' => 0,
    ], $attributes));
}

it('returns only published indexable marketing articles for sitemap automation', function (): void {
    /** @var \Tests\TestCase $this */
    $indexable = createPublicBlogSeoArticle([
        'slug' => 'published-indexable',
        'updated_at' => Carbon::parse('2026-05-21 12:00:00'),
    ]);

    createPublicBlogSeoArticle([
        'slug' => 'hidden-from-index',
        'noindex' => true,
    ]);

    createPublicBlogSeoArticle([
        'slug' => 'draft-article',
        'status' => BlogArticleStatusEnum::DRAFT->value,
        'published_at' => null,
    ]);

    createPublicBlogSeoArticle([
        'slug' => 'holding-article',
        'blog_context' => BlogContextEnum::HOLDING->value,
    ]);

    $response = $this->getJson('/api/v1/blog/sitemap');

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', $indexable->slug)
        ->assertJsonPath('data.0.url', '/blog/' . $indexable->slug);
});

it('does not increment article views when SSR disables tracking', function (): void {
    /** @var \Tests\TestCase $this */
    $article = createPublicBlogSeoArticle([
        'slug' => 'ssr-view-test',
        'views_count' => 7,
    ]);

    $this->getJson('/api/v1/blog/articles/' . $article->slug . '?track_view=0')
        ->assertOk()
        ->assertJsonPath('data.slug', $article->slug);

    expect($article->refresh()->views_count)->toBe(7);

    $this->getJson('/api/v1/blog/articles/' . $article->slug)
        ->assertOk();

    expect($article->refresh()->views_count)->toBe(8);
});

it('returns png marketing OG images in public article responses', function (): void {
    /** @var \Tests\TestCase $this */
    $article = createPublicBlogSeoArticle([
        'slug' => 'contractor-control',
        'og_image' => 'https://prohelper.pro/og/contractor-control.svg',
    ]);

    $this->getJson('/api/v1/blog/articles?per_page=20')
        ->assertOk()
        ->assertJsonPath('data.data.0.og_image', 'https://prohelper.pro/og/contractor-control.png');

    $this->getJson('/api/v1/blog/articles/' . $article->slug . '?track_view=0')
        ->assertOk()
        ->assertJsonPath('data.og_image', 'https://prohelper.pro/og/contractor-control.png');
});
