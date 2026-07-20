<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

use DateTimeImmutable;

final readonly class PaperOriginalData
{
    public function __construct(
        public DateTimeImmutable $signedAt,
        public array $signers,
        public string $storageLocation,
        public string $idempotencyKey,
        public ?int $partyId = null,
        public array $metadata = [],
    ) {}
}
