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
    ) {}

    public function toCachePayload(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'csrf_token' => $this->csrfToken,
            'refresh_token_id' => $this->refreshTokenId,
            'access_expires_at' => $this->accessExpiresAt->format(DATE_ATOM),
            'refresh_expires_at' => $this->refreshExpiresAt->format(DATE_ATOM),
        ];
    }

    public static function fromCachePayload(array $payload): ?self
    {
        foreach ([
            'access_token',
            'refresh_token',
            'csrf_token',
            'refresh_token_id',
            'access_expires_at',
            'refresh_expires_at',
        ] as $key) {
            if (! isset($payload[$key]) || ! is_string($payload[$key]) || $payload[$key] === '') {
                return null;
            }
        }

        try {
            return new self(
                $payload['access_token'],
                $payload['refresh_token'],
                $payload['csrf_token'],
                $payload['refresh_token_id'],
                new DateTimeImmutable($payload['access_expires_at']),
                new DateTimeImmutable($payload['refresh_expires_at']),
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
