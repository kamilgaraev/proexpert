<?php

namespace App\Services\Blog;

use App\Models\Blog\BlogArticle;
use App\Models\LandingAdmin;
use App\Repositories\Blog\BlogArticleRepository;
use App\Repositories\Blog\BlogTagRepository;
use App\Enums\Blog\BlogArticleStatusEnum;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BlogArticleService
{
    public function __construct(
        private BlogArticleRepository $articleRepository,
        private BlogTagRepository $tagRepository
    ) {}

    public function createArticle(array $data, LandingAdmin $author): BlogArticle
    {
        return DB::transaction(function () use ($data, $author) {
            $articleData = $this->prepareArticleData($data, $author);
            
            $article = BlogArticle::create($articleData);
            
            if (isset($data['tags'])) {
                $this->syncTags($article, $data['tags']);
            }
            
            return $article->load(['category', 'author', 'tags']);
        });
    }

    public function updateArticle(BlogArticle $article, array $data): BlogArticle
    {
        return DB::transaction(function () use ($article, $data) {
            $articleData = $this->prepareArticleData($data);
            
            $article->update($articleData);
            
            if (isset($data['tags'])) {
                $this->syncTags($article, $data['tags']);
            }
            
            return $article->fresh(['category', 'author', 'tags']);
        });
    }

    public function publishArticle(BlogArticle $article, ?Carbon $publishAt = null): BlogArticle
    {
        $publishAt = $publishAt ?: now();
        
        $article->update([
            'status' => BlogArticleStatusEnum::PUBLISHED,
            'published_at' => $publishAt,
        ]);

        return $article;
    }

    public function scheduleArticle(BlogArticle $article, Carbon $scheduledAt): BlogArticle
    {
        $article->update([
            'status' => BlogArticleStatusEnum::SCHEDULED,
            'scheduled_at' => $scheduledAt,
        ]);

        return $article;
    }

    public function archiveArticle(BlogArticle $article): BlogArticle
    {
        $article->update([
            'status' => BlogArticleStatusEnum::ARCHIVED,
        ]);

        return $article;
    }

    public function duplicateArticle(BlogArticle $originalArticle, LandingAdmin $author): BlogArticle
    {
        $data = $originalArticle->toArray();
        
        unset($data['id'], $data['created_at'], $data['updated_at']);
        
        $data['title'] = $data['title'] . ' (копия)';
        $data['slug'] = null;
        $data['status'] = BlogArticleStatusEnum::DRAFT;
        $data['published_at'] = null;
        $data['scheduled_at'] = null;
        $data['views_count'] = 0;
        $data['likes_count'] = 0;
        $data['comments_count'] = 0;
        $data['author_id'] = $author->id;

        $newArticle = BlogArticle::create($data);
        
        $newArticle->tags()->sync($originalArticle->tags->pluck('id'));
        
        return $newArticle->load(['category', 'author', 'tags']);
    }

    public function processScheduledArticles(): int
    {
        $scheduledArticles = $this->articleRepository->getScheduledArticles()
            ->filter(fn($article) => $article->scheduled_at <= now());

        $publishedCount = 0;
        
        foreach ($scheduledArticles as $article) {
            $this->publishArticle($article, $article->scheduled_at);
            $publishedCount++;
        }

        return $publishedCount;
    }

    public function generateSlug(string $title): string
    {
        $baseSlug = Str::slug($title);
        $slug = $baseSlug;
        $counter = 1;

        while (BlogArticle::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    public function calculateReadingTime(string $content): int
    {
        $wordsPerMinute = 200;
        $wordCount = str_word_count(strip_tags($content));
        return max(1, ceil($wordCount / $wordsPerMinute));
    }

    public function generateSeoData(BlogArticle $article): array
    {
        $autoMeta = empty($article->meta_description);
        
        return [
            'meta_title' => $article->meta_title ?: $article->title,
            'meta_description' => $article->meta_description ?: 
                Str::limit(strip_tags($article->excerpt ?: $article->content), 160),
            'og_title' => $article->og_title ?: $article->title,
            'og_description' => $article->og_description ?: 
                Str::limit(strip_tags($article->excerpt ?: $article->content), 200),
            'structured_data' => $this->generateStructuredData($article),
        ];
    }

    private function prepareArticleData(array $data, ?LandingAdmin $author = null): array
    {
        if (isset($data['title']) && empty($data['slug'])) {
            $data['slug'] = $this->generateSlug($data['title']);
        }

        if (isset($data['content'])) {
            $data['reading_time'] = $this->calculateReadingTime($data['content']);
        }

        if ($author) {
            $data['author_id'] = $author->id;
        }

        if (isset($data['status']) && $data['status'] === BlogArticleStatusEnum::PUBLISHED->value) {
            $data['published_at'] = $data['published_at'] ?? now();
        }

        return $data;
    }

    private function syncTags(BlogArticle $article, array $tagNames): void
    {
        $tagIds = [];
        
        foreach ($tagNames as $tagName) {
            if (is_string($tagName)) {
                $tag = $this->tagRepository->getOrCreateByName($tagName);
                $tagIds[] = $tag->id;
            } elseif (is_numeric($tagName)) {
                $tagIds[] = $tagName;
            }
        }

        $article->tags()->sync($tagIds);
        
        foreach ($tagIds as $tagId) {
            $tag = $this->tagRepository->find($tagId);
            if ($tag) {
                $tag->update(['usage_count' => $tag->articles()->count()]);
            }
        }
    }

    private function generateStructuredData(BlogArticle $article): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => $article->title,
            'description' => $article->meta_description ?: Str::limit(strip_tags($article->content), 160),
            'image' => $article->featured_image ? url($article->featured_image) : null,
            'author' => [
                '@type' => 'Person',
                'name' => $article->author->name,
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name' => config('app.name'),
            ],
            'datePublished' => $article->published_at?->toISOString(),
            'dateModified' => $article->updated_at->toISOString(),
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => url($article->url),
            ],
        ];
    }
} 