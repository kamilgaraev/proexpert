<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence;

use LogicException;

final class R15CiEvidenceRuntimeGuard
{
    public function assertEnabled(): void
    {
        $environment = strtolower((string) (getenv('APP_ENV') ?: ''));
        if (in_array($environment, ['production', 'prod'], true)
            || PHP_SAPI !== 'cli'
            || getenv('MOST_R15_CI_EVIDENCE') !== '1'
            || getenv('GITHUB_ACTIONS') !== 'true') {
            throw new LogicException('r15_ci_evidence_runtime_forbidden');
        }
    }
}
