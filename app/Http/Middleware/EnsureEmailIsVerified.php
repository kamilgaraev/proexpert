<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Пользователь не авторизован'
            ], 401);
        }

        if (!$user->hasVerifiedEmail()) {
            Log::info('Access denied: email not verified', [
                'user_id' => $user->id,
                'email' => $user->email,
                'route' => $request->route()->getName()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Пожалуйста, подтвердите ваш email адрес',
                'email_verified' => false
            ], 403);
        }

        return $next($request);
    }
}

