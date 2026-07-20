<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Sources;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class LegalDocumentCreateLeaseDecision
{
    private function __construct(
        public string $decision,
        public ?string $retryAction,
    ) {}

    public static function decide(
        string $status,
        ?DateTimeImmutable $leaseExpiresAt,
        DateTimeImmutable $now,
        bool $hasReadyVersion,
        ?string $persistedRetryAction = null,
    ): self {
        if ($status === 'completed') {
            return new self('replay', null);
        }

        if ($status === 'pending' && $leaseExpiresAt !== null && $leaseExpiresAt > $now) {
            return new self('in_progress', null);
        }

        if (! in_array($status, ['pending', 'failed'], true)) {
            throw new InvalidArgumentException('Unsupported document create status.');
        }

        $retryAction = $hasReadyVersion ? 'retry_finalize' : $persistedRetryAction;
        if (! in_array($retryAction, ['retry_upload', 'retry_finalize'], true)) {
            $retryAction = 'retry_upload';
        }

        return new self('claim', $retryAction);
    }

    public static function ownsAttempt(?string $persistedToken, string $attemptToken): bool
    {
        return is_string($persistedToken)
            && $persistedToken !== ''
            && hash_equals($persistedToken, $attemptToken);
    }
}
