<?php

declare(strict_types=1);

namespace App\BusinessModules\Enterprise\MultiOrganization\Website\Services;

use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSite;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSitePage;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\SiteAsset;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\SiteContentBlock;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class SiteBuilderDataService
{
    public function __construct(
        private readonly SiteCollaboratorService $collaboratorService,
        private readonly SiteRevisionService $revisionService,
        private readonly HoldingSiteBlogService $blogService,
        private readonly SitePageService $pageService
    ) {
    }

    public function getEditorPayload(HoldingSite $site): array
    {
        $site->loadMissing([
            'organizationGroup.parentOrganization.childOrganizations',
            'organizationGroup.parentOrganization.users',
            'organizationGroup.parentOrganization.projects',
            'pages.sections.assets',
            'assets.uploader',
            'collaborators.user',
            'revisions.creator',
        ]);

        $this->pageService->getOrCreateHomePage($site, $site->creator);

        $context = $this->getBindingsContext($site);
        $pages = $site->pages()
            ->with('sections.assets')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (HoldingSitePage $page) => $this->serializeEditorPage($page, $context))
            ->values()
            ->all();

        $homePage = collect($pages)->firstWhere('is_home', true);
        $homeSections = is_array($homePage) ? ($homePage['sections'] ?? []) : [];
        $articles = $this->blogService->listArticles($site->organizationGroup, false);
        $defaultCategory = $this->blogService->defaultCategory($site->organizationGroup);

        return [
            'site' => $this->serializeSite($site),
            'pages' => $pages,
            'blocks' => $homeSections,
            'assets' => $this->serializeAssets($site),
            'templates' => $this->getSectionPresets(),
            'page_templates' => $this->getPageTemplates(),
            'section_presets' => $this->getSectionPresets(),
            'collaborators' => $this->collaboratorService->listForSite($site),
            'revisions' => $this->revisionService->listForSite($site),
            'blog' => [
                'articles' => $articles,
                'default_category' => [
                    'id' => $defaultCategory->id,
                    'name' => $defaultCategory->name,
                ],
            ],
            'summary' => [
                'pages_count' => count($pages),
                'blocks_count' => collect($pages)->sum(fn (array $page) => count($page['sections'] ?? [])),
                'active_blocks_count' => collect($pages)->sum(fn (array $page) => collect($page['sections'] ?? [])->where('is_active', true)->count()),
                'assets_count' => $site->assets()->count(),
                'leads_count' => $site->leads()->count(),
                'collaborators_count' => $site->collaborators()->count(),
                'blog_articles_count' => count($articles),
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
            'default_locale' => $site->default_locale ?: 'ru',
            'enabled_locales' => $site->getEnabledLocales(),
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

    public function buildLiveDraftPayload(HoldingSite $site, string $path = '/', ?string $requestedLocale = null): array
    {
        $snapshot = $this->buildSiteSnapshot($site, 'draft', false);

        return $this->resolveRuntimePayload($site, $snapshot, $path, $requestedLocale, true);
    }

    public function buildPublicationSnapshot(HoldingSite $site): array
    {
        return $this->buildSiteSnapshot($site, 'published', true);
    }

    public function buildPublishedPayload(HoldingSite $site, string $path = '/', ?string $requestedLocale = null): array
    {
        $snapshot = $site->hasPublishedSnapshot()
            ? $this->normalizePublishedSnapshot($site, $site->getPublishedPayload())
            : $this->buildSiteSnapshot($site, 'published', true);

        return $this->resolveRuntimePayload($site, $snapshot, $path, $requestedLocale, false);
    }

    public function getPageTemplates(): array
    {
        return [
            [
                'id' => 'corporate-site',
                'name' => 'Корпоративный сайт',
                'description' => 'Главная, о компании, услуги, проекты, блог и контакты.',
                'pages' => [
                    ['page_type' => 'home', 'slug' => '/', 'title' => 'Главная'],
                    ['page_type' => 'about', 'slug' => '/about', 'title' => 'О компании'],
                    ['page_type' => 'services', 'slug' => '/services', 'title' => 'Услуги'],
                    ['page_type' => 'projects', 'slug' => '/projects', 'title' => 'Проекты'],
                    ['page_type' => 'blog_index', 'slug' => '/blog', 'title' => 'Блог'],
                    ['page_type' => 'contacts', 'slug' => '/contacts', 'title' => 'Контакты'],
                ],
            ],
            [
                'id' => 'compact-presence',
                'name' => 'Компактное присутствие',
                'description' => 'Быстрый многостраничный сайт с конверсионной главной.',
                'pages' => [
                    ['page_type' => 'home', 'slug' => '/', 'title' => 'Главная'],
                    ['page_type' => 'services', 'slug' => '/services', 'title' => 'Услуги'],
                    ['page_type' => 'contacts', 'slug' => '/contacts', 'title' => 'Контакты'],
                ],
            ],
        ];
    }

    public function publishedSnapshotNeedsUpgrade(HoldingSite $site): bool
    {
        if (!$site->hasPublishedSnapshot()) {
            return false;
        }

        $snapshot = $site->getPublishedPayload();

        return !is_array($snapshot['pages'] ?? null)
            && is_array($snapshot['blocks'] ?? null)
            && !empty($snapshot['blocks']);
    }

    public function upgradeLegacyPublishedSnapshot(HoldingSite $site): array
    {
        $snapshot = $site->getPublishedPayload();

        if (!$this->publishedSnapshotNeedsUpgrade($site)) {
            return $this->normalizePublishedSnapshot($site, $snapshot);
        }

        $legacyBlocks = $this->normalizeLegacyPublishedBlocks($snapshot['blocks'] ?? []);
        $upgradedSnapshot = [
            'site' => is_array($snapshot['site'] ?? null) ? $snapshot['site'] : $this->serializeSite($site),
            'pages' => [
                $this->buildLegacyHomePageSnapshot($site, $legacyBlocks),
            ],
            'organization' => is_array($snapshot['organization'] ?? null)
                ? $snapshot['organization']
                : $this->buildOrganizationPayload($site),
            'blog' => is_array($snapshot['blog'] ?? null) ? $snapshot['blog'] : ['articles' => []],
            'runtime' => array_merge(
                [
                    'mode' => 'published',
                    'lead_endpoint' => '/api/site-leads',
                    'generated_at' => now()->toISOString(),
                ],
                is_array($snapshot['runtime'] ?? null) ? $snapshot['runtime'] : [],
                ['mode' => 'published']
            ),
        ];
        $upgradedSnapshot['navigation'] = $this->serializeNavigation($upgradedSnapshot['pages']);

        return $this->normalizePublishedSnapshot($site, $upgradedSnapshot);
    }

    public function getSectionPresets(): array
    {
        return [
            [
                'id' => 'hero',
                'name' => 'Hero',
                'description' => 'Первый экран с заголовком, CTA и медиа.',
                'blocks' => ['hero'],
            ],
            [
                'id' => 'corporate',
                'name' => 'Corporate',
                'description' => 'Hero, proof, услуги, кейсы, команда и лид-форма.',
                'blocks' => ['hero', 'stats', 'about', 'services', 'projects', 'team', 'lead_form', 'contacts'],
            ],
            [
                'id' => 'content-led',
                'name' => 'Content-led',
                'description' => 'О компании, сервисы, FAQ, галерея и контакты.',
                'blocks' => ['about', 'services', 'faq', 'gallery', 'contacts'],
            ],
        ];
    }

    private function buildSiteSnapshot(HoldingSite $site, string $mode, bool $onlyPublishedArticles): array
    {
        $site->loadMissing([
            'organizationGroup.parentOrganization.childOrganizations',
            'organizationGroup.parentOrganization.users',
            'organizationGroup.parentOrganization.projects',
            'pages.sections.assets',
        ]);

        $this->pageService->getOrCreateHomePage($site, $site->creator);
        $context = $this->getBindingsContext($site);
        $pages = $site->pages()
            ->with('sections.assets')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (HoldingSitePage $page) => $this->serializePublicPage($page, $context))
            ->values()
            ->all();

        return [
            'site' => $this->serializeSite($site),
            'navigation' => $this->serializeNavigation($pages),
            'pages' => $pages,
            'organization' => $this->buildOrganizationPayload($site),
            'blog' => [
                'articles' => $this->blogService->listArticles($site->organizationGroup, $onlyPublishedArticles),
            ],
            'runtime' => [
                'mode' => $mode,
                'lead_endpoint' => '/api/site-leads',
                'generated_at' => now()->toISOString(),
            ],
        ];
    }

    private function resolveRuntimePayload(
        HoldingSite $site,
        array $snapshot,
        string $path,
        ?string $requestedLocale,
        bool $isPreview
    ): array {
        [$locale, $normalizedPath] = $this->resolveLocaleAndPath(
            $requestedLocale,
            $path,
            $snapshot['site']['default_locale'] ?? ($site->default_locale ?: 'ru'),
            $snapshot['site']['enabled_locales'] ?? $site->getEnabledLocales()
        );

        $pages = is_array($snapshot['pages'] ?? null) ? $snapshot['pages'] : [];
        $currentPage = collect($pages)->first(fn (array $page) => $this->pageMatchesPath($page, $normalizedPath));
        $blog = is_array($snapshot['blog'] ?? null) ? $snapshot['blog'] : ['articles' => []];
        $blogArticle = $this->resolveBlogArticleContext($pages, $blog, $normalizedPath);

        if (!$currentPage && $blogArticle !== null) {
            $currentPage = $blogArticle['page'];
            $blog['current_article'] = $blogArticle['article'];
        }

        if (!$currentPage) {
            $currentPage = collect($pages)->firstWhere('is_home', true) ?? null;
        }

        $currentPage = is_array($currentPage) ? $this->localizePage($currentPage, $locale) : null;
        $blocks = is_array($currentPage['sections'] ?? null) ? $currentPage['sections'] : [];

        return [
            'site' => array_merge($snapshot['site'] ?? [], [
                'current_locale' => $locale,
            ]),
            'navigation' => $snapshot['navigation'] ?? [],
            'pages' => $pages,
            'page' => $currentPage,
            'current_page' => $currentPage,
            'blocks' => $blocks,
            'organization' => $snapshot['organization'] ?? $this->buildOrganizationPayload($site),
            'blog' => $blog,
            'runtime' => array_merge($snapshot['runtime'] ?? [], [
                'mode' => $isPreview ? 'draft' : 'published',
                'path' => $normalizedPath,
                'locale' => $locale,
            ]),
        ];
    }

    private function serializeEditorPage(HoldingSitePage $page, array $context): array
    {
        $sections = $page->sections
            ->sortBy('sort_order')
            ->map(fn (SiteContentBlock $section) => $this->serializeEditorSection($section, $context))
            ->values()
            ->all();

        return [
            'id' => $page->id,
            'page_type' => $page->page_type,
            'slug' => $page->getNormalizedSlug(),
            'navigation_label' => $page->navigation_label,
            'title' => $page->title,
            'description' => $page->description,
            'seo_meta' => $page->seo_meta ?? [],
            'layout_config' => $page->layout_config ?? [],
            'locale_content' => $page->locale_content ?? [],
            'visibility' => $page->visibility,
            'sort_order' => $page->sort_order,
            'is_home' => $page->is_home,
            'is_active' => $page->is_active,
            'sections' => $sections,
        ];
    }

    private function serializePublicPage(HoldingSitePage $page, array $context): array
    {
        $sections = $page->sections
            ->sortBy('sort_order')
            ->map(fn (SiteContentBlock $section) => $this->serializePublicSection($section, $context))
            ->filter()
            ->values()
            ->all();

        return [
            'id' => $page->id,
            'page_type' => $page->page_type,
            'slug' => $page->getNormalizedSlug(),
            'navigation_label' => $page->navigation_label,
            'title' => $page->title,
            'description' => $page->description,
            'seo_meta' => $page->seo_meta ?? [],
            'layout_config' => $page->layout_config ?? [],
            'locale_content' => $page->locale_content ?? [],
            'visibility' => $page->visibility,
            'sort_order' => $page->sort_order,
            'is_home' => $page->is_home,
            'is_active' => $page->is_active,
            'sections' => $sections,
        ];
    }

    private function serializeEditorSection(SiteContentBlock $section, array $context): array
    {
        $resolvedContent = $this->resolveBindings($section->content ?? [], $section->bindings ?? [], $context);
        $type = SiteContentBlock::normalizeBlockType($section->block_type);

        return [
            'id' => $section->id,
            'page_id' => $section->holding_site_page_id,
            'type' => $type,
            'source_type' => $section->block_type,
            'key' => $section->block_key,
            'title' => $section->title,
            'content' => $section->content ?? [],
            'resolved_content' => $resolvedContent,
            'settings' => $section->settings ?? [],
            'bindings' => $section->bindings ?? [],
            'locale_content' => $section->locale_content ?? [],
            'style_config' => $section->style_config ?? [],
            'sort_order' => $section->sort_order,
            'is_active' => $section->is_active,
            'status' => $section->status,
            'published_at' => optional($section->published_at)?->toISOString(),
            'schema' => SiteContentBlock::getContentSchema($type),
            'default_content' => SiteContentBlock::getDefaultContent($type),
            'can_delete' => true,
            'is_renderable' => $this->hasRenderableContent($type, $resolvedContent),
            'assets' => $section->assets->map(fn (SiteAsset $asset) => $this->serializeAsset($asset))->values()->all(),
            'elements' => $this->buildSectionElements($type, $resolvedContent, $section->bindings ?? []),
        ];
    }

    private function serializePublicSection(SiteContentBlock $section, array $context): ?array
    {
        if (!$section->is_active) {
            return null;
        }

        $type = SiteContentBlock::normalizeBlockType($section->block_type);
        $resolvedContent = $this->resolveBindings($section->content ?? [], $section->bindings ?? [], $context);

        if (!$this->hasRenderableContent($type, $resolvedContent)) {
            return null;
        }

        return [
            'id' => $section->id,
            'page_id' => $section->holding_site_page_id,
            'type' => $type,
            'key' => $section->block_key,
            'title' => $section->title,
            'content' => $resolvedContent,
            'settings' => $section->settings ?? [],
            'bindings' => $section->bindings ?? [],
            'style_config' => $section->style_config ?? [],
            'sort_order' => $section->sort_order,
            'assets' => $section->assets->map(fn (SiteAsset $asset) => $this->serializeAsset($asset))->values()->all(),
            'elements' => $this->buildSectionElements($type, $resolvedContent, $section->bindings ?? []),
        ];
    }

    private function serializeNavigation(array $pages): array
    {
        return collect($pages)
            ->filter(fn (array $page) => ($page['visibility'] ?? 'public') === 'public')
            ->map(static fn (array $page) => [
                'id' => $page['id'],
                'slug' => $page['slug'],
                'label' => $page['navigation_label'] ?: $page['title'],
                'page_type' => $page['page_type'],
                'is_home' => $page['is_home'],
            ])
            ->values()
            ->all();
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
        $normalized = $snapshot;
        $normalized['site'] = is_array($snapshot['site'] ?? null) ? $snapshot['site'] : $this->serializeSite($site);
        $normalized['organization'] = is_array($snapshot['organization'] ?? null)
            ? $snapshot['organization']
            : $this->buildOrganizationPayload($site);
        $normalized['pages'] = is_array($snapshot['pages'] ?? null) ? $snapshot['pages'] : [];
        $normalized['navigation'] = is_array($snapshot['navigation'] ?? null)
            ? $snapshot['navigation']
            : $this->serializeNavigation($normalized['pages']);
        $normalized['blog'] = is_array($snapshot['blog'] ?? null) ? $snapshot['blog'] : ['articles' => []];
        $normalized['runtime'] = array_merge(
            [
                'mode' => 'published',
                'lead_endpoint' => '/api/site-leads',
                'generated_at' => now()->toISOString(),
            ],
            is_array($snapshot['runtime'] ?? null) ? $snapshot['runtime'] : [],
            ['mode' => 'published']
        );

        return $normalized;
    }

    private function normalizeLegacyPublishedBlocks(mixed $blocks): array
    {
        if (!is_array($blocks)) {
            return [];
        }

        return collect($blocks)
            ->filter(static fn ($block) => is_array($block))
            ->map(function (array $block, int $index) {
                $type = SiteContentBlock::normalizeBlockType((string) ($block['type'] ?? $block['block_type'] ?? 'custom_html'));

                return [
                    'id' => $block['id'] ?? ('legacy-' . $index),
                    'page_id' => $block['page_id'] ?? 'legacy-home',
                    'type' => $type,
                    'key' => $block['key'] ?? $block['block_key'] ?? sprintf('%s_%d', $type, $index + 1),
                    'title' => $block['title'] ?? (SiteContentBlock::BLOCK_TYPES[$type] ?? ucfirst($type)),
                    'content' => is_array($block['content'] ?? null) ? $block['content'] : [],
                    'settings' => is_array($block['settings'] ?? null) ? $block['settings'] : SiteContentBlock::getDefaultSettings($type),
                    'bindings' => is_array($block['bindings'] ?? null) ? $block['bindings'] : [],
                    'style_config' => is_array($block['style_config'] ?? null) ? $block['style_config'] : [],
                    'sort_order' => (int) ($block['sort_order'] ?? ($index + 1)),
                    'assets' => is_array($block['assets'] ?? null) ? $block['assets'] : [],
                    'elements' => is_array($block['elements'] ?? null)
                        ? $block['elements']
                        : $this->buildSectionElements(
                            $type,
                            is_array($block['content'] ?? null) ? $block['content'] : [],
                            is_array($block['bindings'] ?? null) ? $block['bindings'] : []
                        ),
                    'locale_content' => is_array($block['locale_content'] ?? null) ? $block['locale_content'] : [],
                    'is_active' => $block['is_active'] ?? true,
                ];
            })
            ->sortBy('sort_order')
            ->values()
            ->all();
    }

    private function buildLegacyHomePageSnapshot(HoldingSite $site, array $blocks): array
    {
        $title = $site->title ?: $site->organizationGroup->name;
        $description = $site->description ?: ($site->organizationGroup->description ?? '');

        return [
            'id' => 'legacy-home',
            'page_type' => 'home',
            'slug' => '/',
            'navigation_label' => $title,
            'title' => $title,
            'description' => $description,
            'seo_meta' => $this->normalizeSeoMeta($site->seo_meta ?? [], $site),
            'layout_config' => [
                'variant' => 'legacy',
            ],
            'locale_content' => [],
            'visibility' => 'public',
            'sort_order' => 1,
            'is_home' => true,
            'is_active' => true,
            'sections' => $blocks,
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
        $metadata = $asset->metadata ?? [];
        $usageMap = $this->buildAssetUsageMap($asset->holding_site_id, $asset->public_url);

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
            'metadata' => $metadata,
            'is_optimized' => $asset->is_optimized,
            'uploaded_at' => optional($asset->created_at)?->toISOString(),
            'uploader' => [
                'id' => $asset->uploader?->id,
                'name' => $asset->uploader?->name,
            ],
            'usage_map' => $usageMap,
            'safe_delete' => count($usageMap) === 0,
        ];
    }

    private function buildAssetUsageMap(int $siteId, string $assetUrl): array
    {
        return SiteContentBlock::query()
            ->where('holding_site_id', $siteId)
            ->get()
            ->flatMap(function (SiteContentBlock $section) use ($assetUrl) {
                $content = $section->content ?? [];
                $matches = [];

                array_walk_recursive($content, function ($value, $key) use (&$matches, $section, $assetUrl) {
                    if ($value === $assetUrl) {
                        $matches[] = [
                            'type' => 'section',
                            'field' => $key,
                            'block_id' => $section->id,
                            'block_key' => $section->block_key,
                            'block_type' => SiteContentBlock::normalizeBlockType($section->block_type),
                        ];
                    }
                });

                return $matches;
            })
            ->values()
            ->all();
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

    private function buildSectionElements(string $type, array $content, array $bindings): array
    {
        $elements = [];
        $schema = SiteContentBlock::getContentSchema($type);

        foreach ($schema as $field => $definition) {
            $fieldType = $definition['type'] ?? 'string';
            $elementType = match ($fieldType) {
                'image' => 'image',
                'url' => 'button',
                'array' => 'repeater',
                'html' => 'rich_text',
                'number' => 'metric',
                default => 'text',
            };

            $elements[] = [
                'id' => sprintf('%s:%s', $type, $field),
                'type' => $elementType,
                'label' => $field,
                'path' => sprintf('content.%s', $field),
                'props' => [
                    'value' => Arr::get($content, $field),
                ],
                'bindings' => $bindings[$field] ?? ['mode' => 'manual'],
                'style' => [],
                'responsive' => [],
                'animation' => ['preset' => 'none'],
            ];
        }

        return $elements;
    }

    private function pageMatchesPath(array $page, string $path): bool
    {
        $slug = trim((string) ($page['slug'] ?? '/'));
        $slug = $slug === '' ? '/' : '/' . trim($slug, '/');
        $normalizedPath = trim($path);
        $normalizedPath = $normalizedPath === '' ? '/' : '/' . trim($normalizedPath, '/');

        return $slug === $normalizedPath;
    }

    private function resolveBlogArticleContext(array $pages, array $blog, string $path): ?array
    {
        $blogPage = collect($pages)->first(fn (array $page) => ($page['page_type'] ?? null) === 'blog_index');
        if (!is_array($blogPage)) {
            return null;
        }

        $blogSlug = trim((string) ($blogPage['slug'] ?? '/blog'));
        $blogSlug = $blogSlug === '' ? '/blog' : '/' . trim($blogSlug, '/');
        if ($path === $blogSlug || !str_starts_with($path, $blogSlug . '/')) {
            return null;
        }

        $articleSlug = trim(substr($path, strlen($blogSlug)), '/');
        if ($articleSlug === '') {
            return null;
        }

        $article = collect($blog['articles'] ?? [])->firstWhere('slug', $articleSlug);
        if (!is_array($article)) {
            return null;
        }

        $page = [
            'id' => 'blog-post:' . ($article['id'] ?? $articleSlug),
            'page_type' => 'blog_post',
            'slug' => $path,
            'navigation_label' => $article['title'] ?? 'Статья',
            'title' => $article['title'] ?? 'Статья',
            'description' => $article['excerpt'] ?? '',
            'seo_meta' => [
                'title' => $article['meta_title'] ?? ($article['title'] ?? ''),
                'description' => $article['meta_description'] ?? ($article['excerpt'] ?? ''),
            ],
            'layout_config' => ['variant' => 'article'],
            'locale_content' => [],
            'visibility' => 'public',
            'sort_order' => 999,
            'is_home' => false,
            'is_active' => true,
            'sections' => [],
        ];

        return [
            'page' => $page,
            'article' => $article,
        ];
    }

    private function localizePage(array $page, string $locale): array
    {
        $localized = $page;
        $localeContent = is_array($page['locale_content'] ?? null) ? ($page['locale_content'][$locale] ?? null) : null;

        if (is_array($localeContent)) {
            $localized['title'] = $localeContent['title'] ?? $localized['title'];
            $localized['description'] = $localeContent['description'] ?? $localized['description'];
            $localized['seo_meta'] = array_merge($localized['seo_meta'] ?? [], $localeContent['seo_meta'] ?? []);
        }

        $localized['sections'] = collect($page['sections'] ?? [])
            ->map(fn (array $section) => $this->localizeSection($section, $locale))
            ->values()
            ->all();

        return $localized;
    }

    private function localizeSection(array $section, string $locale): array
    {
        $localized = $section;
        $localeContent = is_array($section['locale_content'] ?? null) ? ($section['locale_content'][$locale] ?? null) : null;

        if (is_array($localeContent)) {
            $localized['content'] = array_replace_recursive($localized['content'] ?? [], $localeContent);
        }

        return $localized;
    }

    private function resolveLocaleAndPath(?string $requestedLocale, string $path, string $defaultLocale, array $enabledLocales): array
    {
        $locale = $requestedLocale ?: $defaultLocale;
        $normalizedPath = trim($path) === '' ? '/' : '/' . trim($path, '/');
        $segments = array_values(array_filter(explode('/', trim($normalizedPath, '/'))));

        if (isset($segments[0]) && in_array($segments[0], $enabledLocales, true)) {
            $locale = $segments[0];
            array_shift($segments);
            $normalizedPath = '/' . implode('/', $segments);
            $normalizedPath = $normalizedPath === '/' || $normalizedPath === '' ? '/' : $normalizedPath;
        }

        if (!in_array($locale, $enabledLocales, true)) {
            $locale = $defaultLocale;
        }

        return [$locale, $normalizedPath];
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
            'font_family' => 'Manrope, sans-serif',
            'font_size_base' => '16px',
            'border_radius' => '24px',
            'shadow_style' => 'soft',
            'surface_style' => 'clean',
            'container_width' => '1240px',
            'section_spacing' => '120px',
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
            'canonical' => $seoMeta['canonical'] ?? $site->getUrl(),
            'noindex' => $seoMeta['noindex'] ?? false,
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
