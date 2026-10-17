<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use Illuminate\Support\Arr;

final class EstimateAuditDraftRecordPlanner
{
    private const PATHS = ['building_quantities.diagnostics', 'stage6_review_items'];

    public function distribute(array $draft, array $parts): array
    {
        $collections = $counts = [];
        foreach (self::PATHS as $path) {
            $records = Arr::get($draft, $path);
            if (! is_array($records) || ! array_is_list($records)) {
                continue;
            }
            $collections[$path] = $records;
            $counts[$path] = count($records);
            foreach (array_keys($parts) as $index) {
                Arr::set($parts[$index]['draft'], $path, []);
            }
        }
        if (array_sum($counts) === 0) {
            return ['parts' => $parts, 'counts' => $counts];
        }
        $lookups = ['entities' => [], 'facts' => [], 'quantities' => [], 'works' => []];
        $loads = [];
        foreach ($parts as $index => $part) {
            foreach ($part['entity_ids'] as $id) {
                $lookups['entities'][$id][$index] = true;
            }
            foreach ($part['work_keys'] as $id) {
                $lookups['works'][$id][$index] = true;
            }
            foreach ($part['facts'] as $fact) {
                $lookups['facts'][$fact['id']][$index] = true;
                if (is_string($fact['entity_id'] ?? null)) {
                    $lookups['entities'][$fact['entity_id']][$index] = true;
                }
            }
            foreach ($part['derived_quantities'] as $quantity) {
                foreach ([$quantity['id'] ?? null, $quantity['quantity_basis']['key'] ?? null] as $id) {
                    if (is_string($id)) {
                        $lookups['quantities'][$id][$index] = true;
                    }
                }
                if (is_string($quantity['entity_id'] ?? null)) {
                    $lookups['entities'][$quantity['entity_id']][$index] = true;
                }
            }
            $loads[$index] = strlen(CanonicalPipelineJson::encode([
                'draft' => EstimateAuditDraftWireProjection::project($part['draft'], $part['derived_quantities']),
                'facts' => $part['facts'], 'derived_quantities' => $part['derived_quantities'], 'evidence' => $part['evidence'],
            ]));
        }
        foreach ($collections as $path => $records) {
            foreach ($records as $record) {
                $owners = $this->owners($record, $lookups);
                if ($owners === []) {
                    $owners[array_keys($loads, min($loads), true)[0]] = true;
                }
                $bytes = strlen(CanonicalPipelineJson::encode([$record]));
                foreach (array_keys($owners) as $index) {
                    $assigned = Arr::get($parts[$index]['draft'], $path);
                    $assigned[] = $record;
                    Arr::set($parts[$index]['draft'], $path, $assigned);
                    $loads[$index] += $bytes;
                }
            }
        }

        return ['parts' => $parts, 'counts' => $counts];
    }

    private function owners(mixed $record, array $lookups): array
    {
        $owners = [];
        if (! is_array($record)) {
            return $owners;
        }
        foreach ([$record, $record['details'] ?? []] as $scope) {
            if (! is_array($scope)) {
                continue;
            }
            foreach (['entity_id' => 'entities', 'work_item_key' => 'works', 'fact_id' => 'facts',
                'logical_id' => 'quantities', 'derived_quantity_id' => 'quantities'] as $field => $lookup) {
                if (is_string($scope[$field] ?? null)) {
                    $owners += $lookups[$lookup][$scope[$field]] ?? [];
                }
            }
            foreach (is_array($scope['quantity_ids'] ?? null) ? $scope['quantity_ids'] : [] as $id) {
                if (is_string($id)) {
                    $owners += $lookups['quantities'][$id] ?? [];
                }
            }
            foreach (['source_refs', 'inputs'] as $field) {
                foreach (is_array($scope[$field] ?? null) ? $scope[$field] : [] as $ref) {
                    $id = is_array($ref) ? ($ref['fact_id'] ?? $ref['source_locator']['fact_id'] ?? null) : null;
                    if (is_string($id)) {
                        $owners += $lookups['facts'][$id] ?? [];
                    }
                }
            }
        }

        return $owners;
    }
}
