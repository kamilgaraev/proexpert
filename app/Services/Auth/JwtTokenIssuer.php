<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

final class JwtTokenIssuer
{
    public function __construct(
        private readonly UserAuthSessionService $authSessionService
    ) {
    }

    /**
     * @param array{guard:string, organization_id?:int|null, brigade_id?:int|null, request?:Request|null, session_uuid?:string|null} $context
     */
    public function issue(User $user, array $context): string
    {
        $claims = [];

        if (array_key_exists('organization_id', $context)) {
            $claims['organization_id'] = $context['organization_id'];
        }

        if (array_key_exists('brigade_id', $context)) {
            $claims['brigade_id'] = $context['brigade_id'];
        }

        if ((bool) config('auth_tokens.sessions.enabled', true)) {
            $organizationId = isset($context['organization_id']) && $context['organization_id'] !== null
                ? (int) $context['organization_id']
                : null;
            $sessionUuid = $context['session_uuid'] ?? null;
            $existingSession = is_string($sessionUuid) && Str::isUuid($sessionUuid)
                ? $this->authSessionService->findActiveByUuid($sessionUuid)
                : null;

            if ($existingSession !== null && (int) $existingSession->user_id === (int) $user->id) {
                $claims['session_uuid'] = $existingSession->session_uuid;
            } else {
                $request = $context['request'] ?? request();
                $authSession = $this->authSessionService->createForLogin($user, $organizationId, $request);

                $claims['session_uuid'] = $authSession->session_uuid;
            }
        }

        return JWTAuth::claims($claims)->fromUser($user);
    }
}
