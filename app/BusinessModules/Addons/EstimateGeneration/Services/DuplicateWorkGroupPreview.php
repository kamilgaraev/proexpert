<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

final class DuplicateWorkGroupPreview
{
    /** Resolve complete section groups after pagination, including peers outside the current page/filter. */
    public static function enrich(array $draft, array $page): array
    {
        $wanted = [];
        foreach ($page['items'] as $item) {
            if (($item['required_action'] ?? null) === 'resolve_duplicate') {
                $wanted[$item['local_estimate_key']][$item['section_key']] = true;
            }
        }
        $groups = [];
        $signatures = [];
        foreach ($draft['local_estimates'] ?? [] as $local) {
            $localKey = $local['key'] ?? '';
            foreach ($local['sections'] ?? [] as $section) {
                $sectionKey = $section['key'] ?? '';
                if (! isset($wanted[$localKey][$sectionKey])) {
                    continue;
                }
                foreach ($section['work_items'] ?? [] as $work) {
                    if (! is_array($work) || ! is_string($work['key'] ?? null)) {
                        continue;
                    }
                    $signature = WorkItemDuplicateSignature::fromWorkItem($work)?->value;
                    if ($signature !== null) {
                        $groups[$localKey][$sectionKey][$signature][] = $work;
                        $signatures[$localKey][$sectionKey][$work['key']] = $signature;
                    }
                }
            }
        }
        $previews = [];
        foreach ($page['items'] as &$item) {
            $item['duplicate_group'] = null;
            if (($item['required_action'] ?? null) !== 'resolve_duplicate') {
                continue;
            }
            $localKey = $item['local_estimate_key'];
            $sectionKey = $item['section_key'];
            $signature = $signatures[$localKey][$sectionKey][$item['work_item_key']] ?? null;
            if ($signature === null) {
                continue;
            }
            if (! isset($previews[$localKey][$sectionKey][$signature])) {
                $peers = $groups[$localKey][$sectionKey][$signature];
                $count = count($peers);
                $previews[$localKey][$sectionKey][$signature] = [
                    'count' => $count, 'complete' => $count <= WorkItemPhysicalIdentity::MAX_GROUP_ITEMS,
                    'can_resolve' => $count > 1 && WorkItemPhysicalIdentity::canResolveGroup($peers),
                    'items' => array_map(static fn (array $work): array => [
                        'key' => $work['key'], 'name' => (string) ($work['name'] ?? $work['key']),
                        'quantity' => isset($work['quantity']) ? (string) $work['quantity'] : null,
                        'unit' => isset($work['unit']) ? (string) $work['unit'] : null,
                    ], array_slice($peers, 0, WorkItemPhysicalIdentity::MAX_GROUP_ITEMS)),
                ];
            }
            $item['duplicate_group'] = $previews[$localKey][$sectionKey][$signature];
        }
        unset($item);

        return $page;
    }
}
