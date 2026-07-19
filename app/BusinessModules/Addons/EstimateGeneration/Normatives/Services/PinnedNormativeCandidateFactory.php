<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\WorkIntentData;
use DateTimeImmutable;

final readonly class PinnedNormativeCandidateFactory
{
    public function __construct(private NormativeIntentCandidateRanker $ranker = new NormativeIntentCandidateRanker) {}

    /** @return list<NormativeCandidateData> */
    public function forWorkItem(
        array $catalogCandidates,
        array $workItem,
        array $normativeSections = [],
        ?WorkIntentData $canonicalIntent = null,
    ): array {
        $rankable = [];
        $byId = [];
        foreach ($catalogCandidates as $candidate) {
            if (! is_array($candidate) || ! is_string($candidate['candidate_id'] ?? null)) {
                continue;
            }
            $id = $candidate['candidate_id'];
            $byId[$id] = $candidate;
            $rankable[] = (object) [
                'id' => $id, 'code' => (string) ($candidate['code'] ?? ''),
                'name' => (string) ($candidate['name'] ?? ''),
                'canonical_unit' => (string) ($candidate['unit'] ?? ''), 'unit' => (string) ($candidate['unit'] ?? ''),
                'section_name' => (string) ($candidate['section']['name'] ?? ''),
                'section_code' => (string) ($candidate['section']['code'] ?? ''),
                'work_composition' => is_array($candidate['work_composition'] ?? null)
                    ? $candidate['work_composition']
                    : [],
            ];
        }
        $selected = $this->ranker->select($rankable, [[
            'search_text' => (string) ($workItem['normative_search_text'] ?? $workItem['name'] ?? ''),
            'unit' => (string) ($workItem['unit'] ?? ''),
            'code' => is_string($workItem['normative_rate_code'] ?? null) ? $workItem['normative_rate_code'] : null,
            'action' => $canonicalIntent?->technology ?? (is_string($workItem['work_intent']['action'] ?? null)
                ? $workItem['work_intent']['action']
                : null),
            'scope' => $canonicalIntent?->structure ?? (is_string($workItem['work_intent']['scope'] ?? null)
                ? $workItem['work_intent']['scope']
                : null),
            'system' => $canonicalIntent?->system ?? (is_string($workItem['work_intent']['system'] ?? null)
                ? $workItem['work_intent']['system']
                : null),
            'object' => $canonicalIntent?->workObject ?? (is_string($workItem['work_intent']['object'] ?? null)
                ? $workItem['work_intent']['object']
                : null),
            'object_type' => $canonicalIntent?->objectType,
            'normative_sections' => $normativeSections,
            'specialization_evidence' => $canonicalIntent?->specializationEvidence
                ?? (is_array($workItem['specialization_evidence'] ?? null) ? $workItem['specialization_evidence'] : []),
            'specialization_scenario' => $canonicalIntent?->specializationScenario
                ?? (is_array($workItem['specialization_scenario'] ?? null) ? $workItem['specialization_scenario'] : null),
        ]]);
        if ($selected === null) {
            return [];
        }

        return array_map(static function (object $ranked, int $index) use ($byId): NormativeCandidateData {
            $candidate = $byId[(string) $ranked->id];
            $section = is_array($candidate['section'] ?? null) ? $candidate['section'] : [];
            $metadata = is_array($candidate['retrieval_metadata'] ?? null) ? $candidate['retrieval_metadata'] : [];

            return new NormativeCandidateData(
                id: (string) $candidate['candidate_id'], normativeId: (int) $candidate['normative_id'],
                datasetId: (int) $candidate['dataset_id'], datasetVersion: (string) $candidate['dataset_version'],
                datasetStatus: (string) $candidate['dataset_status'], code: (string) $candidate['code'],
                name: (string) $candidate['name'], canonicalUnit: (string) $candidate['unit'],
                unitDimension: self::stringOrNull($metadata['unit_dimension'] ?? null),
                material: self::stringOrNull($metadata['material'] ?? null),
                technology: self::stringOrNull($metadata['technology'] ?? null),
                structure: self::stringOrNull($metadata['structure'] ?? null),
                normativeSection: is_string($section['code'] ?? null) ? $section['code'] : null,
                objectType: self::stringOrNull($metadata['object_type'] ?? null),
                regionCode: self::stringOrNull($metadata['region_code'] ?? null),
                validFrom: self::dateOrNull($metadata['valid_from'] ?? null),
                validTo: self::dateOrNull($metadata['valid_to'] ?? null),
                lexicalScore: max(0.1, 1 - ($index * 0.1)), semanticScore: null,
                lexicalAlgorithmVersion: 'pinned-catalog-v1', semanticIndexVersion: null,
                sourceEvidence: ['norm:'.(string) $candidate['normative_id'], 'dataset:'.(string) $candidate['dataset_id']],
                workComposition: is_array($candidate['work_composition'] ?? null)
                    ? array_values(array_filter($candidate['work_composition'], 'is_string'))
                    : [],
            );
        }, $selected, array_keys($selected));
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function dateOrNull(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }
}
