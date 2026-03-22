<?php

declare(strict_types=1);

namespace App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models;

use App\Models\OrganizationGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class HoldingSite extends Model
{
    protected $table = 'holding_sites';

    protected $fillable = [
        'organization_group_id',
        'domain',
        'default_locale',
        'enabled_locales',
        'title',
        'description',
        'logo_url',
        'favicon_url',
        'theme_config',
        'seo_meta',
        'analytics_config',
        'published_payload',
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
        'published_payload' => 'array',
        'enabled_locales' => 'array',
        'is_active' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function organizationGroup(): BelongsTo
    {
        return $this->belongsTo(OrganizationGroup::class);
    }

    public function contentBlocks(): HasMany
    {
        return $this->hasMany(SiteContentBlock::class)->orderBy('sort_order');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(HoldingSitePage::class)->orderBy('sort_order');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(HoldingSiteRevision::class)->latest('created_at');
    }

    public function collaborators(): HasMany
    {
        return $this->hasMany(HoldingSiteCollaborator::class);
    }

    public function publishedBlocks(): HasMany
    {
        return $this->contentBlocks()
            ->where('status', 'published')
            ->where('is_active', true);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(SiteAsset::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(HoldingSiteLead::class, 'holding_site_id')->latest('submitted_at');
    }

    public function homePage(): ?HoldingSitePage
    {
        return $this->pages()->where('is_home', true)->first();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function publish(User $user, ?array $snapshot = null): bool
    {
        $snapshot = $snapshot ?? $this->published_payload ?? [];

        $this->update([
            'status' => 'published',
            'published_payload' => $snapshot,
            'published_at' => now(),
            'updated_by_user_id' => $user->id,
        ]);

        $this->contentBlocks()->update([
            'status' => 'published',
            'published_at' => now(),
            'updated_by_user_id' => $user->id,
        ]);

        $this->clearCache();

        return true;
    }

    public function hasPublishedSnapshot(): bool
    {
        return is_array($this->published_payload) && !empty($this->published_payload);
    }

    public function getPublishedPayload(): array
    {
        return $this->published_payload ?? [];
    }

    public function clearCache(): void
    {
        $keys = [
            "holding_site_data:{$this->id}",
            "holding_site_published:{$this->id}",
            "site_data_{$this->id}",
            "site_blocks_{$this->id}",
            'site_by_domain:' . $this->getDomain(),
        ];

        foreach ($keys as $key) {
            Cache::forget($key);
        }

        try {
            if (method_exists(Cache::getStore(), 'tags')) {
                Cache::tags(['holding_sites', "site_{$this->id}"])->flush();
            }
        } catch (\Throwable $e) {
            Log::warning('Holding site cache clear failed', [
                'site_id' => $this->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function canUserEdit(User $user): bool
    {
        return $this->userIsOwnerByOrganization($user)
            || $this->hasCollaboratorRole($user, [
                HoldingSiteCollaborator::ROLE_OWNER,
                HoldingSiteCollaborator::ROLE_EDITOR,
                HoldingSiteCollaborator::ROLE_PUBLISHER,
            ]);
    }

    public function canUserView(User $user): bool
    {
        return $this->userIsOwnerByOrganization($user)
            || $this->hasCollaboratorRole($user, HoldingSiteCollaborator::ROLES);
    }

    public function canUserPublish(User $user): bool
    {
        return $this->userIsOwnerByOrganization($user)
            || $this->hasCollaboratorRole($user, [
                HoldingSiteCollaborator::ROLE_OWNER,
                HoldingSiteCollaborator::ROLE_PUBLISHER,
            ]);
    }

    public function canManageCollaborators(User $user): bool
    {
        return $this->userIsOwnerByOrganization($user)
            || $this->hasCollaboratorRole($user, [HoldingSiteCollaborator::ROLE_OWNER]);
    }

    public function getDomain(): string
    {
        $domain = $this->domain ?: ($this->organizationGroup->slug . '.prohelper.pro');

        if (str_contains($domain, '.')) {
            return $domain;
        }

        return $domain . '.prohelper.pro';
    }

    public function getUrl(): string
    {
        return 'https://' . $this->getDomain();
    }

    public function isPublished(): bool
    {
        return $this->status === 'published' && $this->is_active && $this->hasPublishedSnapshot();
    }

    public function getEnabledLocales(): array
    {
        $locales = $this->enabled_locales ?? [];

        if (!is_array($locales) || empty($locales)) {
            return [$this->default_locale ?: 'ru'];
        }

        return array_values(array_unique(array_filter($locales, static fn ($value) => is_string($value) && $value !== '')));
    }

    public function getPreviewUrl(): string
    {
        return $this->getUrl() . '?preview=true&token=' . $this->generatePreviewToken();
    }

    public function isValidPreviewToken(string $token): bool
    {
        return hash_equals($this->generatePreviewToken(), $token);
    }

    private function generatePreviewToken(): string
    {
        $updatedAt = $this->updated_at?->timestamp ?? now()->timestamp;

        return hash('sha256', $this->id . $updatedAt . config('app.key'));
    }

    private function userIsOwnerByOrganization(User $user): bool
    {
        $parentOrganization = $this->organizationGroup->parentOrganization;

        return $parentOrganization->users()
            ->wherePivot('user_id', $user->id)
            ->wherePivot('is_owner', true)
            ->exists();
    }

    private function hasCollaboratorRole(User $user, array $roles): bool
    {
        return $this->collaborators()
            ->where('user_id', $user->id)
            ->whereIn('role', $roles)
            ->exists();
    }
}
