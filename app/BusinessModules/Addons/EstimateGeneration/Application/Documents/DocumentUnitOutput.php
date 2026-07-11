<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use InvalidArgumentException;

final readonly class DocumentUnitOutput
{
    /** @param array<string, mixed> $normalizedPayload */
    public function __construct(
        public string $version,
        public string $text,
        public ?float $confidence = null,
        public array $normalizedPayload = [],
        public ?int $width = null,
        public ?int $height = null,
        public ?int $rotation = null,
        public ?DocumentUnitType $unitType = null,
        public ?int $unitIndex = null,
        public ?string $sourceVersion = null,
    ) {
        if ($version === '' || strlen($version) > 80) {
            throw new InvalidArgumentException('Unit output version must contain at most 80 characters.');
        }
    }

    public function matches(DocumentUnitExecutionContext $context): bool
    {
        return ($this->unitType === null || $this->unitType === $context->type)
            && ($this->unitIndex === null || $this->unitIndex === $context->index)
            && ($this->sourceVersion === null || $this->sourceVersion === $context->sourceVersion);
    }
}
