<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit;

interface EstimateAuditModel
{
    /** @param callable(string): void $onAttemptStarted @return array<string, mixed> */
    public function audit(EstimateAuditInput $input, callable $onAttemptStarted): array;
}
