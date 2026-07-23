<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

final readonly class SignatureVerificationResult
{
    public function __construct(
        public string $status,
        public string $provider,
        public string $providerRequestId,
        public string $correlationId,
        public string $signedContentHash,
        public SignerIdentitySet $signers,
        public ElectronicSignatureEvidence $evidence,
        public SignatureArtifact $artifact,
        public bool $callbackAuthentic = false,
        public ?string $revocationReason = null,
        public array $providerMetadata = [],
    ) {
        BoundedEvidencePayload::assert($providerMetadata);
    }

    public function isAccepted(): bool
    {
        return $this->status === 'verified';
    }
}
