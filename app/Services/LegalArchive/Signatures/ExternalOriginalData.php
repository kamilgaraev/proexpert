<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

final readonly class ExternalOriginalData
{
    public function __construct(
        public string $provider,
        public ElectronicSignatureEvidence $evidence,
        public string $idempotencyKey,
        public array $providerMetadata = [],
        public ?int $partyId = null,
        public ?int $expectedDocumentLockVersion = null,
    ) {
        BoundedEvidencePayload::assert($providerMetadata);
    }
}
