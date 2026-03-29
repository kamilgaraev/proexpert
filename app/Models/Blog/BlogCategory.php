<?php

declare(strict_types=1);

namespace App\Models\Blog;

use App\Enums\Blog\BlogContextEnum;
use App\Models\OrganizationGroup;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class BlogCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_group_id',
        'blog_context',
        'name',
        'slug',
        'description',
        'meta_title',
        'meta_description',
        'color',
        'image',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'blog_context' => BlogContextEnum::class,
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function articles(): HasMany
    {
        return $this->hasMany(BlogArticle::class, 'category_id');
    }

    public function organizationGroup(): BelongsTo
    {
        return $this->belongsTo(OrganizationGroup::class, 'organization_group_id');
    }

    public function publishedArticles(): HasMany
    {
        return $this->articles()->where('status', 'published');
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

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function getArticlesCountAttribute(): int
    {
        return $this->articles()->count();
    }

    public function getPublishedArticlesCountAttribute(): int
    {
        return $this->publishedArticles()->count();
    }
}
