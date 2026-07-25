<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\DTOs\Auth\WebAuthTokenPair;
use App\DTOs\Auth\WebAuthTokenPayload;
use App\Models\User;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use LogicException;
use RuntimeException;
use Throwable;

final class WebAuthTokenService
{
    public function __construct(private readonly UserAuthSessionService $sessions)
    {
    }

    public function issue(
        User $user,
        string $audience,
        string $sessionUuid,
        ?int $organizationId,
        bool $remembered,
    ): WebAuthTokenPair {
        $configuration = $this->configuration($audience);
        $now = new DateTimeImmutable();
        $accessExpiresAt = $now->add(new DateInterval('PT'.$this->accessTtlMinutes().'M'));
        $refreshExpiresAt = $now->add(new DateInterval('PT'.$this->refreshTtlMinutes($remembered).'M'));
        $refreshTokenId = (string) Str::uuid();
        $csrfToken = $this->randomToken();

        $accessToken = $this->buildToken(
            $configuration,
            $audience,
            'access',
            $user,
            $sessionUuid,
            $organizationId,
            $remembered,
            $now,
            $accessExpiresAt,
            null,
        );
        $refreshToken = $this->buildToken(
            $configuration,
            $audience,
            'refresh',
            $user,
            $sessionUuid,
            $organizationId,
            $remembered,
            $now,
            $refreshExpiresAt,
            $csrfToken,
            $refreshTokenId,
        );
        $pair = new WebAuthTokenPair(
            $accessToken,
            $refreshToken,
            $csrfToken,
            $refreshTokenId,
            $accessExpiresAt,
            $refreshExpiresAt,
        );

        $this->persistRefreshToken($audience, $sessionUuid, $pair);

        return $pair;
    }

