<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

final readonly class SignatureSession
{
    public function __construct(
        public string $provider,
        public string $providerRequestId,
        public string $correlationId,
        public string $redirectUrl,
        public ?string $expiresAt = null,
        public array $metadata = [],
    ) {
        BoundedEvidencePayload::assert($metadata);
    }
}
