<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

final readonly class SignatureCallback
{
    public function __construct(
        public string $provider,
        public string $providerRequestId,
        public string $correlationId,
        public string $replayToken,
        public array $payload,
    ) {
        BoundedEvidencePayload::assert($payload);
    }
}
