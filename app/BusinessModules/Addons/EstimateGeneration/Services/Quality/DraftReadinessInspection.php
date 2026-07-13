<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality;

final readonly class DraftReadinessInspection
{
    public function __construct(public array $blockingIssues, public array $warnings, public array $metrics) {}

    public function toArray(): array
    {
        return [
            'blocking_issues' => $this->blockingIssues,
            'warnings' => $this->warnings,
            'metrics' => $this->metrics,
            'ready' => $this->blockingIssues === [],
        ];
    }
}
