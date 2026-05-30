<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\DTOs\Normatives;

final readonly class WorkIntentData
{
    /**
     * @param array<int, string> $expectedDimensions
     * @param array<int, string> $preferredNormTypes
     * @param array<int, string> $forbiddenNormTypes
     * @param array<int, string> $preferredSectionPrefixes
     * @param array<int, string> $forbiddenSectionPrefixes
     * @param array<int, string> $signals
     */
    public function __construct(
        public string $scope,
        public string $action,
        public ?string $object,
        public ?string $material,
        public ?string $system,
        public array $expectedDimensions,
        public array $preferredNormTypes,
        public array $forbiddenNormTypes,
        public array $preferredSectionPrefixes,
        public array $forbiddenSectionPrefixes,
        public float $confidence,
        public array $signals,
    ) {}
}
