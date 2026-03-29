<?php

declare(strict_types=1);

namespace App\BusinessModules\Enterprise\MultiOrganization\Website\Services;

use App\Enums\Blog\BlogContextEnum;
use App\Models\Blog\BlogArticle;
use App\Models\Blog\BlogCategory;
use App\Models\OrganizationGroup;
use App\Models\User;
use Illuminate\Support\Str;

class HoldingSiteBlogService
{
    public function listArticles(OrganizationGroup $group, bool $onlyPublished = false): array
    {
        $query = BlogArticle::query()
            ->with('category:id,name,slug')
            ->where('blog_context', BlogContextEnum::HOLDING->value)
            ->where('organization_group_id', $group->id)
            ->orderByDesc('published_at')
            ->orderByDesc('updated_at');

        if ($onlyPublished) {
            $query->where('status', 'published')
                ->whereNotNull('published_at')
                ->where('published_at', '<=', now());
        }

        return $query->get()
            ->map(fn (BlogArticle $article) => $this->serializeArticle($article))
            ->values()
            ->all();
    }

    public function createArticle(OrganizationGroup $group, array $data, User $user): BlogArticle
    {
        $category = $this->resolveCategory($group, $data['category_id'] ?? null);

        return BlogArticle::create([
            'organization_group_id' => $group->id,
            'blog_context' => BlogContextEnum::HOLDING->value,
            'category_id' => $category->id,
            'author_id' => null,
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
            'title' => $data['title'],
            'slug' => $this->generateUniqueSlug((string) ($data['slug'] ?? $data['title'])),
            'excerpt' => $data['excerpt'] ?? null,
            'content' => $data['content'] ?? '',
            'featured_image' => $data['featured_image'] ?? null,
            'gallery_images' => $data['gallery_images'] ?? [],
            'meta_title' => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'meta_keywords' => $data['meta_keywords'] ?? [],
            'og_title' => $data['og_title'] ?? null,
            'og_description' => $data['og_description'] ?? null,
            'og_image' => $data['og_image'] ?? null,
            'structured_data' => $data['structured_data'] ?? [],
            'status' => $data['status'] ?? 'draft',
            'published_at' => ($data['status'] ?? 'draft') === 'published' ? now() : null,
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'reading_time' => $this->estimateReadingTime((string) ($data['content'] ?? '')),
            'is_featured' => $data['is_featured'] ?? false,
            'allow_comments' => $data['allow_comments'] ?? false,
            'is_published_in_rss' => false,
            'noindex' => $data['noindex'] ?? false,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
    }

    public function updateArticle(BlogArticle $article, array $data, User $user): BlogArticle
    {
        $category = null;

        if (array_key_exists('category_id', $data)) {
            $category = $this->resolveCategory($article->organizationGroup, $data['category_id']);
        }

        $article->update(array_filter([
            'category_id' => $category?->id,
            'updated_by_user_id' => $user->id,
            'title' => $data['title'] ?? null,
            'slug' => array_key_exists('slug', $data) ? $this->generateUniqueSlug((string) $data['slug'], $article->id) : null,
            'excerpt' => $data['excerpt'] ?? null,
            'content' => $data['content'] ?? null,
            'featured_image' => $data['featured_image'] ?? null,
            'gallery_images' => $data['gallery_images'] ?? null,
            'meta_title' => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'meta_keywords' => $data['meta_keywords'] ?? null,
            'og_title' => $data['og_title'] ?? null,
            'og_description' => $data['og_description'] ?? null,
            'og_image' => $data['og_image'] ?? null,
            'status' => $data['status'] ?? null,
            'published_at' => isset($data['status']) && $data['status'] === 'published'
                ? ($article->published_at ?? now())
                : (array_key_exists('published_at', $data) ? $data['published_at'] : null),
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'reading_time' => array_key_exists('content', $data)
                ? $this->estimateReadingTime((string) $data['content'])
                : null,
            'is_featured' => $data['is_featured'] ?? null,
            'allow_comments' => $data['allow_comments'] ?? null,
            'noindex' => $data['noindex'] ?? null,
            'sort_order' => $data['sort_order'] ?? null,
        ], static fn ($value) => $value !== null));

        return $article->fresh('category');
    }

    public function deleteArticle(BlogArticle $article): void
    {
        $article->delete();
    }

    public function findPublishedArticleBySlug(OrganizationGroup $group, string $slug): ?BlogArticle
    {
        return BlogArticle::query()
            ->with('category:id,name,slug')
            ->where('blog_context', BlogContextEnum::HOLDING->value)
            ->where('organization_group_id', $group->id)
            ->where('slug', $slug)
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->first();
    }

    public function serializeArticle(BlogArticle $article): array
    {
        return [
            'id' => $article->id,
            'title' => $article->title,
            'slug' => $article->slug,
            'excerpt' => $article->excerpt,
            'content' => $article->content,
            'featured_image' => $article->featured_image,
            'meta_title' => $article->meta_title,
            'meta_description' => $article->meta_description,
            'meta_keywords' => $article->meta_keywords ?? [],
            'status' => is_object($article->status) && property_exists($article->status, 'value')
                ? $article->status->value
                : (string) $article->status,
            'published_at' => optional($article->published_at)->toISOString(),
            'reading_time' => $article->reading_time,
            'is_featured' => $article->is_featured,
            'category' => [
                'id' => $article->category?->id,
                'name' => $article->category?->name,
                'slug' => $article->category?->slug,
            ],
            'author' => [
                'id' => $article->author?->id,
                'name' => $article->author?->name,
            ],
        ];
    }

    public function defaultCategory(OrganizationGroup $group): BlogCategory
    {
        return $this->resolveCategory($group, null);
    }

    private function resolveCategory(OrganizationGroup $group, ?int $categoryId): BlogCategory
    {
        if ($categoryId) {
            $category = BlogCategory::query()
                ->where('blog_context', BlogContextEnum::HOLDING->value)
                ->where('organization_group_id', $group->id)
                ->where('id', $categoryId)
                ->first();

            if ($category instanceof BlogCategory) {
                return $category;
            }
        }

        return BlogCategory::firstOrCreate(
            [
                'organization_group_id' => $group->id,
                'blog_context' => BlogContextEnum::HOLDING->value,
                'slug' => 'holding-news',
            ],
            [
                'name' => 'Новости холдинга',
                'description' => 'Публикации холдинга',
                'sort_order' => 1,
                'is_active' => true,
            ]
        );
    }

    private function generateUniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($source);
        $baseSlug = $baseSlug === '' ? 'article' : $baseSlug;
        $slug = $baseSlug;
        $counter = 1;

        while (
            BlogArticle::query()
                ->where('blog_context', BlogContextEnum::HOLDING->value)
                ->where('slug', $slug)
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $counter++;
            $slug = sprintf('%s-%d', $baseSlug, $counter);
        }

        return $slug;
    }

    private function estimateReadingTime(string $content): int
    {
        $words = str_word_count(strip_tags($content));

        return max(1, (int) ceil($words / 200));
    }
}
