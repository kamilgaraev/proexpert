<?php

declare(strict_types=1);

namespace App\Models\Blog;

use App\Enums\Blog\BlogContextEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class BlogTag extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_group_id',
        'blog_context',
        'name',
        'slug',
        'description',
        'color',
        'usage_count',
        'is_active',
    ];

    protected $casts = [
        'blog_context' => BlogContextEnum::class,
        'is_active' => 'boolean',
        'usage_count' => 'integer',
    ];

    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(BlogArticle::class, 'blog_article_tag', 'tag_id', 'article_id')
            ->withTimestamps();
    }

    public function publishedArticles(): BelongsToMany
    {
        return $this->articles()->where('blog_articles.status', 'published');
    }

    public function setNameAttribute(string $value): void
    {
        $this->attributes['name'] = $value;

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

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePopular($query)
    {
        return $query->orderBy('usage_count', 'desc');
    }
}
