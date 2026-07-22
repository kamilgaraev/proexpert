<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter;

use RuntimeException;

final readonly class DisabledCompletenessArbiter implements CompletenessArbiter
{
    public function __construct(private string $configuredModel, private string $configuredPromptVersion) {}

    public function review(array $context): array
    {
        throw new RuntimeException('completeness_arbiter_disabled');
    }

    public function model(): string
    {
        return $this->configuredModel;
    }

    public function promptVersion(): string
    {
        return $this->configuredPromptVersion;
    }
}
