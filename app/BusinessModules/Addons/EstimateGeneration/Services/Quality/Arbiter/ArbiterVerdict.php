<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter;

final readonly class ArbiterVerdict
{
    /** @param list<array<string, mixed>> $findings */
    public function __construct(public string $outcome, public array $findings) {}
}
