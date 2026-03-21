<?php

namespace App\BusinessModules\Enterprise\MultiOrganization\Website\Services;

use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSite;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\SiteContentBlock;
use App\Models\OrganizationGroup;
use App\Models\User;
use InvalidArgumentException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SiteManagementService
{
    public function __construct(
        private readonly ContentManagementService $contentService,
        private readonly AssetManagerService $assetService,
        private readonly SiteBuilderDataService $builderDataService
    ) {
    }

    public function getOrCreateHoldingLanding(OrganizationGroup $organizationGroup, User $creator): HoldingSite
    {
        $existingSite = HoldingSite::query()->where('organization_group_id', $organizationGroup->id)->first();

        if ($existingSite) {
            return $existingSite;
        }

        return $this->createHoldingLanding($organizationGroup, [], $creator);
    }

    public function createSite(OrganizationGroup $organizationGroup, array $data, User $creator): HoldingSite
    {
        return $this->createHoldingLanding($organizationGroup, $data, $creator);
    }

    public function createHoldingLanding(OrganizationGroup $organizationGroup, array $data, User $creator): HoldingSite
    {
        return DB::transaction(function () use ($organizationGroup, $data, $creator) {
            $site = HoldingSite::create([
                'organization_group_id' => $organizationGroup->id,
                'domain' => $this->normalizeDomain($data['domain'] ?? ($organizationGroup->slug . '.prohelper.pro')),
                'title' => $data['title'] ?? $organizationGroup->name,
                'description' => $data['description'] ?? ('Official website of ' . $organizationGroup->name),
                'theme_config' => $data['theme_config'] ?? $this->getDefaultThemeConfig(),
                'seo_meta' => $data['seo_meta'] ?? $this->getDefaultSeoMeta($organizationGroup),
                'analytics_config' => $data['analytics_config'] ?? [],
                'status' => 'draft',
                'is_active' => true,
                'created_by_user_id' => $creator->id,
                'updated_by_user_id' => $creator->id,
            ]);

            $this->createDefaultBlocks($site, $creator);

            return $site;
        });
    }

    public function updateSiteSettings(HoldingSite $site, array $data, User $user): bool
    {
        $themeConfig = array_merge($site->theme_config ?? [], $data['theme_config'] ?? []);
        $seoMeta = array_merge($site->seo_meta ?? [], $data['seo_meta'] ?? []);
        $analyticsConfig = array_merge($site->analytics_config ?? [], $data['analytics_config'] ?? []);

        $updateData = array_filter([
            'domain' => isset($data['domain']) ? $this->normalizeDomain((string) $data['domain']) : null,
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'logo_url' => $data['logo_url'] ?? null,
            'favicon_url' => $data['favicon_url'] ?? null,
            'theme_config' => isset($data['theme_config']) ? $themeConfig : null,
            'seo_meta' => isset($data['seo_meta']) ? $seoMeta : null,
            'analytics_config' => isset($data['analytics_config']) ? $analyticsConfig : null,
            'updated_by_user_id' => $user->id,
        ], static fn ($value) => $value !== null);

        $updated = $site->update($updateData);

        if ($updated) {
            $site->clearCache();
        }

        return $updated;
    }

    public function publishSite(HoldingSite $site, User $user): bool
    {
        if (!$site->canUserEdit($user)) {
            throw new \RuntimeException('Access denied for site publish.');
        }

        $validationErrors = $this->validateSiteForPublishing($site);
        if (!empty($validationErrors)) {
            throw new InvalidArgumentException(implode(' ', $validationErrors));
        }

        $snapshot = $this->builderDataService->buildPublicationSnapshot($site);

        return $site->publish($user, $snapshot);
    }

    public function getSiteByDomain(string $domain): ?HoldingSite
    {
        $normalizedDomain = trim(strtolower($domain));
        $normalizedDomain = preg_replace('#^https?://#', '', $normalizedDomain);
        $normalizedDomain = trim($normalizedDomain, '/');

        if ($normalizedDomain === '') {
            return null;
        }

        $cacheKey = 'site_by_domain:' . $normalizedDomain;

        return Cache::remember($cacheKey, 300, function () use ($normalizedDomain) {
            $site = HoldingSite::query()
                ->where('domain', $normalizedDomain)
                ->where('is_active', true)
                ->with(['organizationGroup.parentOrganization'])
                ->first();

            if ($site) {
                return $site;
            }

            $slug = str_replace('.prohelper.pro', '', $normalizedDomain);

            return HoldingSite::query()
                ->whereHas('organizationGroup', function ($query) use ($slug) {
                    $query->where('slug', $slug);
                })
                ->where('is_active', true)
                ->with(['organizationGroup.parentOrganization'])
                ->first();
        });
    }

    public function getHoldingLanding(OrganizationGroup $organizationGroup): ?HoldingSite
    {
        return HoldingSite::query()
            ->where('organization_group_id', $organizationGroup->id)
            ->where('is_active', true)
            ->first();
    }

    public function getHoldingLandingData(OrganizationGroup $organizationGroup): ?array
    {
        $site = $this->getHoldingLanding($organizationGroup);

        if (!$site) {
            return null;
        }

        return $this->builderDataService->getEditorPayload($site);
    }

    public function deleteSite(HoldingSite $site, User $user): bool
    {
        if (!$site->canUserEdit($user)) {
            throw new \RuntimeException('Access denied for site delete.');
        }

        return DB::transaction(function () use ($site) {
            foreach ($site->assets as $asset) {
                $this->assetService->deleteAsset($asset, true);
            }

            $site->contentBlocks()->delete();
            $site->leads()->delete();
            $site->clearCache();

            return $site->delete();
        });
    }

    private function createDefaultBlocks(HoldingSite $site, User $creator): void
    {
        $defaults = [
            ['block_type' => 'hero', 'sort_order' => 1],
            ['block_type' => 'stats', 'sort_order' => 2],
            ['block_type' => 'about', 'sort_order' => 3],
            ['block_type' => 'services', 'sort_order' => 4],
            ['block_type' => 'projects', 'sort_order' => 5],
            ['block_type' => 'team', 'sort_order' => 6],
            ['block_type' => 'lead_form', 'sort_order' => 7],
            ['block_type' => 'contacts', 'sort_order' => 8],
        ];

        foreach ($defaults as $definition) {
            $blockType = $definition['block_type'];
            $this->contentService->createBlock($site, [
                'block_type' => $blockType,
                'title' => SiteContentBlock::BLOCK_TYPES[$blockType] ?? ucfirst($blockType),
                'content' => SiteContentBlock::getDefaultContent($blockType),
                'settings' => SiteContentBlock::getDefaultSettings($blockType),
                'bindings' => SiteContentBlock::getDefaultBindings($blockType),
                'sort_order' => $definition['sort_order'],
                'is_active' => true,
            ], $creator);
        }
    }

    private function validateSiteForPublishing(HoldingSite $site): array
    {
        $errors = [];

        if (trim((string) $site->title) === '') {
            $errors[] = trans_message('holding_site_builder.site_title_required');
        }

        return $errors;
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = trim(strtolower($domain));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = trim($domain, '/');

        if (!str_contains($domain, '.')) {
            $domain .= '.prohelper.pro';
        }

        return $domain;
    }

    private function getDefaultThemeConfig(): array
    {
        return [
            'primary_color' => '#2563eb',
            'secondary_color' => '#64748b',
            'accent_color' => '#f59e0b',
            'background_color' => '#ffffff',
            'text_color' => '#111827',
            'font_family' => 'Inter, sans-serif',
            'font_size_base' => '16px',
            'border_radius' => '16px',
            'shadow_style' => 'soft',
        ];
    }

    private function getDefaultSeoMeta(OrganizationGroup $organizationGroup): array
    {
        return [
            'title' => $organizationGroup->name,
            'description' => 'Official website of ' . $organizationGroup->name,
            'keywords' => $organizationGroup->name,
            'og_title' => $organizationGroup->name,
            'og_description' => 'Official website of ' . $organizationGroup->name,
            'og_image' => null,
        ];
    }
}
