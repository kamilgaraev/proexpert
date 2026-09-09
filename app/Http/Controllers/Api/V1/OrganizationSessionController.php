<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\Auth\WebAuthTokenPayload;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SwitchOrganizationRequest;
use App\Http\Responses\AdminResponse;
use App\Http\Responses\LandingResponse;
use App\Services\Auth\OrganizationSessionService;
use App\Services\Auth\WebRefreshCookieService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final class OrganizationSessionController extends Controller
{
    public function __construct(
        private readonly OrganizationSessionService $organizations,
        private readonly WebRefreshCookieService $cookies,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $payload = $request->attributes->get('web_auth_payload');
        abort_unless($payload instanceof WebAuthTokenPayload && $request->user() !== null, 401);
        $response = $payload->audience === 'admin' ? AdminResponse::class : LandingResponse::class;

        return $response::success(['organizations' => $this->organizations->choices($request->user(), $payload->audience)]);
    }

    public function switch(SwitchOrganizationRequest $request): JsonResponse
    {
        $payload = $request->attributes->get('web_auth_payload');
        abort_unless($payload instanceof WebAuthTokenPayload && $request->user() !== null, 401);
        $response = $payload->audience === 'admin' ? AdminResponse::class : LandingResponse::class;

        try {
            $tokens = $this->organizations->switch($request->user(), $payload,
                (int) $request->validated('organization_id'), (string) $request->header('X-CSRF-Token'));

            return $response::success([
                'token' => $tokens->accessToken,
                'csrf_token' => $tokens->csrfToken,
                'token_type' => 'bearer',
                'expires_in' => max(0, $tokens->accessExpiresAt->getTimestamp() - time()),
            ])->withCookie($this->cookies->make($payload->audience, $tokens->refreshToken, $tokens->refreshExpiresAt));
        } catch (AuthorizationException) {
            return $response::error(trans_message('organization.access_denied'), 403);
        } catch (Throwable $exception) {
            Log::error('auth.organization_switch_failed', ['user_id' => $request->user()->id, 'exception_class' => $exception::class]);

            return $response::error(trans_message('auth.server_error'), 500);
        }
    }
}
