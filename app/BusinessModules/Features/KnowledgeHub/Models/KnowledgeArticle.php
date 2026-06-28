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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class KnowledgeArticle extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'parent_id',
        'kind',
        'status',
        'title',
        'slug',
        'excerpt',
        'content',
        'content_plain_text',
        'tags',
        'audiences',
        'surfaces',
        'module_slugs',
        'permission_keys',
        'context_keys',
        'release_version',
        'release_date',
        'published_at',
        'reading_time',
        'is_featured',
        'is_pinned',
        'help_priority',
        'depth',
        'path',
        'sort_order',
        'created_by_system_admin_id',
        'updated_by_system_admin_id',
    ];

    protected $casts = [
        'kind' => KnowledgeArticleKind::class,
        'status' => KnowledgeArticleStatus::class,
        'tags' => 'array',
        'audiences' => 'array',
        'surfaces' => 'array',
        'module_slugs' => 'array',
        'permission_keys' => 'array',
        'context_keys' => 'array',
        'release_date' => 'date',
        'published_at' => 'datetime',
        'reading_time' => 'integer',
        'is_featured' => 'boolean',
        'is_pinned' => 'boolean',
        'help_priority' => 'integer',
        'depth' => 'integer',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $article): void {
            $article->syncHierarchyFields();
        });

        static::created(function (self $article): void {
            if ($article->parent_id === null || ! str_ends_with((string) $article->path, '.new')) {
                return;
            }

            $article->forceFill([
                'path' => preg_replace('/\.new$/', '.'.$article->getKey(), (string) $article->path),
            ])->saveQuietly();
        });

        static::saved(function (self $article): void {
            if (! $article->wasChanged(['parent_id', 'depth', 'path'])) {
                return;
            }

            $article->children()
                ->get()
                ->each(fn (self $child): bool => $child->save());
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(KnowledgeCategory::class, 'category_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->orderBy('sort_order')
            ->orderBy('title');
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(KnowledgeArticleFeedback::class, 'article_id');
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

    public function setContentAttribute(?string $value): void
    {
        $this->attributes['content'] = $value;
        $plainText = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $value)));
        $this->attributes['content_plain_text'] = $plainText !== '' ? $plainText : null;
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

    public function scopeRoot(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
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

    private function syncHierarchyFields(): void
    {
        if ($this->parent_id === null) {
            $this->attributes['depth'] = 0;
            $this->attributes['path'] = null;

            return;
        }

        if ($this->exists && (int) $this->parent_id === (int) $this->getKey()) {
            $this->attributes['parent_id'] = null;
            $this->attributes['depth'] = 0;
            $this->attributes['path'] = null;

            return;
        }

        $parent = self::query()
            ->select(['id', 'depth', 'path'])
            ->find($this->parent_id);

        if ($parent === null) {
            $this->attributes['parent_id'] = null;
            $this->attributes['depth'] = 0;
            $this->attributes['path'] = null;

            return;
        }

        $parentPath = $parent->path !== null && $parent->path !== ''
            ? $parent->path
            : (string) $parent->id;

        if ($this->exists && str_contains('.'.$parentPath.'.', '.'.$this->getKey().'.')) {
            $this->attributes['parent_id'] = null;
            $this->attributes['depth'] = 0;
            $this->attributes['path'] = null;

            return;
        }

        $this->attributes['depth'] = min(((int) $parent->depth) + 1, 12);
        $this->attributes['path'] = $parentPath.'.'.($this->exists ? (string) $this->getKey() : 'new');
    }
}
