<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Evidence;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class PlanOneACompletionRef
{
    public function __construct(
        public string $lockSha256,
        public string $evidenceSha256,
        public DateTimeImmutable $generatedAt,
        public string $status,
    ) {
        if (preg_match('/^[a-f0-9]{64}$/', $lockSha256) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $evidenceSha256) !== 1
            || $status !== 'passed') {
            throw new InvalidArgumentException('plan_one_a_completion_ref_invalid');
        }
    }
}
