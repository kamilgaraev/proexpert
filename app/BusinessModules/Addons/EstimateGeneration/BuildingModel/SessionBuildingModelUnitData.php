<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use InvalidArgumentException;

final readonly class SessionBuildingModelUnitData
{
    public DocumentUnitType $type;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public int $unitId,
        public int $documentId,
        public int $pageId,
        string|DocumentUnitType $type,
        public int $index,
        public string $sourceVersion,
        public float $confidence,
        public array $payload,
    ) {
        $this->type = is_string($type)
            ? (DocumentUnitType::tryFrom($type) ?? throw new InvalidArgumentException('Building model unit type is invalid.'))
            : $type;
        if ($unitId < 1 || $documentId < 1 || $pageId < 1 || $index < 1
            || preg_match('/^sha256:[a-f0-9]{64}$/D', $sourceVersion) !== 1
            || ! is_finite($confidence) || $confidence < 0 || $confidence > 1) {
            throw new InvalidArgumentException('Building model unit identity is invalid.');
        }
    }
}
