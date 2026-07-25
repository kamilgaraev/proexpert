<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

use DateTimeImmutable;

final readonly class WebAuthTokenPair
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public string $csrfToken,
        public string $refreshTokenId,
        public DateTimeImmutable $accessExpiresAt,
        public DateTimeImmutable $refreshExpiresAt,
    ) {
    }
}
