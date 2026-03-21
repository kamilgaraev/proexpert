<?php

declare(strict_types=1);

namespace App\BusinessModules\Enterprise\MultiOrganization\Website\Services;

use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSite;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\SiteAsset;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\SiteContentBlock;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class SiteBuilderDataService
{
    public function getEditorPayload(HoldingSite $site): array
    {
        return [
            'site' => $this->serializeSite($site),
            'blocks' => $this->getEditorBlocks($site),
            'assets' => $this->serializeAssets($site),
            'templates' => $this->getTemplatePresets(),
            'summary' => [
                'blocks_count' => $site->contentBlocks()->count(),
                'active_blocks_count' => $site->contentBlocks()->where('is_active', true)->count(),
                'assets_count' => $site->assets()->count(),
                'leads_count' => $site->leads()->count(),
                'last_published_at' => optional($site->published_at)?->toISOString(),
            ],
            'publication' => [
                'status' => $site->status,
                'is_published' => $site->isPublished(),
                'published_at' => optional($site->published_at)?->toISOString(),
                'preview_url' => $site->getPreviewUrl(),
                'public_url' => $site->getUrl(),
                'has_snapshot' => $site->hasPublishedSnapshot(),
            ],
        ];
    }

    public function serializeSite(HoldingSite $site): array
    {
        return [
            'id' => $site->id,
            'organization_group_id' => $site->organization_group_id,
            'domain' => $site->getDomain(),
            'title' => $site->title,
            'description' => $site->description,
            'logo_url' => $site->logo_url,
            'favicon_url' => $site->favicon_url,
            'theme_config' => $this->normalizeThemeConfig($site->theme_config ?? []),
            'seo_meta' => $this->normalizeSeoMeta($site->seo_meta ?? [], $site),
            'analytics_config' => $site->analytics_config ?? [],
            'status' => $site->status,
            'is_active' => $site->is_active,
            'is_published' => $site->isPublished(),
            'published_at' => optional($site->published_at)?->toISOString(),
            'url' => $site->getUrl(),
            'preview_url' => $site->getPreviewUrl(),
            'lead_endpoint' => '/api/site-leads',
            'created_at' => optional($site->created_at)?->toISOString(),
            'updated_at' => optional($site->updated_at)?->toISOString(),
        ];
    }

    public function getEditorBlocks(HoldingSite $site): array
    {
        $context = $this->getBindingsContext($site);

        return $site->contentBlocks()
            ->with('assets')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (SiteContentBlock $block) => $this->serializeEditorBlock($block, $context))
            ->values()
            ->all();
    }

    public function buildLiveDraftPayload(HoldingSite $site): array
    {
        $context = $this->getBindingsContext($site);
        $blocks = $site->contentBlocks()
            ->with('assets')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (SiteContentBlock $block) => $this->serializePublicBlock($block, $context))
            ->filter()
            ->values()
            ->all();

        return $this->buildPayload($site, $blocks, 'draft');
    }

    public function buildPublicationSnapshot(HoldingSite $site): array
    {
        $context = $this->getBindingsContext($site);
        $blocks = $site->contentBlocks()
            ->with('assets')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (SiteContentBlock $block) => $this->serializePublicBlock($block, $context))
            ->filter()
            ->values()
            ->all();

        return $this->buildPayload($site, $blocks, 'published');
    }

    public function buildPublishedPayload(HoldingSite $site): array
    {
        if ($site->hasPublishedSnapshot()) {
            return $this->normalizePublishedSnapshot($site, $site->getPublishedPayload());
        }

        $context = $this->getBindingsContext($site);
        $blocks = $site->contentBlocks()
            ->with('assets')
            ->where('status', 'published')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (SiteContentBlock $block) => $this->serializePublicBlock($block, $context))
            ->filter()
            ->values()
            ->all();

        return $this->buildPayload($site, $blocks, 'published');
    }

    public function getTemplatePresets(): array
    {
        return [
            [
                'id' => 'corporate',
                'name' => 'Corporate',
                'description' => 'Hero, about, services, cases, team, lead form and contacts.',
                'blocks' => ['hero', 'stats', 'about', 'services', 'projects', 'team', 'lead_form', 'contacts'],
            ],
            [
                'id' => 'portfolio',
                'name' => 'Portfolio',
                'description' => 'Hero, proof, cases gallery and lead capture.',
                'blocks' => ['hero', 'stats', 'projects', 'gallery', 'testimonials', 'lead_form', 'contacts'],
            ],
            [
                'id' => 'growth',
                'name' => 'Growth',
                'description' => 'Hero, services, FAQ and conversion-oriented CTA.',
                'blocks' => ['hero', 'services', 'faq', 'lead_form', 'contacts'],
            ],
        ];
    }

    private function buildPayload(HoldingSite $site, array $blocks, string $mode): array
    {
        return [
            'site' => $this->serializeSite($site),
            'blocks' => $blocks,
            'organization' => $this->buildOrganizationPayload($site),
            'runtime' => [
                'mode' => $mode,
                'lead_endpoint' => '/api/site-leads',
                'generated_at' => now()->toISOString(),
            ],
        ];
    }

    private function buildOrganizationPayload(HoldingSite $site): array
    {
        return [
            'holding' => [
                'id' => $site->organizationGroup->id,
                'name' => $site->organizationGroup->name,
                'slug' => $site->organizationGroup->slug,
                'description' => $site->organizationGroup->description,
            ],
            'organization' => [
                'id' => $site->organizationGroup->parentOrganization?->id,
                'name' => $site->organizationGroup->parentOrganization?->name,
                'description' => $site->organizationGroup->parentOrganization?->description,
                'phone' => $site->organizationGroup->parentOrganization?->phone,
                'email' => $site->organizationGroup->parentOrganization?->email,
                'address' => $site->organizationGroup->parentOrganization?->address,
                'city' => $site->organizationGroup->parentOrganization?->city,
            ],
        ];
    }

    private function normalizePublishedSnapshot(HoldingSite $site, array $snapshot): array
    {
        if (!isset($snapshot['site']) || !is_array($snapshot['site'])) {
            $snapshot['site'] = $this->serializeSite($site);
        }

        if (!isset($snapshot['organization']) || !is_array($snapshot['organization'])) {
            $snapshot['organization'] = $this->buildOrganizationPayload($site);
        }

        if (!isset($snapshot['blocks']) || !is_array($snapshot['blocks'])) {
            $snapshot['blocks'] = [];
        }

        $runtime = is_array($snapshot['runtime'] ?? null) ? $snapshot['runtime'] : [];
        $snapshot['runtime'] = array_merge(
            [
                'mode' => 'published',
                'lead_endpoint' => '/api/site-leads',
                'generated_at' => now()->toISOString(),
            ],
            $runtime,
            ['mode' => 'published']
        );

        return $snapshot;
    }

    private function serializeEditorBlock(SiteContentBlock $block, array $context): array
    {
        $resolvedContent = $this->resolveBindings($block->content ?? [], $block->bindings ?? [], $context);
        $normalizedType = SiteContentBlock::normalizeBlockType($block->block_type);

        return [
            'id' => $block->id,
            'type' => $normalizedType,
            'source_type' => $block->block_type,
            'key' => $block->block_key,
            'title' => $block->title,
            'content' => $block->content ?? [],
            'resolved_content' => $resolvedContent,
            'settings' => $block->settings ?? [],
            'bindings' => $block->bindings ?? [],
            'sort_order' => $block->sort_order,
            'is_active' => $block->is_active,
            'status' => $block->status,
            'published_at' => optional($block->published_at)?->toISOString(),
            'schema' => SiteContentBlock::getContentSchema($normalizedType),
            'default_content' => SiteContentBlock::getDefaultContent($normalizedType),
            'can_delete' => true,
            'is_renderable' => $this->hasRenderableContent($normalizedType, $resolvedContent),
            'assets' => $block->assets->map(fn (SiteAsset $asset) => $this->serializeAsset($asset))->values()->all(),
        ];
    }

    private function serializePublicBlock(SiteContentBlock $block, array $context): ?array
    {
        if (!$block->is_active) {
            return null;
        }

        $type = SiteContentBlock::normalizeBlockType($block->block_type);
        $resolvedContent = $this->resolveBindings($block->content ?? [], $block->bindings ?? [], $context);

        if (!$this->hasRenderableContent($type, $resolvedContent)) {
            return null;
        }

        return [
            'id' => $block->id,
            'type' => $type,
            'key' => $block->block_key,
            'title' => $block->title,
            'content' => $resolvedContent,
            'settings' => $block->settings ?? [],
            'sort_order' => $block->sort_order,
            'assets' => $block->assets->map(fn (SiteAsset $asset) => $this->serializeAsset($asset))->values()->all(),
        ];
    }

    private function serializeAssets(HoldingSite $site): array
    {
        return $site->assets()
            ->with('uploader')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (SiteAsset $asset) => $this->serializeAsset($asset))
            ->values()
            ->all();
    }

    private function serializeAsset(SiteAsset $asset): array
    {
        $optimized = $asset->optimized_variants ?? [];

        return [
            'id' => $asset->id,
            'filename' => $asset->filename,
            'public_url' => $asset->public_url,
            'optimized_url' => [
                'thumbnail' => $optimized['thumbnail'] ?? $asset->public_url,
                'small' => $optimized['small'] ?? $asset->public_url,
                'medium' => $optimized['medium'] ?? $asset->public_url,
                'large' => $optimized['large'] ?? $asset->public_url,
                'original' => $asset->public_url,
            ],
            'mime_type' => $asset->mime_type,
            'file_size' => $asset->file_size,
            'human_size' => $asset->getHumanReadableSize(),
            'asset_type' => $asset->asset_type,
            'usage_context' => $asset->usage_context,
            'metadata' => $asset->metadata ?? [],
            'is_optimized' => $asset->is_optimized,
            'uploaded_at' => optional($asset->created_at)?->toISOString(),
            'uploader' => [
                'id' => $asset->uploader?->id,
                'name' => $asset->uploader?->name,
            ],
        ];
    }

    private function getBindingsContext(HoldingSite $site): array
    {
        $group = $site->organizationGroup()->with(['parentOrganization.users', 'parentOrganization.childOrganizations', 'parentOrganization.projects'])->first();
        $organization = $group?->parentOrganization;

        $projects = $organization?->projects()
            ->where('status', 'completed')
            ->where('is_archived', false)
            ->orderByDesc('updated_at')
            ->limit(12)
            ->get(['id', 'name', 'description', 'address', 'budget_amount', 'end_date']) ?? collect();

        $team = $organization?->users()
            ->wherePivot('is_active', true)
            ->get(['users.id', 'users.name', 'users.email']) ?? collect();

        $childOrganizations = $organization?->childOrganizations()
            ->where('organization_type', 'child')
            ->get(['id', 'name']) ?? collect();

        $services = collect($organization?->capabilities ?? [])
            ->filter(static fn ($value) => is_string($value) && $value !== '')
            ->values()
            ->map(static fn (string $title) => [
                'title' => $title,
                'description' => null,
                'features' => [],
            ]);

        return [
            'holding' => [
                'name' => $group?->name,
                'slug' => $group?->slug,
                'description' => $group?->description,
            ],
            'organization' => [
                'name' => $organization?->name,
                'legal_name' => $organization?->legal_name,
                'description' => $organization?->description,
                'tax_number' => $organization?->tax_number,
                'registration_number' => $organization?->registration_number,
                'logo_url' => $site->logo_url,
            ],
            'contacts' => [
                'phone' => $organization?->phone,
                'email' => $organization?->email,
                'address' => $organization?->address,
                'city' => $organization?->city,
            ],
            'metrics' => [
                'child_organizations_count' => $childOrganizations->count(),
                'users_count' => $team->count(),
                'projects_count' => $projects->count(),
                'contracts_count' => $organization?->contracts()->count() ?? 0,
                'stats_items' => [
                    ['label' => 'Organizations', 'value' => $childOrganizations->count()],
                    ['label' => 'Projects', 'value' => $projects->count()],
                    ['label' => 'Team', 'value' => $team->count()],
                    ['label' => 'Contracts', 'value' => $organization?->contracts()->count() ?? 0],
                ],
            ],
            'services' => [
                'items' => $services->all(),
            ],
            'projects' => [
                'items' => $projects->map(static fn ($project) => [
                    'id' => $project->id,
                    'title' => $project->name,
                    'description' => $project->description,
                    'location' => $project->address,
                    'budget' => $project->budget_amount,
                    'completed_at' => optional($project->end_date)?->format('Y-m-d'),
                ])->values()->all(),
            ],
            'team' => [
                'members' => $team->map(static fn ($user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'position' => 'Team member',
                    'email' => $user->email,
                ])->values()->all(),
            ],
        ];
    }

    private function resolveBindings(array $content, array $bindings, array $context): array
    {
        $resolved = $content;

        foreach ($bindings as $path => $binding) {
            if (!is_array($binding)) {
                continue;
            }

            $mode = $binding['mode'] ?? 'manual';
            if ($mode === 'manual') {
                continue;
            }

            $currentValue = Arr::get($resolved, $path);
            $sourceValue = Arr::get($context, $binding['source'] ?? '');
            $overrideValue = $binding['override'] ?? null;
            $fallbackValue = $binding['fallback'] ?? null;

            $nextValue = match ($mode) {
                'auto' => !$this->isValueEmpty($overrideValue) ? $overrideValue : (!$this->isValueEmpty($sourceValue) ? $sourceValue : $currentValue),
                'hybrid' => !$this->isValueEmpty($currentValue) ? $currentValue : (!$this->isValueEmpty($overrideValue) ? $overrideValue : $sourceValue),
                default => $currentValue,
            };

            if ($this->isValueEmpty($nextValue) && !$this->isValueEmpty($fallbackValue)) {
                $nextValue = $fallbackValue;
            }

            if (!$this->isValueEmpty($nextValue)) {
                Arr::set($resolved, $path, $nextValue);
            }
        }

        return $resolved;
    }

    private function hasRenderableContent(string $type, array $content): bool
    {
        return match ($type) {
            'hero' => $this->hasValue(Arr::get($content, 'title'))
                || $this->hasValue(Arr::get($content, 'subtitle'))
                || $this->hasValue(Arr::get($content, 'description')),
            'stats' => $this->hasValue(Arr::get($content, 'items')),
            'services' => $this->hasValue(Arr::get($content, 'services')) || $this->hasValue(Arr::get($content, 'description')),
            'projects' => $this->hasValue(Arr::get($content, 'projects')) || $this->hasValue(Arr::get($content, 'description')),
            'team' => $this->hasValue(Arr::get($content, 'members')),
            'testimonials' => $this->hasValue(Arr::get($content, 'items')),
            'gallery' => $this->hasValue(Arr::get($content, 'images')),
            'faq' => $this->hasValue(Arr::get($content, 'items')),
            'lead_form' => true,
            'contacts' => $this->hasValue(Arr::get($content, 'phone'))
                || $this->hasValue(Arr::get($content, 'email'))
                || $this->hasValue(Arr::get($content, 'address')),
            'custom_html' => $this->hasValue(Arr::get($content, 'html')),
            default => $this->hasValue($content),
        };
    }

    private function normalizeThemeConfig(array $themeConfig): array
    {
        return array_merge([
            'primary_color' => '#2563eb',
            'secondary_color' => '#64748b',
            'accent_color' => '#f59e0b',
            'background_color' => '#ffffff',
            'text_color' => '#111827',
            'font_family' => 'Inter, sans-serif',
            'font_size_base' => '16px',
            'border_radius' => '16px',
            'shadow_style' => 'soft',
        ], $themeConfig);
    }

    private function normalizeSeoMeta(array $seoMeta, HoldingSite $site): array
    {
        return [
            'title' => $seoMeta['title'] ?? $seoMeta['meta_title'] ?? $site->title,
            'description' => $seoMeta['description'] ?? $seoMeta['meta_description'] ?? $site->description,
            'keywords' => $seoMeta['keywords'] ?? $seoMeta['meta_keywords'] ?? '',
            'og_title' => $seoMeta['og_title'] ?? $site->title,
            'og_description' => $seoMeta['og_description'] ?? $site->description,
            'og_image' => $seoMeta['og_image'] ?? $site->logo_url,
        ];
    }

    private function hasValue(mixed $value): bool
    {
        if (is_array($value)) {
            return collect($value)->contains(fn ($item) => $this->hasValue($item));
        }

        return !$this->isValueEmpty($value);
    }

    private function isValueEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return Collection::make($value)->every(fn ($item) => $this->isValueEmpty($item));
        }

        return false;
    }
}
