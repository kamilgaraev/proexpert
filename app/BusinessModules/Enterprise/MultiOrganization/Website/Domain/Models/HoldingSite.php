<?php

namespace App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use App\Models\OrganizationGroup;
use App\Models\User;
use Carbon\Carbon;

/**
 * Доменная модель сайта холдинга
 */
class HoldingSite extends Model
{
    protected $table = 'holding_sites';

    protected $fillable = [
        'organization_group_id',
        'domain',
        'title',
        'description',
        'logo_url',
        'favicon_url',
        'template_id',
        'theme_config',
        'seo_meta',
        'analytics_config',
        'status',
        'is_active',
        'published_at',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'theme_config' => 'array',
        'seo_meta' => 'array',
        'analytics_config' => 'array',
        'is_active' => 'boolean',
        'published_at' => 'datetime',
    ];

    /**
     * Холдинг-владелец сайта
     */
    public function organizationGroup(): BelongsTo
    {
        return $this->belongsTo(OrganizationGroup::class);
    }

    /**
     * Блоки контента сайта
     */
    public function contentBlocks(): HasMany
    {
        return $this->hasMany(SiteContentBlock::class)->orderBy('sort_order');
    }

    /**
     * Активные опубликованные блоки
     */
    public function publishedBlocks(): HasMany
    {
        return $this->contentBlocks()
            ->where('status', 'published')
            ->where('is_active', true);
    }

    /**
     * Файлы и медиа
     */
    public function assets(): HasMany
    {
        return $this->hasMany(SiteAsset::class);
    }

    /**
     * Создатель сайта
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Последний редактор
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * Получить полную структуру сайта для рендеринга
     */
    public function getFullSiteData(): array
    {
        $cacheKey = "holding_site_data:{$this->id}:{$this->updated_at->timestamp}";
        
        return Cache::remember($cacheKey, 3600, function () {
            return [
                'site' => [
                    'id' => $this->id,
                    'domain' => $this->domain,
                    'title' => $this->title,
                    'description' => $this->description,
                    'logo_url' => $this->logo_url,
                    'favicon_url' => $this->favicon_url,
                    'template_id' => $this->template_id,
                    'theme_config' => $this->theme_config,
                    'seo_meta' => $this->seo_meta,
                    'analytics_config' => $this->analytics_config,
                ],
                'blocks' => $this->publishedBlocks()
                    ->with('assets')
                    ->get()
                    ->map(fn($block) => $block->toPublicArray())
                    ->toArray(),
                'organization' => [
                    'name' => $this->organizationGroup->name,
                    'slug' => $this->organizationGroup->slug,
                ],
                'last_updated' => $this->updated_at->toISOString(),
            ];
        });
    }

    /**
     * Опубликовать сайт
     */
    public function publish(User $user): bool
    {
        $this->update([
            'status' => 'published',
            'published_at' => now(),
            'updated_by_user_id' => $user->id,
        ]);

        // Публикуем все черновые блоки
        $this->contentBlocks()
            ->where('status', 'draft')
            ->update([
                'status' => 'published',
                'published_at' => now(),
                'updated_by_user_id' => $user->id,
            ]);

        $this->clearCache();
        
        return true;
    }

    /**
     * Очистить кэш сайта
     */
    public function clearCache(): void
    {
        Cache::forget("holding_site_data:{$this->id}:{$this->updated_at->timestamp}");
        Cache::tags(['holding_sites', "site_{$this->id}"])->flush();
    }

    /**
     * Проверить может ли пользователь редактировать сайт
     */
    public function canUserEdit(User $user): bool
    {
        $organizationGroup = $this->organizationGroup;
        $parentOrganization = $organizationGroup->parentOrganization;
        
        // Проверяем является ли пользователь владельцем родительской организации
        return $parentOrganization->users()
            ->wherePivot('user_id', $user->id)
            ->wherePivot('is_owner', true)
            ->exists();
    }

    /**
     * Получить URL сайта
     */
    public function getUrl(): string
    {
        return "https://{$this->domain}";
    }

    /**
     * Проверить статус публикации
     */
    public function isPublished(): bool
    {
        return $this->status === 'published' && $this->is_active;
    }

    /**
     * Получить превью URL для черновика
     */
    public function getPreviewUrl(): string
    {
        return $this->getUrl() . '?preview=true&token=' . $this->generatePreviewToken();
    }

    /**
     * Генерировать токен для превью
     */
    private function generatePreviewToken(): string
    {
        return hash('sha256', $this->id . $this->updated_at->timestamp . config('app.key'));
    }

    /**
     * Проверить токен превью
     */
    public function isValidPreviewToken(string $token): bool
    {
        return hash_equals($this->generatePreviewToken(), $token);
    }
}
