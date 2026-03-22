<?php

declare(strict_types=1);

namespace App\BusinessModules\Enterprise\MultiOrganization\Website\Services;

use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSite;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSitePage;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\SiteContentBlock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SitePageService
{
    public function __construct(
        private readonly ContentManagementService $contentService
    ) {
    }

    public function getOrCreateHomePage(HoldingSite $site, ?User $user = null): HoldingSitePage
    {
        $page = $site->pages()->where('is_home', true)->first();

        if ($page instanceof HoldingSitePage) {
            return $page;
        }

        $creator = $user ?? $site->creator;

        return HoldingSitePage::create([
            'holding_site_id' => $site->id,
            'page_type' => 'home',
            'slug' => '/',
            'navigation_label' => 'Главная',
            'title' => $site->title,
            'description' => $site->description,
            'seo_meta' => $site->seo_meta ?? [],
            'layout_config' => ['variant' => 'default'],
            'locale_content' => [
                $site->default_locale ?: 'ru' => [
                    'title' => $site->title,
                    'description' => $site->description,
                ],
            ],
            'visibility' => 'public',
            'sort_order' => 1,
            'is_home' => true,
            'is_active' => true,
            'created_by_user_id' => $creator?->id,
            'updated_by_user_id' => $creator?->id,
        ]);
    }

    public function createPage(HoldingSite $site, array $data, User $user): HoldingSitePage
    {
        return DB::transaction(function () use ($site, $data, $user) {
            $isHome = (bool) ($data['is_home'] ?? false);
            $pageType = (string) ($data['page_type'] ?? 'custom');

            if ($isHome) {
                $site->pages()->update(['is_home' => false]);
            }

            $page = HoldingSitePage::create([
                'holding_site_id' => $site->id,
                'page_type' => $pageType,
                'slug' => $this->normalizeSlug($data['slug'] ?? ($isHome ? '/' : Str::slug((string) ($data['title'] ?? 'page')))),
                'navigation_label' => $data['navigation_label'] ?? $data['title'] ?? 'Новая страница',
                'title' => $data['title'] ?? 'Новая страница',
                'description' => $data['description'] ?? null,
                'seo_meta' => $data['seo_meta'] ?? [],
                'layout_config' => $data['layout_config'] ?? ['variant' => 'default'],
                'locale_content' => $data['locale_content'] ?? [],
                'visibility' => $data['visibility'] ?? 'public',
                'sort_order' => isset($data['sort_order'])
                    ? max(1, (int) $data['sort_order'])
                    : (((int) $site->pages()->max('sort_order')) + 1),
                'is_home' => $isHome,
                'is_active' => $data['is_active'] ?? true,
                'created_by_user_id' => $user->id,
                'updated_by_user_id' => $user->id,
            ]);

            $site->clearCache();

            return $page;
        });
    }

    public function updatePage(HoldingSitePage $page, array $data, User $user): HoldingSitePage
    {
        DB::transaction(function () use ($page, $data, $user) {
            $site = $page->site;
            $isHome = array_key_exists('is_home', $data) ? (bool) $data['is_home'] : $page->is_home;

            if ($isHome) {
                $site->pages()->where('id', '!=', $page->id)->update(['is_home' => false]);
            }

            $page->update(array_filter([
                'page_type' => $data['page_type'] ?? null,
                'slug' => array_key_exists('slug', $data) ? $this->normalizeSlug($data['slug']) : null,
                'navigation_label' => $data['navigation_label'] ?? null,
                'title' => $data['title'] ?? null,
                'description' => $data['description'] ?? null,
                'seo_meta' => array_key_exists('seo_meta', $data) ? $data['seo_meta'] : null,
                'layout_config' => array_key_exists('layout_config', $data) ? $data['layout_config'] : null,
                'locale_content' => array_key_exists('locale_content', $data) ? $data['locale_content'] : null,
                'visibility' => $data['visibility'] ?? null,
                'sort_order' => array_key_exists('sort_order', $data) ? max(1, (int) $data['sort_order']) : null,
                'is_home' => $isHome,
                'is_active' => $data['is_active'] ?? null,
                'updated_by_user_id' => $user->id,
            ], static fn ($value) => $value !== null));

            $site->clearCache();
        });

        return $page->fresh(['sections.assets']);
    }

    public function deletePage(HoldingSitePage $page): void
    {
        if ($page->is_home) {
            throw new InvalidArgumentException('Home page cannot be deleted.');
        }

        DB::transaction(function () use ($page) {
            $site = $page->site;
            $page->sections()->delete();
            $page->delete();
            $site->clearCache();
        });
    }

    public function reorderPages(HoldingSite $site, array $pageOrder, User $user): void
    {
        DB::transaction(function () use ($site, $pageOrder, $user) {
            foreach (array_values($pageOrder) as $index => $pageId) {
                HoldingSitePage::query()
                    ->where('id', $pageId)
                    ->where('holding_site_id', $site->id)
                    ->update([
                        'sort_order' => $index + 1,
                        'updated_by_user_id' => $user->id,
                    ]);
            }

            $site->clearCache();
        });
    }

    public function createSection(HoldingSitePage $page, array $data, User $user): SiteContentBlock
    {
        return $this->contentService->createBlockForPage($page, $data, $user);
    }

    public function updateSection(SiteContentBlock $section, array $data, User $user): SiteContentBlock
    {
        $this->contentService->updateBlock($section, $data, $user);

        return $section->fresh(['assets']);
    }

    public function duplicateSection(SiteContentBlock $section, User $user): SiteContentBlock
    {
        return $this->contentService->duplicateBlock($section, $user);
    }

    public function deleteSection(SiteContentBlock $section): void
    {
        $this->contentService->deleteBlock($section);
    }

    public function reorderSections(HoldingSitePage $page, array $sectionOrder, User $user): void
    {
        $this->contentService->reorderPageSections($page, $sectionOrder, $user);
    }

    private function normalizeSlug(?string $slug): string
    {
        $normalized = trim((string) $slug);

        if ($normalized === '' || $normalized === '/') {
            return '/';
        }

        return '/' . ltrim($normalized, '/');
    }
}
