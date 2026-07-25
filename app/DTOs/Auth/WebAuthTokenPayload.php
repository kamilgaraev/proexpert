<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

use DateTimeImmutable;

final readonly class WebAuthTokenPayload
{
    public function __construct(
        public string $audience,
        public string $tokenUse,
        public int $userId,
        public string $sessionUuid,
        public ?int $organizationId,
        public string $tokenId,
        public ?string $csrfToken,
        public bool $remembered,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
