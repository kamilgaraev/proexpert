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
        if ($selected === null || ! self::exactKeys($catalogCandidate, self::CATALOG_KEYS)
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
        foreach ($resources as $records) {
            if (! is_array($records) || ! array_is_list($records)) {
                throw new InvalidArgumentException('accepted_normative_resources_invalid');
            }
            foreach ($records as $resource) {
                if (! is_array($resource) || ! is_int($resource['price_id'] ?? null) || $resource['price_id'] <= 0
                    || ! is_string($resource['code'] ?? null) || ! is_string($resource['unit'] ?? null)
                    || ! is_numeric($resource['quantity'] ?? null) || (float) $resource['quantity'] <= 0) {
                    throw new InvalidArgumentException('accepted_normative_resources_invalid');
                }
            }
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
        );
    }

    /** @param array<string, mixed> $match @param array<string, mixed> $decision */
    public static function fromLegacyMatch(array $match, array $decision): self
    {
        $selected = $match['selected'] ?? null;
        $version = $match['version']['version_key'] ?? null;
        if (! is_array($selected) || ! is_string($version) || $version === ''
            || ! is_array($selected['resources'] ?? null) || self::resourceCount($selected['resources']) === 0) {
            throw new InvalidArgumentException('accepted_normative_legacy_match_invalid');
        }

        return new self(
            (string) ($selected['key'] ?? ''), (int) ($selected['norm_id'] ?? 0),
            (int) ($selected['dataset_id'] ?? 0), $version, (string) ($selected['dataset_status'] ?? 'parsed'),
            (string) ($selected['code'] ?? ''), (string) ($selected['name'] ?? ''), (string) ($selected['unit'] ?? ''),
            self::record($selected['collection'] ?? null), self::record($selected['section'] ?? null),
            self::strings($selected['work_composition'] ?? []), $selected['resources'],
            (float) ($selected['score'] ?? 0), (float) ($decision['confidence'] ?? $selected['confidence'] ?? 0),
            self::strings($selected['match_reasons'] ?? []), self::strings($decision['warnings'] ?? []), [],
        );
    }

    /** @return array<string, mixed> */
    public function legacyMatch(): array
    {
        $selected = [
            'key' => $this->candidateId, 'norm_id' => $this->normativeId, 'code' => $this->code,
            'name' => $this->name, 'unit' => $this->unit, 'collection' => $this->collection,
            'section' => $this->section, 'score' => $this->score, 'confidence' => $this->confidence,
            'match_reasons' => $this->matchReasons, 'warnings' => $this->warnings,
            'work_composition' => $this->workComposition, 'resources' => $this->resources,
        ];

        return [
            'version' => ['source_type' => 'fsnb', 'version_key' => $this->datasetVersion],
            'price_version' => null, 'selected' => $selected, 'candidates' => [$selected],
        ];
    }

    private static function resourceCount(array $resources): int
    {
        return array_sum(array_map(static fn (mixed $items): int => is_array($items) ? count($items) : 0, $resources));
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
