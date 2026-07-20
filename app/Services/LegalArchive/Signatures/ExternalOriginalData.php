<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

final readonly class ExternalOriginalData
{
    public function __construct(
        public string $provider,
        public ElectronicSignatureEvidence $evidence,
        public string $idempotencyKey,
        public string $verificationStatus,
        public array $providerMetadata = [],
        public ?string $revocationReason = null,
        public ?int $partyId = null,
    ) {
        BoundedEvidencePayload::assert($providerMetadata);
    }
}
