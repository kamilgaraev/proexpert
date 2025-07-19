<?php

namespace App\Models\Blog;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class BlogTag extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'color',
        'usage_count',
        'is_active',
    ];

    protected $casts = [
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

    public function setNameAttribute($value): void
    {
        $this->attributes['name'] = $value;
        if (empty($this->attributes['slug'])) {
            $this->attributes['slug'] = Str::slug($value);
        }
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePopular($query)
    {
        return $query->orderBy('usage_count', 'desc');
    }

    public function incrementUsage(): void
    {
        $this->increment('usage_count');
    }

    public function decrementUsage(): void
    {
        $this->decrement('usage_count');
    }
} 