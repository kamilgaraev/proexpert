<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\DTOs\Normatives;

final readonly class NormativeSearchProfileData
{
    /**
     * @param array<int, string> $requiredTerms
     * @param array<int, string> $synonymTerms
     * @param array<int, string> $allowedSectionPrefixes
     * @param array<int, string> $forbiddenSectionPrefixes
     * @param array<int, string> $forbiddenDomainTerms
     * @param array<int, string> $allowedAnalogActions
     */
    public function __construct(
        public string $scope,
        public ?string $action,
        public ?string $system,
        public array $requiredTerms,
        public array $synonymTerms,
        public array $allowedSectionPrefixes,
        public array $forbiddenSectionPrefixes,
        public array $forbiddenDomainTerms,
        public array $allowedAnalogActions,
    ) {}
}
