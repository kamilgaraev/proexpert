<?php

declare(strict_types=1);

namespace App\Models\Blog;

use App\Enums\Blog\BlogArticleStatusEnum;
use App\Enums\Blog\BlogContextEnum;
use App\Models\LandingAdmin;
use App\Models\OrganizationGroup;
use App\Models\SystemAdmin;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class BlogArticle extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_group_id',
        'blog_context',
        'category_id',
        'author_id',
        'author_system_admin_id',
        'created_by_user_id',
        'updated_by_user_id',
        'last_edited_by_system_admin_id',
        'title',
        'slug',
        'excerpt',
        'content',
        'editor_document',
        'editor_version',
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
        'last_autosaved_at',
    ];

    protected $casts = [
        'blog_context' => BlogContextEnum::class,
        'editor_document' => 'array',
        'gallery_images' => 'array',
        'meta_keywords' => 'array',
        'structured_data' => 'array',
        'status' => BlogArticleStatusEnum::class,
        'published_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'last_autosaved_at' => 'datetime',
        'views_count' => 'integer',
        'likes_count' => 'integer',
        'comments_count' => 'integer',
        'reading_time' => 'integer',
        'editor_version' => 'integer',
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

    public function organizationGroup(): BelongsTo
    {
        return $this->belongsTo(OrganizationGroup::class, 'organization_group_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(LandingAdmin::class, 'author_id');
    }

    public function systemAuthor(): BelongsTo
    {
        return $this->belongsTo(SystemAdmin::class, 'author_system_admin_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function lastEditedBySystemAdmin(): BelongsTo
    {
        return $this->belongsTo(SystemAdmin::class, 'last_edited_by_system_admin_id');
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

    public function revisions(): HasMany
    {
        return $this->hasMany(BlogArticleRevision::class, 'article_id');
    }

    public function setTitleAttribute(string $value): void
    {
        $this->attributes['title'] = $value;

        if (empty($this->attributes['slug'])) {
            $this->attributes['slug'] = Str::slug($value);
        }
    }

    public function scopeMarketing($query)
    {
        return $query->where('blog_context', BlogContextEnum::MARKETING->value);
    }

    public function scopeHolding($query)
    {
        return $query->where('blog_context', BlogContextEnum::HOLDING->value);
    }

    public function scopePublished($query)
    {
        return $query->where('status', BlogArticleStatusEnum::PUBLISHED)
            ->where(function ($builder): void {
                $builder->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            });
    }

    public function scopeForOrganizationGroup($query, int $organizationGroupId)
    {
        return $query->where('organization_group_id', $organizationGroupId);
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

    public function scopeByCategory($query, int $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    public function scopeByTag($query, int $tagId)
    {
        return $query->whereHas('tags', function ($builder) use ($tagId): void {
            $builder->where('blog_tags.id', $tagId);
        });
    }

    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($builder) use ($search): void {
            $builder->where('title', 'like', '%' . $search . '%')
                ->orWhere('content', 'like', '%' . $search . '%')
                ->orWhere('excerpt', 'like', '%' . $search . '%')
                ->orWhereHas('tags', function ($tagQuery) use ($search): void {
                    $tagQuery->where('blog_tags.name', 'like', '%' . ltrim($search, '#') . '%');
                });
        });
    }

    public function getIsPublishedAttribute(): bool
    {
        if ($this->status !== BlogArticleStatusEnum::PUBLISHED) {
            return false;
        }

        return $this->published_at === null || $this->published_at->lte(now());
    }

    public function getReadablePublishedAtAttribute(): string
    {
        return $this->published_at?->format('d.m.Y H:i') ?? '';
    }

    public function getEstimatedReadingTimeAttribute(): int
    {
        if ($this->reading_time) {
            return $this->reading_time;
        }

        $wordCount = str_word_count(strip_tags((string) $this->content));

        return max(1, (int) ceil($wordCount / 200));
    }

    public function incrementViews(): void
    {
        $this->increment('views_count');
    }

    public function getUrlAttribute(): string
    {
        return '/blog/' . $this->slug;
    }

    public function getMetaTitleAttribute(?string $value): string
    {
        return $value ?: (string) $this->title;
    }

    public function getMetaDescriptionAttribute(?string $value): string
    {
        return $value ?: Str::limit(strip_tags((string) ($this->excerpt ?: $this->content)), 160);
    }

    public function getAuthorLabelAttribute(): string
    {
        return $this->systemAuthor?->name
            ?? $this->author?->name
            ?? 'ProHelper Team';
    }

    public function getAuthorEmailAttribute(): ?string
    {
        return $this->systemAuthor?->email ?? $this->author?->email;
    }

    public function getPublishTimestamp(): ?CarbonInterface
    {
        return $this->published_at;
    }
}
