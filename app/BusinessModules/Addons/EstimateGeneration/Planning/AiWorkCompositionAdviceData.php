<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

final readonly class AiWorkCompositionAdviceData
{
    /** @param array<string, array{status: string, reason_codes: list<string>, confidence: float}> $decisions */
    public function __construct(
        public string $status,
        public array $decisions = [],
        public ?string $model = null,
    ) {}
}
