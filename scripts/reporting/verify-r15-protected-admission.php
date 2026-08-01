<?php

declare(strict_types=1);

use App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence\R15CandidateAdmissionVerifier;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    if ($argc !== 2 || getenv('GITHUB_ACTIONS') !== 'true') {
        throw new RuntimeException('r15_protected_admission_untrusted');
    }
    (new R15CandidateAdmissionVerifier)->verify(
        $argv[1],
        (string) getenv('GITHUB_SHA'),
        (string) getenv('GITHUB_REPOSITORY'),
        (string) getenv('GITHUB_REF'),
        (string) getenv('GITHUB_JOB'),
    );
    throw new RuntimeException('r15_protected_admission_blocked');
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}
