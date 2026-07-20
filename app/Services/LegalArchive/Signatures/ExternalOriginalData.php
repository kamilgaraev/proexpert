<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

use DateTimeImmutable;

final readonly class ExternalOriginalData
{
    public function __construct(
        public string $provider,
        public DateTimeImmutable $signedAt,
        public array $signers,
        public string $idempotencyKey,
        public string $verificationStatus,
        public DateTimeImmutable $verifiedAt,
        public array $certificateMetadata,
        public array $providerMetadata = [],
        public ?string $revocationReason = null,
        public ?int $partyId = null,
    ) {}
}
