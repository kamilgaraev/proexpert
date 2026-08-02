<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence;

use InvalidArgumentException;

final class R15CandidateAdmissionVerifier
{
    public function verify(string $directory, string $commitSha, string $repository, string $ref, string $job): void
    {
        if (preg_match('/^[a-f0-9]{40}$/D', $commitSha) !== 1
            || $repository !== 'kamilgaraev/proexpert'
            || $ref !== 'refs/heads/main'
            || $job !== 'procurement-cycle-r15-protected-admission') {
            $this->reject();
        }
        (new ProcurementCycleReleaseCandidateResolver)->resolve($directory, $commitSha);
    }

    private function reject(): never
    {
        throw new InvalidArgumentException('r15_protected_admission_untrusted');
    }
}
