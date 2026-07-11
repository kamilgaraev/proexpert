<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

final readonly class DocumentUnitPageReservationState
{
    /** @param array<int, string> $languageCodes @param array<string, mixed> $normalizedPayload @param array<int, string> $qualityFlags */
    public function __construct(
        public ?int $processingUnitId,
        public ?string $sourceVersion,
        public ?string $outputVersion,
        public ?int $width,
        public ?int $height,
        public ?int $rotation,
        public array $languageCodes,
        public ?string $text,
        public ?string $textHash,
        public ?float $confidence,
        public ?string $rawPayloadPath,
        public array $normalizedPayload,
        public array $qualityFlags,
        public bool $hasLineage,
    ) {}

    public function pristine(): bool
    {
        return $this->outputVersion === null && $this->width === null && $this->height === null
            && $this->rotation === null && $this->languageCodes === [] && $this->text === null
            && $this->textHash === null && $this->confidence === null && $this->rawPayloadPath === null
            && $this->normalizedPayload === [] && $this->qualityFlags === [] && ! $this->hasLineage;
    }
}
