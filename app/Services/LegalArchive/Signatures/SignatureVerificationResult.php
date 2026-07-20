<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

use DateTimeImmutable;

final readonly class SignatureVerificationResult
{
    public function __construct(
        public string $status,
        public string $provider,
        public string $signedContentHash,
        public bool $callbackAuthentic = false,
        public ?string $signerName = null,
        public ?string $certificateSerial = null,
        public ?DateTimeImmutable $verifiedAt = null,
        public ?string $revocationReason = null,
        public ?string $signaturePath = null,
        public ?string $signatureContentHash = null,
        public array $certificateMetadata = [],
        public array $providerMetadata = [],
    ) {}

    public function isAccepted(): bool
    {
        return $this->status === 'verified';
    }
}
