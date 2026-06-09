<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\KnowledgeHub\Models;

use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeArticleKind;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeArticleStatus;
use App\Models\SystemAdmin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class KnowledgeArticle extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'kind',
        'status',
        'title',
        'slug',
        'excerpt',
        'content',
        'tags',
        'release_version',
        'release_date',
        'published_at',
        'reading_time',
        'is_featured',
        'sort_order',
        'created_by_system_admin_id',
        'updated_by_system_admin_id',
    ];

    protected $casts = [
        'kind' => KnowledgeArticleKind::class,
        'status' => KnowledgeArticleStatus::class,
        'tags' => 'array',
        'release_date' => 'date',
        'published_at' => 'datetime',
        'reading_time' => 'integer',
        'is_featured' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(KnowledgeCategory::class, 'category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(SystemAdmin::class, 'created_by_system_admin_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(SystemAdmin::class, 'updated_by_system_admin_id');
    }

    public function setTitleAttribute(string $value): void
    {
        $this->attributes['title'] = $value;

        if (empty($this->attributes['slug'])) {
            $this->attributes['slug'] = Str::slug($value);
        }
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', KnowledgeArticleStatus::PUBLISHED->value)
            ->where(function (Builder $builder): void {
                $builder->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            });
    }

    public function scopeKnowledge(Builder $query): Builder
    {
        return $query->where('kind', '!=', KnowledgeArticleKind::CHANGELOG->value);
    }

    public function scopeChangelog(Builder $query): Builder
    {
        return $query->where('kind', KnowledgeArticleKind::CHANGELOG->value);
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        $needle = '%'.mb_strtolower($search).'%';

        return $query->where(function (Builder $builder) use ($needle): void {
            $builder
                ->whereRaw('LOWER(title) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(COALESCE(excerpt, \'\')) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(COALESCE(content, \'\')) LIKE ?', [$needle]);
        });
    }
}
