<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter;

final class ArbiterReviewContextFactory
{
    /** @return array<string, mixed> */
    public function make(array $draft, ?ArbiterOperationContext $operation = null): array
    {
        $scopes = [];
        $scopeKeys = [];
        $packageKeys = [];
        $scopePackages = [];
        $evidenceRefs = [];
        $workItems = [];

        foreach (array_values((array) ($draft['completeness']['scopes'] ?? [])) as $scope) {
            if (! is_array($scope) || ! $this->isReference($scope['key'] ?? null)) {
                continue;
            }
            $key = (string) $scope['key'];
            $scopeKeys[] = $key;
            $scopePackages[$key] = [];
            $references = $this->references($scope['evidence_refs'] ?? []);
            $evidenceRefs = [...$evidenceRefs, ...$references];
            $scopes[] = [
                'key' => $key,
                'state' => in_array($scope['state'] ?? null, ['covered', 'excluded', 'unresolved'], true)
                    ? $scope['state']
                    : 'unresolved',
                'required_items' => $this->references($scope['required_items'] ?? []),
                'covered_items' => $this->references($scope['covered_items'] ?? []),
                'missing_items' => $this->references($scope['missing_items'] ?? []),
                'evidence_refs' => $references,
            ];
        }
        $packages = [];
        foreach ((array) ($draft['local_estimates'] ?? []) as $estimate) {
            if (! is_array($estimate) || ! $this->isReference($estimate['key'] ?? null)) {
                continue;
            }
            $key = (string) $estimate['key'];
            $packageKeys[] = $key;
            $workKeys = [];
            foreach ((array) ($estimate['sections'] ?? []) as $section) {
                foreach (is_array($section) ? (array) ($section['work_items'] ?? []) : [] as $workItem) {
                    if (! is_array($workItem)) {
                        continue;
                    }
                    $metadata = is_array($workItem['metadata'] ?? null) ? $workItem['metadata'] : [];
                    foreach (['composition_work_key', 'material_scenario_work_key', 'quantity_key'] as $workKey) {
                        if ($this->isReference($metadata[$workKey] ?? null)) {
                            $workKeys[] = $metadata[$workKey];
                        }
                    }
                    $quantityEvidence = is_array($workItem['quantity_evidence'] ?? null) ? $workItem['quantity_evidence'] : [];
                    $evidenceRefs = [...$evidenceRefs, ...$this->references($quantityEvidence['evidence_ids'] ?? [])];
                    $workItems[] = $this->workItemSummary($key, $workItem, $quantityEvidence);
                }
            }
            $packages[] = ['key' => $key, 'work_keys' => array_values(array_unique($workKeys))];
            if (array_key_exists($key, $scopePackages)) {
                $scopePackages[$key][] = $key;
            }
        }
        foreach ($scopePackages as $scopeKey => $keys) {
            $scopePackages[$scopeKey] = array_values(array_unique($keys));
        }
        $payload = [
            'source_input_version' => $this->isReference($draft['source_input_version'] ?? null)
                ? $draft['source_input_version']
                : null,
            'completeness_status' => in_array($draft['completeness']['status'] ?? null, [
                'full_confirmed_scope', 'confirmed_scope_only', 'review_required',
            ], true) ? $draft['completeness']['status'] : 'review_required',
            'scopes' => $scopes,
            'packages' => $packages,
            'work_items' => $workItems,
            'review_context' => $this->reviewContext($draft),
            'budget_scope' => is_array($draft['budget_scope'] ?? null) ? $draft['budget_scope'] : [],
            'scope_keys' => array_values(array_unique($scopeKeys)),
            'package_keys' => array_values(array_unique($packageKeys)),
            'scope_packages' => $scopePackages,
            'evidence_refs' => array_values(array_unique($evidenceRefs)),
        ];

        return [
            ...$payload,
            'input_hash' => 'sha256:'.hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'schema_version' => 'completeness-arbiter:v1',
            ...($operation instanceof ArbiterOperationContext ? ['operation' => $operation] : []),
        ];
    }

    /** @return list<string> */
    private function references(mixed $values): array
    {
        $result = [];
        foreach ((array) $values as $value) {
            if (is_int($value) && $value > 0) {
                $result[] = (string) $value;

                continue;
            }
            if ($this->isReference($value)) {
                $result[] = (string) $value;
            }
        }

        return array_values(array_unique($result));
    }

