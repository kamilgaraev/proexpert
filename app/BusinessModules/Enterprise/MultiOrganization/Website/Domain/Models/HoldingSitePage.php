<?php

declare(strict_types=1);

namespace App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HoldingSitePage extends Model
{
    protected $table = 'holding_site_pages';

    protected $fillable = [
        'holding_site_id',
        'page_type',
        'slug',
        'navigation_label',
        'title',
        'description',
        'seo_meta',
        'layout_config',
        'locale_content',
        'visibility',
        'sort_order',
        'is_home',
        'is_active',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'seo_meta' => 'array',
        'layout_config' => 'array',
        'locale_content' => 'array',
        'is_home' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(HoldingSite::class, 'holding_site_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(SiteContentBlock::class, 'holding_site_page_id')->orderBy('sort_order');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function getNormalizedSlug(): string
    {
        if ($this->is_home) {
            return '/';
        }

        $slug = trim((string) $this->slug);
        if ($slug === '') {
            return '/';
        }

        return '/' . ltrim($slug, '/');
    }

    public function matchesPath(string $path): bool
    {
        $normalizedPath = trim($path);
        $normalizedPath = $normalizedPath === '' ? '/' : '/' . trim($normalizedPath, '/');

        return $normalizedPath === $this->getNormalizedSlug();
    }
}
