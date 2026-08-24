<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\DTOs\Auth\LoginDTO;
use App\DTOs\Auth\WebAuthTokenPair;
use App\DTOs\Auth\WebAuthTokenPayload;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use Tymon\JWTAuth\JWT;

final class WebAuthenticationService
{
    public function __construct(
        private readonly JwtAuthService $legacyAuthentication,
        private readonly WebAuthTokenService $tokens,
        private readonly UserAuthSessionService $sessions,
        private readonly JWT $jwt,
    ) {}

    public function authenticate(LoginDTO $credentials, string $audience, string $guard, bool $remembered): array
    {
        $result = $this->legacyAuthentication->authenticate($credentials, $guard);

        return $this->establishFromAuthenticationResult($result, $audience, $remembered);
    }

    public function establishFromAuthenticationResult(array $result, string $audience, bool $remembered): array
    {

        if (! ($result['success'] ?? false)) {
            return $result;
        }

        try {
            $user = $result['auth_user'] ?? $result['user'] ?? null;
            $legacyToken = $result['token'] ?? null;

            if (! $user instanceof User || ! is_string($legacyToken) || $legacyToken === '') {
                throw new RuntimeException('Legacy authentication did not produce an active session.');
            }

            $legacyPayload = $this->jwt->setToken($legacyToken)->getPayload();
            $sessionUuid = $legacyPayload->get('session_uuid');

            if (! is_string($sessionUuid) || ! Str::isUuid($sessionUuid)) {
                throw new RuntimeException('Login session is missing.');
            }

            $session = $this->sessions->findActiveByUuid($sessionUuid);

            if ($session === null || (int) $session->user_id !== (int) $user->id || ! $user->is_active) {
                throw new RuntimeException('Login session is inactive.');
            }

            $organizationId = $user->current_organization_id !== null
                ? (int) $user->current_organization_id
                : null;

            return [
                'success' => true,
                'user' => $result['user'],
                'auth_user' => $user,
                'organization' => $result['organization'] ?? null,
                'email_verified' => $result['email_verified'] ?? true,
                'available_interfaces' => $result['available_interfaces'] ?? [],
                'invitation' => $result['invitation'] ?? null,
                'session_uuid' => $sessionUuid,
                'tokens' => $this->tokens->issue($user, $audience, $sessionUuid, $organizationId, $remembered),
                'status_code' => 200,
            ];
        } catch (Throwable $exception) {
            Log::warning('web_auth.login_session_creation_failed', [
                'audience' => $audience,
                'exception_class' => $exception::class,
            ]);

            return [
                'success' => false,
                'message' => trans_message('auth.login_internal_error'),
                'status_code' => 500,
            ];
        }
    }

    public function refresh(User $user, WebAuthTokenPayload $payload, string $refreshToken): WebAuthTokenPair
    {
        return $this->tokens->rotate($user, $payload, $refreshToken);
    }

    public function logout(User $user, string $audience, string $sessionUuid): void
    {
        $this->tokens->invalidateRefreshSession($audience, $sessionUuid);
        $session = $this->sessions->findActiveByUuid($sessionUuid);

        if ($session !== null && (int) $session->user_id === (int) $user->id) {
            $this->sessions->revoke($session, 'user_logout');
        }
    }
}