    private function isReference(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9:._-]{1,120}$/', $value) === 1;
    }

    private function shortText(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $limit);
    }

    /** @return array<string, mixed> */
    private function quantityEvidenceSummary(array $evidence): array
    {
        return array_filter([
            'status' => is_string($evidence['status'] ?? null) ? $evidence['status'] : null,
            'confidence' => is_int($evidence['confidence'] ?? null) || is_float($evidence['confidence'] ?? null)
                ? (float) $evidence['confidence']
                : null,
            'evidence_ids' => $this->references($evidence['evidence_ids'] ?? []),
            'source' => $this->shortText($evidence['source'] ?? null, 120),
            'reason' => $this->shortText($evidence['reason'] ?? null, 80),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /** @return array<string, mixed> */
    private function normativeMatchSummary(mixed $match): array
    {
        if (! is_array($match)) {
            return [];
        }

        return array_filter([
            'status' => is_string($match['status'] ?? null) ? $match['status'] : null,
            'norm_code' => $this->shortText($match['norm_code'] ?? null, 80),
            'normative_code' => $this->shortText($match['normative_code'] ?? null, 80),
            'confidence' => is_int($match['confidence'] ?? null) || is_float($match['confidence'] ?? null)
                ? (float) $match['confidence']
                : null,
            'reason_code' => $this->shortText($match['reason_code'] ?? null, 120),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function resourceCount(mixed $resources): int
    {
        return is_array($resources) ? count($resources) : 0;
    }

    /** @param array<string, mixed> $workItem @param array<string, mixed> $quantityEvidence @return array<string, mixed> */
    private function workItemSummary(string $packageKey, array $workItem, array $quantityEvidence): array
    {
        $normativeMatch = is_array($workItem['normative_match'] ?? null) ? $workItem['normative_match'] : [];
        $materialsCount = $this->resourceCount($workItem['materials'] ?? null);
        $laborCount = $this->resourceCount($workItem['labor'] ?? null);
        $machineryCount = $this->resourceCount($workItem['machinery'] ?? null);

        return array_filter([
            'package_key' => $packageKey,
            'name' => $this->shortText($workItem['name'] ?? null, 56),
            'quantity' => $this->scalar($workItem['quantity'] ?? null),
            'unit' => $this->shortText($workItem['unit'] ?? null, 24),
            'total_cost' => $this->scalar($workItem['total_cost'] ?? null),
            'evidence_status' => $this->shortText($quantityEvidence['status'] ?? null, 32),
            'evidence_confidence' => $this->scalar($quantityEvidence['confidence'] ?? null),
            'evidence_ids' => array_slice($this->references($quantityEvidence['evidence_ids'] ?? []), 0, 3),
            'norm_status' => $this->shortText($normativeMatch['status'] ?? null, 32),
            'norm_code' => $this->shortText($normativeMatch['norm_code'] ?? null, 48),
            'normative_code' => $this->shortText($normativeMatch['normative_code'] ?? null, 48),
            'resource_counts' => array_filter([
                'materials' => $materialsCount > 0 ? $materialsCount : null,
                'labor' => $laborCount > 0 ? $laborCount : null,
                'machinery' => $machineryCount > 0 ? $machineryCount : null,
            ], static fn (?int $value): bool => $value !== null),
        ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }

    /** @return array<string, mixed> */
    private function reviewContext(array $draft): array
    {
        return [
            'object_profile' => $this->objectProfile($draft['object_profile'] ?? null),
            'building_model' => $this->buildingModel($draft['building_model'] ?? null),
            'building_quantities' => $this->buildingQuantities($draft['building_quantities'] ?? null),
            'source_documents' => $this->sourceDocuments($draft['source_documents'] ?? null),
            'document_requirements' => is_array($draft['document_requirements'] ?? null)
                ? $this->compactScalarMap($draft['document_requirements'])
                : [],
            'traceability' => $this->traceability($draft['traceability'] ?? null),
            'regional_context' => is_array($draft['regional_context'] ?? null)
                ? $this->compactScalarMap($draft['regional_context'])
                : [],
            'completeness_exclusions' => is_array($draft['completeness_exclusions'] ?? null)
                ? $this->compactList($draft['completeness_exclusions'], 20)
                : [],
        ];
    }

    /** @return array<string, mixed> */
    private function objectProfile(mixed $profile): array
    {
        if (! is_array($profile)) {
            return [];
        }

        return array_filter([
            'object_type' => $this->shortText($profile['object_type'] ?? null, 80),
            'description' => $this->shortText($profile['description'] ?? null, 240),
            'area' => $this->scalar($profile['area'] ?? null),
            'floors' => $this->scalar($profile['floors'] ?? null),
            'rooms' => $this->scalar($profile['rooms'] ?? null),
            'confidence' => $this->scalar($profile['confidence'] ?? null),
            'finish_levels' => $this->compactList($profile['finish_levels'] ?? [], 12),
            'engineering_systems' => $this->compactList($profile['engineering_systems'] ?? [], 12),
            'missing_inputs' => $this->compactList($profile['missing_inputs'] ?? [], 20),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /** @return array<string, mixed> */
    private function buildingModel(mixed $model): array
    {
        if (! is_array($model)) {
            return [];
        }

        return array_filter([
            'unit' => $this->shortText($model['unit'] ?? null, 20),
            'scale_status' => $this->shortText($model['scale_status'] ?? null, 40),
            'model_version' => $this->shortText($model['model_version'] ?? null, 80),
            'scale_meters_per_unit' => $this->scalar($model['scale_meters_per_unit'] ?? null),
            'floors' => $this->scalar($model['floors'] ?? null),
            'floors_count' => is_array($model['floors'] ?? null) ? count($model['floors']) : null,
            'metrics' => is_array($model['metrics'] ?? null) ? $this->compactScalarMap($model['metrics']) : [],
            'evidence_ids' => $this->references($model['evidence_ids'] ?? []),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /** @return array<string, mixed> */
    private function buildingQuantities(mixed $quantities): array
    {
        if (! is_array($quantities)) {
            return [];
        }

        return array_filter([
            'metrics' => is_array($quantities['metrics'] ?? null) ? $this->compactScalarMap($quantities['metrics']) : [],
            'total_area' => $this->scalar($quantities['total_area'] ?? null),
            'quantity_count' => is_array($quantities['quantities'] ?? null) ? count($quantities['quantities']) : null,
            'diagnostics_count' => is_array($quantities['diagnostics'] ?? null) ? count($quantities['diagnostics']) : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /** @return list<array<string, mixed>> */
    private function sourceDocuments(mixed $documents): array
    {
        $result = [];
        foreach (array_slice((array) $documents, 0, 20) as $document) {
            if (! is_array($document)) {
                continue;
            }
            $result[] = array_filter([
                'id' => $this->scalar($document['id'] ?? null),
                'filename' => $this->shortText($document['filename'] ?? null, 120),
                'status' => $this->shortText($document['status'] ?? null, 40),
                'quality' => $this->shortText($document['quality'] ?? null, 40),
                'source_refs' => $this->references($document['source_refs'] ?? []),
                'facts_summary' => $this->compactList($document['facts_summary'] ?? [], 12),
                'scopes' => $this->compactList($document['scopes'] ?? [], 12),
            ], static fn (mixed $value): bool => $value !== null && $value !== []);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function traceability(mixed $traceability): array
    {
        if (! is_array($traceability)) {
            return [];
        }

        return array_filter([
            'document_source_refs' => $this->references($traceability['document_source_refs'] ?? []),
            'document_context' => is_array($traceability['document_context'] ?? null)
                ? $this->compactScalarMap($traceability['document_context'])
                : [],
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private function scalar(mixed $value): mixed
    {
        return is_string($value) || is_int($value) || is_float($value) || is_bool($value) ? $value : null;
    }

    /** @return array<string, mixed> */
    private function compactScalarMap(array $map): array
    {
        $result = [];
        foreach ($map as $key => $value) {
            if (! is_string($key) || count($result) >= 30) {
                continue;
            }
            if (is_string($value)) {
                $result[$key] = $this->shortText($value, 160);

                continue;
            }
            if (is_int($value) || is_float($value) || is_bool($value)) {
                $result[$key] = $value;
            }
        }

        return array_filter($result, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @return list<mixed> */
    private function compactList(mixed $items, int $limit): array
    {
        $result = [];
        foreach (array_slice((array) $items, 0, $limit) as $item) {
            if (is_string($item)) {
                $value = $this->shortText($item, 120);
                if ($value !== null) {
                    $result[] = $value;
                }

                continue;
            }
            if (is_int($item) || is_float($item) || is_bool($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }

}
