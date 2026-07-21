<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter;

interface CompletenessArbiter
{
    /** @param array<string, mixed> $context
     *  @return array<string, mixed>
     */
    public function review(array $context): array;

    public function model(): string;

    public function promptVersion(): string;
}
