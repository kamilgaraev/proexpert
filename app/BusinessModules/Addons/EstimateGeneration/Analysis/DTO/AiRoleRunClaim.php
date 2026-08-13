<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO;

use InvalidArgumentException;

final readonly class AiRoleRunClaim
{
    public function __construct(
        public int $runId,
        public string $disposition,
        public ?string $ownerUuid = null,
        public ?AiRoleRunResult $result = null,
        public ?string $failureCode = null,
    ) {
        if ($runId < 1 || ! in_array($disposition, ['owned', 'busy', 'replay', 'failed', 'ambiguous'], true)) {
            throw new InvalidArgumentException('ai_role_run_claim_invalid');
        }
    }
}
