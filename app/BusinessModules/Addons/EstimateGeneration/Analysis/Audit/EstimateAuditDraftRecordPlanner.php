<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use Illuminate\Support\Arr;

final class EstimateAuditDraftRecordPlanner
{
    private const PATHS = ['building_quantities.diagnostics', 'stage6_review_items',
        'package_plan.packages', 'estimate_composition.request_info'];

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
        $lookups = ['entities' => [], 'facts' => [], 'quantities' => [], 'works' => [], 'packages' => [], 'candidates' => []];
        $allFacts = $allEvidence = [];
        $loads = [];
        foreach ($parts as $index => $part) {
            foreach ($part['draft']['local_estimates'] ?? [] as $estimate) {
                if (is_string($estimate['key'] ?? null)) {
                    $lookups['packages'][$estimate['key']][$index] = true;
                }
                foreach ($estimate['sections'] ?? [] as $section) {
                    foreach ($section['work_items'] ?? [] as $item) {
                        if (is_string($item['composition_intent']['candidate_id'] ?? null)) {
                            $lookups['candidates'][$item['composition_intent']['candidate_id']][$index] = true;
                        }
                    }
                }
            }
            foreach ($part['entity_ids'] as $id) {
                $lookups['entities'][$id][$index] = true;
            }
            foreach ($part['work_keys'] as $id) {
                $lookups['works'][$id][$index] = true;
            }
            foreach ($part['facts'] as $fact) {
                $allFacts[$fact['id']] = $fact;
                $lookups['facts'][$fact['id']][$index] = true;
                if (is_string($fact['entity_id'] ?? null)) {
                    $lookups['entities'][$fact['entity_id']][$index] = true;
                }
            }
            foreach ($part['evidence'] as $record) {
                $allEvidence[$record['fact_id']][CanonicalPipelineJson::encode($record)] = $record;
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
                $owners = $path === 'package_plan.packages' && is_array($record) && is_string($record['key'] ?? null)
                    ? ($lookups['packages'][$record['key']] ?? []) : $this->owners($record, $lookups);
                if ($path === 'estimate_composition.request_info' && ($record['next_action'] ?? null) === 'keep_existing_work') {
                    $covering = is_string($record['covered_by_position_key'] ?? null)
                        ? ($lookups['works'][$record['covered_by_position_key']] ?? [])
                        : ($lookups['candidates'][$record['covered_by_candidate_id'] ?? ''] ?? []);
                    if ($covering !== []) {
                        $owners = $covering;
                    }
                }
                if ($owners === []) {
                    $owners[array_keys($loads, min($loads), true)[0]] = true;
                }
                if ($path === 'estimate_composition.request_info') {
                    $candidateLoads = array_intersect_key($loads, $owners);
                    if (is_array($record)) {
                        foreach (array_keys($candidateLoads) as $candidateIndex) {
                            $candidatePart = $parts[$candidateIndex];
                            $candidateLoads[$candidateIndex] += $this->attachRequestSources($candidatePart, $record, $allFacts, $allEvidence);
                        }
                    }
                    $owners = [array_keys($candidateLoads, min($candidateLoads), true)[0] => true];
                }
                $bytes = strlen(CanonicalPipelineJson::encode([$record]));
                foreach (array_keys($owners) as $index) {
                    if ($path === 'estimate_composition.request_info' && is_array($record)) {
                        $loads[$index] += $this->attachRequestSources($parts[$index], $record, $allFacts, $allEvidence);
                    }
                    $assigned = Arr::get($parts[$index]['draft'], $path);
                    $assigned[] = $record;
                    Arr::set($parts[$index]['draft'], $path, $assigned);
                    $loads[$index] += $bytes;
                }
            }
        }

        return ['parts' => $parts, 'counts' => $counts];
    }

    private function attachRequestSources(array &$part, array $record, array $facts, array $evidence): int
    {
        $presentFacts = array_fill_keys(array_column($part['facts'], 'id'), true);
        $presentEvidence = array_fill_keys(array_map(CanonicalPipelineJson::encode(...), $part['evidence']), true);
        $bytes = 0;
        foreach (is_array($record['source_fact_ids'] ?? null) ? $record['source_fact_ids'] : [] as $id) {
            if (! is_string($id) || ! isset($facts[$id])) {
                continue;
            }
            if (! isset($presentFacts[$id])) {
                $part['facts'][] = $facts[$id];
                $presentFacts[$id] = true;
                $bytes += strlen(CanonicalPipelineJson::encode($facts[$id]));
            }
            foreach ($evidence[$id] ?? [] as $encoded => $source) {
                if (! isset($presentEvidence[$encoded])) {
                    $part['evidence'][] = $source;
                    $presentEvidence[$encoded] = true;
                    $bytes += strlen($encoded);
                }
            }
        }

        return $bytes;
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
            foreach (is_array($scope['source_fact_ids'] ?? null) ? $scope['source_fact_ids'] : [] as $id) {
                if (is_string($id)) {
                    $owners += $lookups['facts'][$id] ?? [];
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
