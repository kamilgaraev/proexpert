<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO;

use InvalidArgumentException;

final readonly class AiRoleRunFailure
{
    public function __construct(
        public string $code,
        public bool $ambiguous = false,
        public ?string $physicalAttemptId = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,119}$/D', $code) !== 1
            || ($physicalAttemptId !== null
                && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $physicalAttemptId) !== 1)) {
            throw new InvalidArgumentException('ai_role_run_failure_invalid');
        }
    }
}
