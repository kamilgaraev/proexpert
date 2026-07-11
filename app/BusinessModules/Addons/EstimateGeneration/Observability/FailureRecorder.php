<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use InvalidArgumentException;
use Throwable;

final readonly class FailureRecorder
{
    private FailureRecorderObserver $observer;

    public function __construct(
        private FailureStore $store,
        private FailureNormalizer $normalizer = new FailureNormalizer,
        ?FailureRecorderObserver $observer = null,
    ) {
        $this->observer = $observer ?? new NullFailureRecorderObserver;
    }

    public function capture(Throwable $error, FailureContext $context): FailureData
    {
        $failure = $this->normalizer->normalize($error, $context);

        try {
            $this->store->record($failure, now()->toDateTimeImmutable());
        } catch (Throwable) {
            try {
                $this->observer->recordingFailed($failure->code, $failure->fingerprint);
            } catch (Throwable) {
            }
        }

        return $failure;
    }

    public function captureAndRethrow(Throwable $error, FailureContext $context): never
    {
        $this->capture($error, $context);

        throw $error;
    }

    public function resolve(FailureContext $context, string $fingerprint, string $resolutionCode): bool
    {
        if (preg_match('/\Asha256:[0-9a-f]{64}\z/', $fingerprint) !== 1
            || preg_match('/\A[a-z][a-z0-9_]{0,79}\z/', $resolutionCode) !== 1) {
            throw new InvalidArgumentException('Invalid failure resolution identity.');
        }

        return $this->store->resolve($context, $fingerprint, $resolutionCode, now()->toDateTimeImmutable());
    }

    public function resolveActive(FailureContext $context, string $resolutionCode = 'retry_succeeded'): int
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,79}\z/', $resolutionCode) !== 1) {
            throw new InvalidArgumentException('Invalid failure resolution code.');
        }

        return $this->store->resolveActive($context, $resolutionCode, now()->toDateTimeImmutable());
    }
}
