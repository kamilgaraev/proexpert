<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

final class JwtTokenIssuer
{
    public function __construct(
        private readonly UserAuthSessionService $authSessionService
    ) {
    }

    /**
     * @param array{guard:string, organization_id?:int|null, brigade_id?:int|null, request?:Request|null} $context
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
            $request = $context['request'] ?? request();
            $authSession = $this->authSessionService->createForLogin($user, $organizationId, $request);

            $claims['session_uuid'] = $authSession->session_uuid;
        }

        return JWTAuth::claims($claims)->fromUser($user);
    }
}
