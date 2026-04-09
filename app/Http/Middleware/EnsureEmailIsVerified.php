<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\CustomerResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

use function trans_message;

class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $this->unauthorizedResponse();
        }

        if (!$user->hasVerifiedEmail()) {
            return CustomerResponse::error(
                trans_message('customer.auth.email_verification_required'),
                403,
                null,
                [
                    'email_verified' => false,
                    'status' => 'verification_required',
                ]
            );
        }

        return $next($request);
    }

    private function unauthorizedResponse(): JsonResponse
    {
        return CustomerResponse::error(
            trans_message('customer.unauthorized'),
            401
        );
    }
}
