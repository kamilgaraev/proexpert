<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use InvalidArgumentException;

/** Complete, deterministic work coverage; sparse sections share a batch without losing their identities. */
final class EstimateAuditBatchPlanner
{
    public const WORKS_PER_PART = 32;

    public const MAX_PARTS = 512;

    public static function partCeiling(int $workItems): int
    {
        if ($workItems < 0 || $workItems > self::WORKS_PER_PART * self::MAX_PARTS) {
            throw new InvalidArgumentException('estimate_audit_partition_limit');
        }

        return max(1, (int) ceil($workItems / self::WORKS_PER_PART));
    }

    /** @return list<array{id:string,work_keys:list<string>,draft:array<string,mixed>}> */
    public function plan(array $draft): array
    {
        $locations = [];
        $seen = [];
        foreach ($draft['local_estimates'] ?? [] as $localIndex => $local) {
            foreach ($local['sections'] ?? [] as $sectionIndex => $section) {
                foreach ($section['work_items'] ?? [] as $itemIndex => $item) {
                    $key = $item['key'] ?? null;
                    if (! is_string($key) || trim($key) === '' || isset($seen[$key])) {
                        throw new InvalidArgumentException('estimate_audit_work_identity_ambiguous');
                    }
                    $seen[$key] = true;
                    $locations[] = ['key' => $key, 'local' => $localIndex, 'section' => $sectionIndex, 'item' => $itemIndex,
                        'order' => [(string) ($local['key'] ?? $localIndex), (string) ($section['key'] ?? $sectionIndex), $key]];
                }
            }
        }
        if ($locations === [] || count($locations) > self::WORKS_PER_PART * self::MAX_PARTS) {
            throw new InvalidArgumentException('estimate_audit_partition_limit');
        }
        if (count($locations) <= self::WORKS_PER_PART) {
            return [['id' => 'whole', 'work_keys' => array_column($locations, 'key'), 'draft' => $draft]];
        }
        usort($locations, static fn (array $left, array $right): int => $left['order'] <=> $right['order']);
        $parts = [];
        foreach (array_chunk($locations, self::WORKS_PER_PART) as $chunk) {
            $projection = $draft;
            $projection['local_estimates'] = [];
            foreach ($chunk as $location) {
                $localIndex = $location['local'];
                $sectionIndex = $location['section'];
                if (! isset($projection['local_estimates'][$localIndex])) {
                    $projection['local_estimates'][$localIndex] = [...$draft['local_estimates'][$localIndex], 'sections' => []];
                }
                if (! isset($projection['local_estimates'][$localIndex]['sections'][$sectionIndex])) {
                    $projection['local_estimates'][$localIndex]['sections'][$sectionIndex] = [...$draft['local_estimates'][$localIndex]['sections'][$sectionIndex], 'work_items' => []];
                }
                $projection['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][]
                    = $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$location['item']];
            }
            foreach ($projection['local_estimates'] as &$local) {
                $local['sections'] = array_values($local['sections']);
            }
            unset($local);
            $projection['local_estimates'] = array_values($projection['local_estimates']);
            $keys = array_column($chunk, 'key');
            $parts[] = ['id' => 'part:'.substr(hash('sha256', CanonicalPipelineJson::encode(array_column($chunk, 'order'))), 0, 40),
                'work_keys' => $keys, 'draft' => $projection];
        }

        return $parts;
    }
}
