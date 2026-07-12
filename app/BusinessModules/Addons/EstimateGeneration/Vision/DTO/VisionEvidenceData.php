<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\DTO;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionContractException;

final readonly class VisionEvidenceData
{
    /** @param array{page_id: int, page_number: int, processing_unit_id: int, source_version: string, coordinate_space: string} $locator */
    public function __construct(public string $key, public array $locator)
    {
        if (preg_match('/^[a-z0-9][a-z0-9._:-]{0,79}$/', $key) !== 1
            || ! self::hasExactKeys($locator, ['page_id', 'page_number', 'processing_unit_id', 'source_version', 'coordinate_space'])
            || ! is_int($locator['page_id']) || $locator['page_id'] < 1
            || ! is_int($locator['page_number']) || $locator['page_number'] < 1 || $locator['page_number'] > 10_000
            || ! is_int($locator['processing_unit_id']) || $locator['processing_unit_id'] < 1
            || ! is_string($locator['source_version']) || preg_match('/^sha256:[a-f0-9]{64}$/', $locator['source_version']) !== 1
            || ! is_string($locator['coordinate_space']) || ! in_array($locator['coordinate_space'], ['normalized_derivative_v1', 'normalized_source_v1', 'source_pixels_v1', 'source_units_v1'], true)) {
            throw new VisionContractException('invalid_evidence');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (! self::hasExactKeys($data, ['key', 'locator']) || ! is_string($data['key']) || ! is_array($data['locator'])
            || ! self::hasExactKeys($data['locator'], ['page_id', 'page_number', 'processing_unit_id', 'source_version', 'coordinate_space'])) {
            throw new VisionContractException('invalid_evidence');
        }

        return new self($data['key'], $data['locator']);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['key' => $this->key, 'locator' => $this->locator];
    }

    public function toSourceSpace(): self
    {
        return new self($this->key, [...$this->locator, 'coordinate_space' => 'normalized_source_v1']);
    }

    public function assertMatches(VisionDocumentInput $input, string $coordinateSpace): void
    {
        if ($this->locator['page_id'] !== $input->pageId
            || $this->locator['page_number'] !== $input->pageNumber
            || $this->locator['processing_unit_id'] !== $input->processingUnitId
            || $this->locator['source_version'] !== $input->sourceVersion
            || $this->locator['coordinate_space'] !== $coordinateSpace) {
            throw new VisionContractException('evidence_provenance_mismatch');
        }
    }

    /** @param array<string, mixed> $data @param list<string> $keys */
    private static function hasExactKeys(array $data, array $keys): bool
    {
        return count($data) === count($keys) && array_diff(array_keys($data), $keys) === [];
    }
}
