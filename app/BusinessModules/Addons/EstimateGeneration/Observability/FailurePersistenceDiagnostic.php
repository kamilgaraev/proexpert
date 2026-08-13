<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use Throwable;

final readonly class FailurePersistenceDiagnostic
{
    private function __construct(
        public string $code,
        public string $exceptionClass,
        public string $rootExceptionClass,
        public string $chainFingerprint,
        public string $fingerprint,
    ) {}

    public static function fromThrowable(Throwable $error): self
    {
        $diagnostic = (new FailureDiagnosticIdentity)->forThrowable(
            $error,
            'failure_observability_persistence',
        );

        return new self(
            'failure_observability_persistence_failed',
            $diagnostic['exception_class'],
            $diagnostic['root_exception_class'],
            $diagnostic['exception_chain_fingerprint'],
            $diagnostic['diagnostic_fingerprint'],
        );
    }
}
