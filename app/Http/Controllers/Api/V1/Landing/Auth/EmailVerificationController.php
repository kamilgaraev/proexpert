<?php

namespace App\Http\Controllers\Api\V1\Landing\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EmailVerificationController extends Controller
{
    public function verify(Request $request, $id, $hash): JsonResponse
    {
        try {
            $user = User::findOrFail($id);

            if (!$request->hasValidSignature()) {
                Log::warning('Email verification failed: invalid signature', [
                    'user_id' => $id,
                    'expires' => $request->query('expires'),
                ]);

                return LandingResponse::error(
                    'Неверная ссылка для подтверждения email',
                    403
                );
            }

            if (!hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
                Log::warning('Email verification failed: invalid hash', [
                    'user_id' => $id,
                    'provided_hash' => $hash,
                ]);

                return LandingResponse::error(
                    'Неверная ссылка для подтверждения email',
                    403
                );
            }

            if ($user->hasVerifiedEmail()) {
                return LandingResponse::success(null, 'Email уже подтвержден');
            }

            if ($user->markEmailAsVerified()) {
                event(new Verified($user));

                $this->clearUserProfileCache($user);

                Log::info('Email verified successfully', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                ]);
            }

            return LandingResponse::success(null, 'Email успешно подтвержден');
        } catch (\Throwable $e) {
            Log::error('Email verification failed', [
                'user_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(
                'Ошибка при подтверждении email',
                500
            );
        }
    }

    private function clearUserProfileCache(User $user): void
    {
        Cache::forget("user_with_roles_{$user->id}_" . ($user->current_organization_id ?? 'no_org'));
    }

    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Пользователь не авторизован'
            ], 401);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'success' => false,
                'message' => 'Email уже подтвержден'
            ], 400);
        }

        $user->sendEmailVerificationNotification();

        Log::info('Email verification resent', [
            'user_id' => $user->id,
            'email' => $user->email
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Письмо с подтверждением отправлено повторно'
        ]);
    }

    public function check(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Пользователь не авторизован',
                'verified' => false
            ], 401);
        }

        return response()->json([
            'success' => true,
            'verified' => $user->hasVerifiedEmail(),
            'email' => $user->email
        ]);
    }
}
