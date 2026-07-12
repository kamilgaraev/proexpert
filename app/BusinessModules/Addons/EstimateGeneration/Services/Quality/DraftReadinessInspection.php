<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality;

final readonly class DraftReadinessInspection
{
    public function __construct(public array $blockingIssues, public array $warnings, public array $metrics) {}
}
