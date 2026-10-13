<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\DTOs\Auth\WebAuthTokenPayload;
use App\Http\Responses\AdminResponse;
use App\Http\Responses\CustomerResponse;
use App\Http\Responses\LandingResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class WebAuthFailureResponder
{
    public static function reject(Request $request, string $audience, int $status, string $reason): Response
    {
        $payload = $request->attributes->get('web_refresh_payload') ?? $request->attributes->get('web_auth_payload');
        $route = $request->route();
        Log::notice('auth.web.request_rejected', [
            'reason' => $reason,
            'audience' => $audience,
            'method' => $request->method(),
            'path' => $route instanceof Route ? $route->uri() : null,
            'trace_id' => $request->attributes->get('trace_id'),
            'user_id' => $payload instanceof WebAuthTokenPayload ? $payload->userId : null,
            'session_uuid' => $payload instanceof WebAuthTokenPayload ? $payload->sessionUuid : null,
        ]);
        $message = trans_message($status === 401 ? 'errors.unauthenticated' : 'auth.access_denied');
        $extra = ['code' => $reason];

        return match ($audience) {
            'admin' => AdminResponse::error($message, $status, null, $extra),
            'customer' => CustomerResponse::error($message, $status, null, $extra),
            default => LandingResponse::error($message, $status, null, $extra),
        };
    }
}
