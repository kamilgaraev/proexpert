<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Sketch;

use InvalidArgumentException;

final readonly class SketchProvenanceData
{
    public function __construct(
        public string $source,
        public ?int $confirmedBy,
        public ?string $evidenceRef,
        public string $sourceFingerprint,
        public int $pageNumber,
        public string $coordinateTransform,
    ) {
        $catalog = $source === 'catalog_default';
        if (! in_array($source, ['user', 'catalog_default'], true)
            || preg_match('/^sha256:[a-f0-9]{64}$/', $sourceFingerprint) !== 1 || $pageNumber < 1 || $coordinateTransform === ''
            || ($catalog && ($confirmedBy !== null || $evidenceRef !== null))
            || (! $catalog && ($confirmedBy === null || $confirmedBy < 1 || $evidenceRef === null || $evidenceRef === ''))) {
            throw new InvalidArgumentException('Sketch provenance is invalid.');
        }
    }

    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'confirmed_by' => $this->confirmedBy,
            'evidence_ref' => $this->evidenceRef,
            'source_fingerprint' => $this->sourceFingerprint,
            'page_number' => $this->pageNumber,
            'coordinate_transform' => $this->coordinateTransform,
        ];
    }
}
