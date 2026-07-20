<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

use DateTimeImmutable;

final readonly class PaperOriginalData
{
    public function __construct(
        public DateTimeImmutable $signedAt,
        public SignerIdentitySet $signers,
        public string $storageLocation,
        public string $idempotencyKey,
        public ?int $partyId = null,
        public ?string $partyRoleSnapshot = null,
        public bool $authorityConfirmed = false,
        public string $timeSource = 'operator',
        public ?string $clientIpHash = null,
        public ?string $userAgentHash = null,
    ) {}
}
