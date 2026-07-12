<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateSetData;
use InvalidArgumentException;

final readonly class RecordedNormativeContentDecision
{
    private function __construct(
        public int $datasetId,
        public string $datasetVersion,
        public string $code,
        public string $selectedContentSha256,
        public array $orderingContentSha256,
        public array $explanationCodes,
        public array $evidenceRefs,
        public float $confidence,
    ) {}

    public static function fromArray(array $data): self
    {
        $keys = ['dataset_id', 'dataset_version', 'code', 'selected_content_sha256', 'ordering_content_sha256',
            'explanation_codes', 'evidence_refs', 'confidence'];
        $actual = array_keys($data); sort($actual); sort($keys);
        if ($actual !== $keys || ! is_int($data['dataset_id']) || ! is_string($data['dataset_version'])
            || ! is_string($data['code']) || ! self::hash($data['selected_content_sha256'])
            || ! is_array($data['ordering_content_sha256']) || $data['ordering_content_sha256'] === []
            || array_filter($data['ordering_content_sha256'], static fn (mixed $value): bool => ! self::hash($value)) !== []
            || ! is_array($data['explanation_codes']) || ! is_array($data['evidence_refs'])
            || ! is_float($data['confidence']) && ! is_int($data['confidence'])) {
            throw new InvalidArgumentException('recorded_normative_content_invalid');
        }

        return new self($data['dataset_id'], $data['dataset_version'], $data['code'], $data['selected_content_sha256'],
            array_values($data['ordering_content_sha256']), array_values($data['explanation_codes']),
            array_values($data['evidence_refs']), (float) $data['confidence']);
    }

    public static function capture(
        NormativeCandidateData $selected,
        array $ordering,
        array $explanationCodes,
        array $evidenceRefs,
        float $confidence,
    ): self {
        $fingerprints = array_map(self::fingerprint(...), $ordering);
        if ($ordering === [] || count($fingerprints) !== count(array_unique($fingerprints))
            || ! in_array(self::fingerprint($selected), $fingerprints, true)) {
            throw new InvalidArgumentException('recorded_normative_content_invalid');
        }

        return new self($selected->datasetId, $selected->datasetVersion, $selected->code,
            self::fingerprint($selected), $fingerprints, array_values($explanationCodes), array_values($evidenceRefs), $confidence);
    }

    public function resolve(NormativeCandidateSetData $set): array
    {
        $byFingerprint = [];
        foreach ($set->candidates as $candidate) {
            $fingerprint = self::fingerprint($candidate);
            if (isset($byFingerprint[$fingerprint])) {
                throw new InvalidArgumentException('recorded_normative_content_ambiguous');
            }
            $byFingerprint[$fingerprint] = $candidate;
        }
        $selected = $byFingerprint[$this->selectedContentSha256] ?? null;
        if (! $selected instanceof NormativeCandidateData || $selected->datasetId !== $this->datasetId
            || $selected->datasetVersion !== $this->datasetVersion || $selected->code !== $this->code) {
            throw new InvalidArgumentException('recorded_normative_content_mismatch');
        }
        $ordering = [];
        foreach ($this->orderingContentSha256 as $fingerprint) {
            $candidate = $byFingerprint[$fingerprint] ?? null;
            if (! $candidate instanceof NormativeCandidateData) {
                throw new InvalidArgumentException('recorded_normative_content_mismatch');
            }
            $ordering[] = $candidate->id;
        }
        if (count($ordering) !== count($set->candidates)) {
            throw new InvalidArgumentException('recorded_normative_content_mismatch');
        }

        return ['selected_candidate_id' => $selected->id, 'ordering' => $ordering,
            'explanation_codes' => $this->explanationCodes, 'evidence_refs' => $this->evidenceRefs,
            'confidence' => $this->confidence, 'schema_version' => 'normative-rerank-v1'];
    }

    public static function fingerprint(NormativeCandidateData $candidate): string
    {
        $content = [
            'dataset_id' => $candidate->datasetId, 'dataset_version' => $candidate->datasetVersion,
            'dataset_status' => $candidate->datasetStatus, 'code' => $candidate->code,
            'unit' => $candidate->canonicalUnit, 'unit_dimension' => $candidate->unitDimension,
            'material' => $candidate->material, 'technology' => $candidate->technology,
            'structure' => $candidate->structure, 'normative_section' => $candidate->normativeSection,
            'object_type' => $candidate->objectType, 'region_code' => $candidate->regionCode,
            'valid_from' => $candidate->validFrom?->format('Y-m-d'), 'valid_to' => $candidate->validTo?->format('Y-m-d'),
        ];

        return hash('sha256', json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private static function hash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }
}
