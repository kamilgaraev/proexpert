<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use Throwable;

final readonly class PipelineFailureDetails
{
    private function __construct(
        public string $code,
        public string $fingerprint,
    ) {}

    public static function from(Throwable $error): self
    {
        return new self(
            'pipeline_stage_failed',
            hash('sha256', $error::class."\0".(string) $error->getCode()),
        );
    }

    public static function previousChainFingerprint(Throwable $error): ?string
    {
        $fingerprints = [];
        $previous = $error->getPrevious();

        while ($previous instanceof Throwable) {
            $fingerprints[] = self::from($previous)->fingerprint;
            $previous = $previous->getPrevious();
        }

        return $fingerprints === []
            ? null
            : hash('sha256', implode("\0", $fingerprints));
    }
}
