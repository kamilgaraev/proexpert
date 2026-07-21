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
                    $workItems[] = [
                        'package_key' => $key,
                        'name' => $workItem['name'] ?? null,
                        'description' => $workItem['description'] ?? null,
                        'quantity' => $workItem['quantity'] ?? null,
                        'unit' => $workItem['unit'] ?? null,
                        'total_cost' => $workItem['total_cost'] ?? null,
                        'quantity_evidence' => $quantityEvidence,
                        'normative_match' => is_array($workItem['normative_match'] ?? null) ? $workItem['normative_match'] : [],
                        'price_snapshot' => is_array($workItem['price_snapshot'] ?? null) ? $workItem['price_snapshot'] : [],
                        'materials' => is_array($workItem['materials'] ?? null) ? $workItem['materials'] : [],
                        'labor' => is_array($workItem['labor'] ?? null) ? $workItem['labor'] : [],
                        'machinery' => is_array($workItem['machinery'] ?? null) ? $workItem['machinery'] : [],
                    ];
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
            'review_context' => [
                'object_profile' => is_array($draft['object_profile'] ?? null) ? $draft['object_profile'] : [],
                'building_model' => is_array($draft['building_model'] ?? null) ? $draft['building_model'] : [],
                'building_quantities' => is_array($draft['building_quantities'] ?? null) ? $draft['building_quantities'] : [],
                'source_documents' => is_array($draft['source_documents'] ?? null) ? $draft['source_documents'] : [],
                'document_requirements' => is_array($draft['document_requirements'] ?? null) ? $draft['document_requirements'] : [],
                'traceability' => is_array($draft['traceability'] ?? null) ? $draft['traceability'] : [],
                'regional_context' => is_array($draft['regional_context'] ?? null) ? $draft['regional_context'] : [],
                'completeness_exclusions' => is_array($draft['completeness_exclusions'] ?? null) ? $draft['completeness_exclusions'] : [],
            ],
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
}