    public function parse(string $token, string $audience, string $expectedTokenUse): WebAuthTokenPayload
    {
        try {
            $configuration = $this->configuration($audience);
            $rawAudience = $this->rawAudience($token);

            if (! is_string($rawAudience) || ! hash_equals($audience, $rawAudience)) {
                throw new RuntimeException('Invalid web token audience.');
            }

            $parsedToken = $configuration->parser()->parse($token);

            if (! $parsedToken instanceof Plain) {
                throw new RuntimeException('Unsupported web token.');
            }

            $configuration->validator()->assert(
                $parsedToken,
                new SignedWith($configuration->signer(), $configuration->verificationKey()),
                new StrictValidAt(SystemClock::fromUTC()),
                new IssuedBy($this->issuer()),
                new PermittedFor($audience),
            );

            $claims = $parsedToken->claims();
            $tokenUse = $claims->get('token_use', null);
            $subject = $claims->get('sub', null);
            $sessionUuid = $claims->get('session_uuid', null);
            $tokenId = $claims->get('jti', null);
            $expiresAt = $claims->get('exp', null);
            $organizationId = $claims->get('organization_id', null);
            $csrfToken = $claims->get('csrf', null);
            $remembered = $claims->get('remembered', false);

            if ($tokenUse !== $expectedTokenUse
                || ! is_string($subject)
                || filter_var($subject, FILTER_VALIDATE_INT) === false
                || (int) $subject < 1
                || ! is_string($sessionUuid)
                || ! Str::isUuid($sessionUuid)
                || ! is_string($tokenId)
                || ! Str::isUuid($tokenId)
                || ! $expiresAt instanceof DateTimeImmutable
                || ($organizationId !== null && (! is_int($organizationId) || $organizationId < 1))
                || ($csrfToken !== null && (! is_string($csrfToken) || $csrfToken === ''))
                || ! is_bool($remembered)
            ) {
                throw new RuntimeException('Malformed web token claims.');
            }

            if ($expectedTokenUse === 'refresh' && $csrfToken === null) {
                throw new RuntimeException('Refresh token is missing CSRF state.');
            }

            return new WebAuthTokenPayload(
                $audience,
                $tokenUse,
                (int) $subject,
                $sessionUuid,
                $organizationId,
                $tokenId,
                $csrfToken,
                $remembered,
                $expiresAt,
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('Web token validation failed.', previous: $exception);
        }
    }

    public function rotate(User $user, WebAuthTokenPayload $payload, string $refreshToken): WebAuthTokenPair
    {
        $lock = Cache::lock($this->refreshLockKey($payload->audience, $payload->sessionUuid), 10);

        if (! $lock->get()) {
            throw new RuntimeException('Refresh token rotation is unavailable.');
        }

        try {
            $record = Cache::get($this->refreshCacheKey($payload->audience, $payload->sessionUuid));
            $tokenHash = hash('sha256', $refreshToken);

            if (! is_array($record)
                || ! isset($record['token_hash'], $record['token_id'])
                || ! is_string($record['token_hash'])
                || ! is_string($record['token_id'])
                || ! hash_equals($record['token_hash'], $tokenHash)
                || ! hash_equals($record['token_id'], $payload->tokenId)
            ) {
                $this->revokeForRefreshFailure($payload);
                throw new RuntimeException('Refresh token replay detected.');
            }

            $session = $this->sessions->findActiveByUuid($payload->sessionUuid);

            if ($session === null || (int) $session->user_id !== (int) $user->id || ! $user->is_active) {
                $this->revokeForRefreshFailure($payload);
                throw new RuntimeException('Refresh session is no longer active.');
            }

            return $this->issue(
                $user,
                $payload->audience,
                $payload->sessionUuid,
                $payload->organizationId,
                $payload->remembered,
            );
        } finally {
            $lock->release();
        }
    }

    public function invalidateRefreshSession(string $audience, string $sessionUuid): void
    {
        Cache::forget($this->refreshCacheKey($audience, $sessionUuid));
    }

    public function matchesCurrentCsrfToken(WebAuthTokenPayload $payload, string $csrfToken): bool
    {
        $record = Cache::get($this->refreshCacheKey($payload->audience, $payload->sessionUuid));

        return is_array($record)
            && isset($record['csrf_hash'])
            && is_string($record['csrf_hash'])
            && hash_equals($record['csrf_hash'], hash('sha256', $csrfToken));
    }

    private function buildToken(
        Configuration $configuration,
        string $audience,
        string $tokenUse,
        User $user,
        string $sessionUuid,
        ?int $organizationId,
        bool $remembered,
        DateTimeImmutable $issuedAt,
        DateTimeImmutable $expiresAt,
        ?string $csrfToken,
        ?string $tokenId = null,
    ): string {
        $builder = $configuration->builder()
            ->issuedBy($this->issuer())
            ->permittedFor($audience)
            ->relatedTo((string) $user->getKey())
            ->identifiedBy($tokenId ?? (string) Str::uuid())
            ->issuedAt($issuedAt)
            ->canOnlyBeUsedAfter($issuedAt)
            ->expiresAt($expiresAt)
            ->withClaim('token_use', $tokenUse)
            ->withClaim('session_uuid', $sessionUuid)
            ->withClaim('organization_id', $organizationId)
            ->withClaim('remembered', $remembered);

        if ($csrfToken !== null) {
            $builder = $builder->withClaim('csrf', $csrfToken);
        }

        return $builder->getToken($configuration->signer(), $configuration->signingKey())->toString();
    }

    private function persistRefreshToken(string $audience, string $sessionUuid, WebAuthTokenPair $pair): void
    {
        $stored = Cache::put(
            $this->refreshCacheKey($audience, $sessionUuid),
            [
                'token_hash' => hash('sha256', $pair->refreshToken),
                'token_id' => $pair->refreshTokenId,
                'csrf_hash' => hash('sha256', $pair->csrfToken),
            ],
            $pair->refreshExpiresAt,
        );

        if ($stored !== true) {
            throw new RuntimeException('Refresh session storage failed.');
        }
    }

    private function revokeForRefreshFailure(WebAuthTokenPayload $payload): void
    {
        $this->invalidateRefreshSession($payload->audience, $payload->sessionUuid);
        $session = $this->sessions->findActiveByUuid($payload->sessionUuid);

        if ($session !== null) {
            $this->sessions->revoke($session, 'refresh_token_replay_or_state_loss');
        }
    }

    private function configuration(string $audience): Configuration
    {
        $key = config("web_auth.keys.{$audience}");

        if (! is_string($key) || strlen($key) < 32) {
            throw new LogicException('Web authentication signing key is not configured.');
        }

        return Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($key));
    }

    private function issuer(): string
    {
        $issuer = config('web_auth.issuer');

        if (! is_string($issuer) || $issuer === '') {
            throw new LogicException('Web authentication issuer is not configured.');
        }

        return $issuer;
    }

    private function accessTtlMinutes(): int
    {
        return max(1, (int) config('web_auth.access_ttl_minutes', 15));
    }

    private function refreshTtlMinutes(bool $remembered): int
    {
        $key = $remembered ? 'remember_refresh_ttl_minutes' : 'refresh_ttl_minutes';

        return max(1, (int) config("web_auth.{$key}", 1440));
    }

    private function refreshCacheKey(string $audience, string $sessionUuid): string
    {
        return "web_auth:refresh:{$audience}:{$sessionUuid}";
    }

    private function refreshLockKey(string $audience, string $sessionUuid): string
    {
        return "web_auth:refresh_lock:{$audience}:{$sessionUuid}";
    }

    private function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function rawAudience(string $token): ?string
    {
        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            return null;
        }

        $payload = strtr($segments[1], '-_', '+/');
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
        $decoded = base64_decode($payload, true);

        if (! is_string($decoded)) {
            return null;
        }

        try {
            $claims = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $audience = is_array($claims) ? ($claims['aud'] ?? null) : null;

        return is_string($audience) ? $audience : null;
    }
}
