<?php

declare(strict_types=1);

namespace App\BusinessModules\Enterprise\MultiOrganization\Website\Services;

use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSite;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSitePage;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\SiteContentBlock;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ContentManagementService
{
    public function createBlock(HoldingSite $site, array $data, User $creator): SiteContentBlock
    {
        $page = $site->homePage() ?? app(SitePageService::class)->getOrCreateHomePage($site, $creator);

        return $this->createBlockForPage($page, $data, $creator);
    }

    public function createBlockForPage(HoldingSitePage $page, array $data, User $creator): SiteContentBlock
    {
        $blockType = SiteContentBlock::normalizeBlockType($data['block_type']);
        $content = $data['content'] ?? SiteContentBlock::getDefaultContent($blockType);
        $settings = array_merge(
            SiteContentBlock::getDefaultSettings($blockType),
            $data['settings'] ?? []
        );
        $bindings = array_merge(
            SiteContentBlock::getDefaultBindings($blockType),
            $data['bindings'] ?? []
        );

        if (!isset($data['sort_order'])) {
            $data['sort_order'] = ((int) $page->sections()->max('sort_order')) + 1;
        }

        if (!isset($data['block_key'])) {
            $data['block_key'] = $this->generateBlockKey($page->site, $blockType);
        }

        return SiteContentBlock::create([
            'holding_site_id' => $page->holding_site_id,
            'holding_site_page_id' => $page->id,
            'block_type' => $blockType,
            'block_key' => $data['block_key'],
            'title' => $data['title'] ?? ucfirst(str_replace('_', ' ', $blockType)),
            'content' => $content,
            'settings' => $settings,
            'bindings' => $bindings,
            'locale_content' => $data['locale_content'] ?? [],
            'style_config' => $data['style_config'] ?? ['spacing' => 'default'],
            'sort_order' => $data['sort_order'],
            'is_active' => $data['is_active'] ?? true,
            'status' => 'draft',
            'created_by_user_id' => $creator->id,
            'updated_by_user_id' => $creator->id,
        ]);
    }

    public function updateBlock(SiteContentBlock $block, array $data, User $user): bool
    {
        $updateData = array_filter([
            'title' => $data['title'] ?? null,
            'content' => $data['content'] ?? null,
            'settings' => isset($data['settings'])
                ? array_merge($block->settings ?? [], $data['settings'])
                : null,
            'bindings' => isset($data['bindings'])
                ? array_merge($block->bindings ?? [], $data['bindings'])
                : null,
            'locale_content' => isset($data['locale_content'])
                ? array_merge($block->locale_content ?? [], $data['locale_content'])
                : null,
            'style_config' => isset($data['style_config'])
                ? array_merge($block->style_config ?? [], $data['style_config'])
                : null,
            'sort_order' => $data['sort_order'] ?? null,
            'is_active' => $data['is_active'] ?? null,
            'updated_by_user_id' => $user->id,
        ], static fn ($value) => $value !== null);

        $updated = $block->update($updateData);

        if ($updated) {
            $block->holdingSite->clearCache();
        }

        return $updated;
    }

    public function reorderBlocks(HoldingSite $site, array $blockOrder, User $user): bool
    {
        return DB::transaction(function () use ($site, $blockOrder, $user) {
            foreach (array_values($blockOrder) as $index => $blockId) {
                SiteContentBlock::query()
                    ->where('id', $blockId)
                    ->where('holding_site_id', $site->id)
                    ->update([
                        'sort_order' => $index + 1,
                        'updated_by_user_id' => $user->id,
                    ]);
            }

            $site->clearCache();

            return true;
        });
    }

    public function reorderPageSections(HoldingSitePage $page, array $sectionOrder, User $user): bool
    {
        return DB::transaction(function () use ($page, $sectionOrder, $user) {
            foreach (array_values($sectionOrder) as $index => $sectionId) {
                SiteContentBlock::query()
                    ->where('id', $sectionId)
                    ->where('holding_site_page_id', $page->id)
                    ->update([
                        'sort_order' => $index + 1,
                        'updated_by_user_id' => $user->id,
                    ]);
            }

            $page->site->clearCache();

            return true;
        });
    }

    public function publishBlock(SiteContentBlock $block, User $user): bool
    {
        return $block->publish($user);
    }

    public function duplicateBlock(SiteContentBlock $block, User $user): SiteContentBlock
    {
        $newBlock = $block->replicate();
        $newBlock->block_key = $this->generateBlockKey($block->holdingSite, $block->block_type);
        $newBlock->title = $block->title . ' copy';
        $newBlock->sort_order = ((int) $block->holdingSite->contentBlocks()->max('sort_order')) + 1;
        $newBlock->status = 'draft';
        $newBlock->published_at = null;
        $newBlock->created_by_user_id = $user->id;
        $newBlock->updated_by_user_id = $user->id;
        $newBlock->save();

        return $newBlock;
    }

    public function deleteBlock(SiteContentBlock $block): bool
    {
        $site = $block->holdingSite;
        $deleted = $block->delete();

        if ($deleted) {
            $site->clearCache();
        }

        return $deleted;
    }

    public function getBlocksForEditing(HoldingSite $site): array
    {
        return $site->contentBlocks()
            ->orderBy('sort_order')
            ->get()
            ->map(fn (SiteContentBlock $block) => [
                'id' => $block->id,
                'page_id' => $block->holding_site_page_id,
                'type' => SiteContentBlock::normalizeBlockType($block->block_type),
                'key' => $block->block_key,
                'title' => $block->title,
                'content' => $block->content ?? [],
                'settings' => $block->settings ?? [],
                'bindings' => $block->bindings ?? [],
                'locale_content' => $block->locale_content ?? [],
                'style_config' => $block->style_config ?? [],
                'sort_order' => $block->sort_order,
                'is_active' => $block->is_active,
                'status' => $block->status,
                'published_at' => optional($block->published_at)?->toISOString(),
                'schema' => SiteContentBlock::getContentSchema($block->block_type),
            ])
            ->values()
            ->all();
    }

    public function validateBlockData(string $blockType, array $content): array
    {
        $schema = SiteContentBlock::getContentSchema($blockType);
        $errors = [];

        foreach ($schema as $field => $rules) {
            if (($rules['required'] ?? false) && SiteContentBlock::isEmptyValue($content[$field] ?? null)) {
                $errors[] = sprintf('Field "%s" is required.', $field);
            }

            if (!array_key_exists($field, $content) || SiteContentBlock::isEmptyValue($content[$field])) {
                continue;
            }

            $errors = array_merge($errors, $this->validateFieldType($field, $content[$field], $rules['type'] ?? 'string'));
        }

        return $errors;
    }

    private function generateBlockKey(HoldingSite $site, string $blockType): string
    {
        $baseKey = $blockType;
        $counter = 1;
        $key = $baseKey;

        while ($site->contentBlocks()->where('block_key', $key)->exists()) {
            $counter++;
            $key = $baseKey . '_' . $counter;
        }

        return $key;
    }

    private function validateFieldType(string $field, mixed $value, string $type): array
    {
        return match ($type) {
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) ? [] : [sprintf('Field "%s" must contain a valid email.', $field)],
            'url' => filter_var($value, FILTER_VALIDATE_URL) ? [] : [sprintf('Field "%s" must contain a valid URL.', $field)],
            'number' => is_numeric($value) ? [] : [sprintf('Field "%s" must be numeric.', $field)],
            'array' => is_array($value) ? [] : [sprintf('Field "%s" must be an array.', $field)],
            default => [],
        };
    }
}
