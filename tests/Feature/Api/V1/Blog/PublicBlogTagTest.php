<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Blog;

use App\Enums\Blog\BlogArticleStatusEnum;
use App\Enums\Blog\BlogContextEnum;
use App\Models\Blog\BlogArticle;
use App\Models\Blog\BlogCategory;
use App\Models\Blog\BlogTag;
use App\Models\LandingAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicBlogTagTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_tag_excludes_unpublished_and_foreign_articles(): void
    {
        $tag = $this->createTag();
        $published = $this->createArticle(['noindex' => true]);
        $published->tags()->attach($tag);
        $draft = $this->createArticle(['status' => BlogArticleStatusEnum::DRAFT->value]);
        $draft->tags()->attach($tag);
        $future = $this->createArticle(['published_at' => now()->addDay()]);
        $future->tags()->attach($tag);
        $holding = $this->createArticle(['blog_context' => BlogContextEnum::HOLDING->value]);
        $holding->tags()->attach($tag);
        $this->createArticle(['title' => 'reinforcement Арматура']);
        $otherTag = $this->createTag(['name' => 'Другая арматура', 'slug' => 'other-reinforcement']);
        $this->createArticle()->tags()->attach($otherTag);

        $this->getJson('/api/v1/blog/articles?tag_slug=reinforcement')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $published->id)
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.meta.per_page', 12);
    }

    public function test_pagination_keeps_exact_tag_and_stable_order(): void
    {
        $tag = $this->createTag();
        $oldest = $this->createArticle(['published_at' => '2026-01-01 10:00:00']);
        $oldest->tags()->attach($tag);
        $middle = $this->createArticle(['published_at' => '2026-01-01 10:00:00']);
        $middle->tags()->attach($tag);
        $latest = $this->createArticle(['published_at' => '2026-01-01 10:00:00']);
        $latest->tags()->attach($tag);

        $first = $this->getJson('/api/v1/blog/articles?tag_slug=reinforcement&per_page=2&page=1')
            ->assertOk()
            ->assertJsonCount(2, 'data.data')
            ->assertJsonPath('data.data.0.id', $latest->id)
            ->assertJsonPath('data.data.1.id', $middle->id)
            ->assertJsonPath('data.meta.total', 3)
            ->assertJsonPath('data.meta.last_page', 2);

        parse_str(parse_url($first->json('data.links.next'), PHP_URL_QUERY), $nextQuery);
        self::assertSame('reinforcement', $nextQuery['tag_slug']);
        self::assertSame('2', $nextQuery['per_page']);
        self::assertSame('2', $nextQuery['page']);

        $this->getJson('/api/v1/blog/articles?'.http_build_query($nextQuery))
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $oldest->id)
            ->assertJsonPath('data.meta.current_page', 2)
            ->assertJsonPath('data.meta.per_page', 2)
            ->assertJsonPath('data.links.next', null);

        $this->getJson('/api/v1/blog/articles?tag_slug=reinforcement&per_page=2&page=3')
            ->assertOk()
            ->assertJsonCount(0, 'data.data')
            ->assertJsonPath('data.meta.current_page', 3)
            ->assertJsonPath('data.meta.last_page', 2)
            ->assertJsonPath('data.meta.total', 3);
    }

    public function test_tag_combines_with_category_and_search(): void
    {
        $tag = $this->createTag();
        $matching = $this->createArticle(['title' => 'Доставка бетона']);
        $matching->tags()->attach($tag);
        $this->createArticle(['category_id' => $matching->category_id, 'title' => 'Доставка кирпича'])
            ->tags()->attach($tag);
        $this->createArticle(['title' => 'Доставка бетона'])->tags()->attach($tag);
        $this->createArticle(['category_id' => $matching->category_id, 'title' => 'Доставка бетона']);

        $this->getJson('/api/v1/blog/articles?'.http_build_query([
            'tag_slug' => $tag->slug,
            'category_id' => $matching->category_id,
            'search' => 'бетона',
            'per_page' => 24,
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $matching->id)
            ->assertJsonPath('data.meta.per_page', 24);
    }

    public function test_known_empty_tag_returns_successful_empty_page(): void
    {
        $this->createTag();

        $this->getJson('/api/v1/blog/articles?tag_slug=reinforcement')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data.data')
            ->assertJsonPath('data.meta.current_page', 1)
            ->assertJsonPath('data.meta.last_page', 1)
            ->assertJsonPath('data.meta.total', 0);
    }

    #[DataProvider('unavailableTags')]
    public function test_unavailable_tag_returns_not_found(string $kind): void
    {
        if ($kind !== 'missing') {
            $this->createTag([
                'is_active' => $kind !== 'inactive',
                'blog_context' => $kind === 'holding' ? BlogContextEnum::HOLDING->value : BlogContextEnum::MARKETING->value,
            ]);
        }

        $this->getJson('/api/v1/blog/articles?tag_slug=reinforcement')
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null)
            ->assertJsonPath('message', trans_message('blog_cms.tag_not_found'));
    }

    public static function unavailableTags(): array
    {
        return [['missing'], ['inactive'], ['holding']];
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_query_returns_standard_validation_error(array $query, string $field): void
    {
        $this->getJson('/api/v1/blog/articles?'.http_build_query($query))
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null)
            ->assertJsonValidationErrors($field);
    }

    public static function invalidQueries(): array
    {
        return [
            'empty tag' => [['tag_slug' => ''], 'tag_slug'],
            'array tag' => [['tag_slug' => ['reinforcement']], 'tag_slug'],
            'invalid tag' => [['tag_slug' => 'with space'], 'tag_slug'],
            'oversized tag' => [['tag_slug' => str_repeat('a', 256)], 'tag_slug'],
            'zero page' => [['page' => 0], 'page'],
            'negative page' => [['page' => -1], 'page'],
            'decimal page' => [['page' => '1.5'], 'page'],
            'text page' => [['page' => 'NaN'], 'page'],
            'array page' => [['page' => [1]], 'page'],
            'zero limit' => [['per_page' => 0], 'per_page'],
            'excessive limit' => [['per_page' => 25], 'per_page'],
            'array limit' => [['per_page' => [12]], 'per_page'],
            'invalid category' => [['category_id' => -1], 'category_id'],
            'array search' => [['search' => ['query']], 'search'],
            'oversized search' => [['search' => str_repeat('a', 201)], 'search'],
        ];
    }

    private function createArticle(array $attributes = []): BlogArticle
    {
        $category = BlogCategory::query()->create([
            'blog_context' => $attributes['blog_context'] ?? BlogContextEnum::MARKETING->value,
            'name' => 'Материалы',
            'slug' => fake()->unique()->slug(),
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
            'title' => 'Поставка арматуры',
            'slug' => fake()->unique()->slug(),
            'excerpt' => 'Материалы на объекте',
            'content' => '<p>Проверка поставки.</p>',
            'status' => BlogArticleStatusEnum::PUBLISHED->value,
            'published_at' => now()->subDay(),
            'noindex' => false,
        ], $attributes));
    }

    private function createTag(array $attributes = []): BlogTag
    {
        return BlogTag::query()->create(array_merge([
            'blog_context' => BlogContextEnum::MARKETING->value,
            'name' => 'Арматура',
            'slug' => 'reinforcement',
            'is_active' => true,
        ], $attributes));
    }
}
