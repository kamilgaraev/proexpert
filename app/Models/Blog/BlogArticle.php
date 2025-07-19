<?php

namespace App\Models\Blog;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\LandingAdmin;
use App\Enums\Blog\BlogArticleStatusEnum;
use Illuminate\Support\Str;
use Carbon\Carbon;

class BlogArticle extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'author_id',
        'title',
        'slug',
        'excerpt',
        'content',
        'featured_image',
        'gallery_images',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'og_title',
        'og_description',
        'og_image',
        'structured_data',
        'status',
        'published_at',
        'scheduled_at',
        'views_count',
        'likes_count',
        'comments_count',
        'reading_time',
        'is_featured',
        'allow_comments',
        'is_published_in_rss',
        'noindex',
        'sort_order',
    ];

    protected $casts = [
        'gallery_images' => 'array',
        'meta_keywords' => 'array',
        'structured_data' => 'array',
        'status' => BlogArticleStatusEnum::class,
        'published_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'views_count' => 'integer',
        'likes_count' => 'integer',
        'comments_count' => 'integer',
        'reading_time' => 'integer',
        'is_featured' => 'boolean',
        'allow_comments' => 'boolean',
        'is_published_in_rss' => 'boolean',
        'noindex' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(BlogCategory::class, 'category_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(LandingAdmin::class, 'author_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(BlogTag::class, 'blog_article_tag', 'article_id', 'tag_id')
            ->withTimestamps();
    }

    public function comments(): HasMany
    {
        return $this->hasMany(BlogComment::class, 'article_id');
    }

    public function approvedComments(): HasMany
    {
        return $this->comments()->where('status', 'approved');
    }

    public function setTitleAttribute($value): void
    {
        $this->attributes['title'] = $value;
        if (empty($this->attributes['slug'])) {
            $this->attributes['slug'] = Str::slug($value);
        }
    }

    public function scopePublished($query)
    {
        return $query->where('status', BlogArticleStatusEnum::PUBLISHED)
            ->where('published_at', '<=', now());
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeScheduled($query)
    {
        return $query->where('status', BlogArticleStatusEnum::SCHEDULED)
            ->where('scheduled_at', '>', now());
    }

    public function scopeByCategory($query, $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    public function scopeByTag($query, $tagId)
    {
        return $query->whereHas('tags', function ($q) use ($tagId) {
            $q->where('blog_tags.id', $tagId);
        });
    }

    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('title', 'like', "%{$search}%")
                ->orWhere('content', 'like', "%{$search}%")
                ->orWhere('excerpt', 'like', "%{$search}%");
        });
    }

    public function getIsPublishedAttribute(): bool
    {
        return $this->status === BlogArticleStatusEnum::PUBLISHED && 
               $this->published_at <= now();
    }

    public function getReadablePublishedAtAttribute(): string
    {
        return $this->published_at ? $this->published_at->format('d.m.Y H:i') : '';
    }

    public function getEstimatedReadingTimeAttribute(): int
    {
        if ($this->reading_time) {
            return $this->reading_time;
        }
        
        $wordsPerMinute = 200;
        $wordCount = str_word_count(strip_tags($this->content));
        return max(1, ceil($wordCount / $wordsPerMinute));
    }

    public function incrementViews(): void
    {
        $this->increment('views_count');
    }

    public function getUrlAttribute(): string
    {
        return "/blog/{$this->slug}";
    }

    public function getMetaTitleAttribute($value): string
    {
        return $value ?: $this->title;
    }

    public function getMetaDescriptionAttribute($value): string
    {
        return $value ?: Str::limit(strip_tags($this->excerpt ?: $this->content), 160);
    }
} 