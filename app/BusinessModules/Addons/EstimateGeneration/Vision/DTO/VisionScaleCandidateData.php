<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\DTO;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionContractException;

final readonly class VisionScaleCandidateData
{
    private const SOURCES = ['dimension_text', 'scale_notation', 'known_object', 'manual_reference'];

    private const DETAILS = ['visible_dimension', 'drawing_scale', 'reference_object', 'confirmed_control_dimension'];

    public function __construct(
        public string $source,
        public float $metersPerUnit,
        public float $confidence,
        public string $evidenceRef,
        public string $detail,
    ) {
        if (! in_array($source, self::SOURCES, true) || ! in_array($detail, self::DETAILS, true)
            || ! is_finite($metersPerUnit) || $metersPerUnit <= 0.0 || $metersPerUnit > 1_000_000.0
            || ! is_finite($confidence) || $confidence < 0.0 || $confidence > 1.0
            || preg_match('/^[a-z0-9][a-z0-9._:-]{0,79}$/', $evidenceRef) !== 1) {
            throw new VisionContractException('invalid_scale_candidate');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (! self::hasExactKeys($data, ['source', 'meters_per_unit', 'confidence', 'evidence_ref', 'detail'])
            || ! is_string($data['source']) || (! is_int($data['meters_per_unit']) && ! is_float($data['meters_per_unit']))
            || (! is_int($data['confidence']) && ! is_float($data['confidence']))
            || ! is_string($data['evidence_ref']) || ! is_string($data['detail'])) {
            throw new VisionContractException('invalid_scale_candidate');
        }

        return new self($data['source'], (float) $data['meters_per_unit'], (float) $data['confidence'], $data['evidence_ref'], $data['detail']);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['source' => $this->source, 'meters_per_unit' => $this->metersPerUnit, 'confidence' => $this->confidence, 'evidence_ref' => $this->evidenceRef, 'detail' => $this->detail];
    }

    /** @param array<string, mixed> $data @param list<string> $keys */
    private static function hasExactKeys(array $data, array $keys): bool
    {
        return count($data) === count($keys) && array_diff(array_keys($data), $keys) === [];
    }
}
