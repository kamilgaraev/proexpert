<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO;

use InvalidArgumentException;

final readonly class AcceptedNormativeDecisionData
{
    private const CATALOG_KEYS = [
        'candidate_id', 'normative_id', 'dataset_id', 'dataset_version', 'dataset_status', 'code', 'name',
        'unit', 'collection', 'section', 'work_composition', 'resources',
    ];

    private function __construct(
        public string $candidateId,
        public int $normativeId,
        public int $datasetId,
        public string $datasetVersion,
        public string $datasetStatus,
        public string $code,
        public string $name,
        public string $unit,
        public array $collection,
        public array $section,
        public array $workComposition,
        public array $resources,
        public float $score,
        public float $confidence,
        public array $matchReasons,
        public array $warnings,
        public array $evidenceRefs,
        public array $unpricedAbstractResources,
    ) {}

    public static function fromWorkflowResult(NormativeWorkflowResultData $result, array $catalogCandidate): self
    {
        $selectedId = $result->selectedCandidateId();
        $selected = null;
        foreach ($result->candidateSet->candidates as $candidate) {
            if ($candidate->id === $selectedId) {
                $selected = $candidate;
                break;
            }
        }
        $retrievalMetadata = is_array($catalogCandidate['retrieval_metadata'] ?? null)
            ? $catalogCandidate['retrieval_metadata']
            : [];
        $unpricedAbstractResources = self::abstractResources(
            $retrievalMetadata['unpriced_abstract_resources'] ?? []
        );
        $catalogIdentity = $catalogCandidate;
        unset($catalogIdentity['retrieval_metadata']);
        if ($selected === null || ! self::exactKeys($catalogIdentity, self::CATALOG_KEYS)
            || ($catalogCandidate['candidate_id'] ?? null) !== $selected->id
            || ($catalogCandidate['normative_id'] ?? null) !== $selected->normativeId
            || ($catalogCandidate['dataset_id'] ?? null) !== $selected->datasetId) {
            throw new InvalidArgumentException('accepted_normative_candidate_mismatch');
        }
        if (($catalogCandidate['dataset_version'] ?? null) !== $selected->datasetVersion
            || ($catalogCandidate['dataset_status'] ?? null) !== $selected->datasetStatus
            || $result->candidateSet->datasetVersion !== $selected->datasetVersion) {
            throw new InvalidArgumentException('accepted_normative_dataset_mismatch');
        }
        if (($catalogCandidate['unit'] ?? null) !== $selected->canonicalUnit) {
            throw new InvalidArgumentException('accepted_normative_unit_mismatch');
        }
        $resources = $catalogCandidate['resources'] ?? null;
        if (! is_array($resources) || ! self::exactKeys($resources, ['materials', 'labor', 'machinery', 'other'])
            || self::resourceCount($resources) === 0) {
            throw new InvalidArgumentException('accepted_normative_resources_missing');
        }
        $hasPositiveQuantity = false;
        foreach ($resources as $records) {
            if (! is_array($records) || ! array_is_list($records)) {
                throw new InvalidArgumentException('accepted_normative_resources_invalid');
            }
            foreach ($records as $resource) {
                if (! is_array($resource) || ! is_int($resource['price_id'] ?? null) || $resource['price_id'] <= 0
                    || ! is_string($resource['code'] ?? null) || ! is_string($resource['unit'] ?? null)
                    || ! is_numeric($resource['quantity'] ?? null) || (float) $resource['quantity'] < 0) {
                    throw new InvalidArgumentException('accepted_normative_resources_invalid');
                }
                self::assertProjectResourceSelection($resource);
                $hasPositiveQuantity = $hasPositiveQuantity || (float) $resource['quantity'] > 0;
            }
        }
        if (! $hasPositiveQuantity) {
            throw new InvalidArgumentException('accepted_normative_resources_invalid');
        }

        return new self(
            $selected->id, $selected->normativeId, $selected->datasetId, $selected->datasetVersion,
            $selected->datasetStatus, $selected->code, $selected->name, $selected->canonicalUnit,
            self::record($catalogCandidate['collection']), self::record($catalogCandidate['section']),
            self::strings($catalogCandidate['work_composition']), $resources,
            $selected->semanticScore ?? $selected->lexicalScore,
            $result->rerankResult?->confidence ?? min(1.0, max(0.0, $selected->semanticScore ?? $selected->lexicalScore)),
            $result->rerankResult?->explanationCodes ?? ['lexical_match'], [],
            array_values(array_unique([...$selected->sourceEvidence, ...($result->rerankResult?->evidenceRefs ?? [])])),
            $unpricedAbstractResources,
        );
    }

    /** @param array<string, mixed> $match @param array<string, mixed> $decision */
    public static function fromAcceptedCatalogMatch(array $match, array $decision): self
    {
        $selected = $match['selected'] ?? null;
        $version = $match['version']['version_key'] ?? null;
        if (! is_array($selected) || ! is_string($version) || $version === ''
            || ! is_array($selected['resources'] ?? null) || self::resourceCount($selected['resources']) === 0) {
            throw new InvalidArgumentException('accepted_normative_catalog_match_invalid');
        }

        return new self(
            (string) ($selected['key'] ?? ''), (int) ($selected['norm_id'] ?? 0),
            (int) ($selected['dataset_id'] ?? 0), $version, (string) ($selected['dataset_status'] ?? 'parsed'),
            (string) ($selected['code'] ?? ''), (string) ($selected['name'] ?? ''), (string) ($selected['unit'] ?? ''),
            self::record($selected['collection'] ?? null), self::record($selected['section'] ?? null),
            self::strings($selected['work_composition'] ?? []), $selected['resources'],
            (float) ($selected['score'] ?? 0), (float) ($decision['confidence'] ?? $selected['confidence'] ?? 0),
            self::strings($selected['match_reasons'] ?? []), self::strings($decision['warnings'] ?? []), [],
            self::abstractResources($selected['unpriced_abstract_resources'] ?? []),
        );
    }

    private static function abstractResources(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('accepted_normative_abstract_resources_invalid');
        }

        return array_map(static function (mixed $resource): array {
            if (! is_array($resource)
                || ! is_string($resource['resource_code'] ?? null)
                || ! is_string($resource['name'] ?? null)
                || ! is_string($resource['unit'] ?? null)
                || ! is_numeric($resource['quantity'] ?? null)
                || ($resource['reason'] ?? null) !== 'project_resource_selection_required') {
                throw new InvalidArgumentException('accepted_normative_abstract_resources_invalid');
            }

            return [
                'resource_code' => $resource['resource_code'],
                'name' => $resource['name'],
                'unit' => $resource['unit'],
                'quantity' => (float) $resource['quantity'],
                'reason' => 'project_resource_selection_required',
            ];
        }, $value);
    }

    private static function resourceCount(array $resources): int
    {
        return array_sum(array_map(static fn (mixed $items): int => is_array($items) ? count($items) : 0, $resources));
    }

    /** @param array<string, mixed> $resource */
    private static function assertProjectResourceSelection(array $resource): void
    {
        if (! array_key_exists('project_resource_selection', $resource)) {
            return;
        }
        $selection = $resource['project_resource_selection'];
        $groupCode = $resource['code'] ?? null;
        $policy = is_array($selection) ? ($selection['policy'] ?? null) : null;
        $selectedResourceCode = is_array($selection) ? ($selection['selected_resource_code'] ?? null) : null;
        $isSemanticSelection = in_array($policy, [
            'regional_semantic_pipe_hard_attributes_median:v1',
            'regional_semantic_metal_gutter_family_median:v1',
            'regional_semantic_hard_attributes_median:v2',
            'fsbc_semantic_hard_attributes_median:v2',
            'fsnb_semantic_hard_attributes_median:v2',
            'regional_semantic_hard_attributes_median:v3',
            'fsbc_semantic_hard_attributes_median:v3',
            'fsnb_semantic_hard_attributes_median:v3',
        ], true)
            && self::policyMatchesPriceSource($policy, $selection['price_source'] ?? null)
            && is_string($selectedResourceCode)
            && trim($selectedResourceCode) !== '';
        $isExactGroupSelection = is_string($groupCode)
            && is_string($selectedResourceCode)
            && in_array($policy, [
                'regional_child_median:v1',
                'fsbc_base_child_median:v1',
                'fsnb_base_child_median:v1',
                'regional_child_hard_attributes_median:v1',
                'fsbc_base_child_hard_attributes_median:v1',
                'fsnb_base_child_hard_attributes_median:v1',
                'regional_child_hard_attributes_median:v2',
                'fsbc_base_child_hard_attributes_median:v2',
                'fsnb_base_child_hard_attributes_median:v2',
            ], true)
            && self::policyMatchesPriceSource($policy, $selection['price_source'] ?? null)
            && preg_match('/^'.preg_quote($groupCode, '/').'-\d{4}$/D', $selectedResourceCode) === 1;
        if (! is_array($selection)
            || ! is_string($groupCode)
            || preg_match('/^\d{2}\.\d\.\d{2}\.\d{2}$/D', $groupCode) !== 1
            || ($selection['group_code'] ?? null) !== $groupCode
            || ! ($isExactGroupSelection || $isSemanticSelection)
            || ! is_string($selection['selected_resource_name'] ?? null)
            || trim($selection['selected_resource_name']) === ''
            || ! in_array(($selection['price_source'] ?? null), ['regional_catalog', 'fsbc_base', 'fsnb_base'], true)
            || ($resource['price_source'] ?? null) !== ($selection['price_source'] ?? null)
            || ! is_string($selection['price_source_version'] ?? null)
            || trim($selection['price_source_version']) === ''
            || ! is_int($selection['candidates_count'] ?? null)
            || $selection['candidates_count'] <= 0) {
            throw new InvalidArgumentException('accepted_normative_project_resource_selection_invalid');
        }
    }

    private static function policyMatchesPriceSource(mixed $policy, mixed $priceSource): bool
    {
        if (! is_string($policy) || ! is_string($priceSource)) {
            return false;
        }

        return match ($priceSource) {
            'regional_catalog' => str_starts_with($policy, 'regional_'),
            'fsbc_base' => str_starts_with($policy, 'fsbc_'),
            'fsnb_base' => str_starts_with($policy, 'fsnb_'),
            default => false,
        };
    }

    private static function record(mixed $value): array
    {
        return is_array($value) && ! array_is_list($value) && $value !== []
            ? $value : throw new InvalidArgumentException('accepted_normative_catalog_invalid');
    }

    private static function strings(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)
            || array_filter($value, static fn (mixed $item): bool => ! is_string($item)) !== []) {
            throw new InvalidArgumentException('accepted_normative_catalog_invalid');
        }

        return $value;
    }

    private static function exactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }
}
