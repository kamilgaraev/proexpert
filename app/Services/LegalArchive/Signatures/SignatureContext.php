<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

final readonly class SignatureContext
{
    public function __construct(
        public int $organizationId,
        public int $documentId,
        public int $documentVersionId,
        public string $contentHash,
        public string $correlationId,
        public string $callbackUrl,
        public SignerIdentitySet $signers,
        public string $providerOperationId,
        public string $providerIdempotencyKey,
        public array $metadata = [],
    ) {}
}
