<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

use function trans_message;

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

                return LandingResponse::error(trans_message('landing.email_verification.invalid_link'), 403);
            }

            if (!hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
                Log::warning('Email verification failed: invalid hash', [
                    'user_id' => $id,
                    'provided_hash' => $hash,
                ]);

                return LandingResponse::error(trans_message('landing.email_verification.invalid_link'), 403);
            }

            if ($user->hasVerifiedEmail()) {
                return LandingResponse::success(null, trans_message('landing.email_verification.already_verified'));
            }

            if ($user->markEmailAsVerified()) {
                event(new Verified($user));
                $this->clearUserProfileCache($user);

                Log::info('Email verified successfully', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                ]);
            }

            return LandingResponse::success(null, trans_message('landing.email_verification.verified'));
        } catch (\Throwable $e) {
            Log::error('Email verification failed', [
                'user_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('landing.email_verification.verify_error'), 500);
        }
    }

    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return LandingResponse::error(trans_message('landing.not_authenticated'), 401);
        }

        if ($user->hasVerifiedEmail()) {
            return LandingResponse::error(trans_message('landing.email_verification.already_verified'), 400);
        }

        $user->sendEmailVerificationNotification();

        Log::info('Email verification resent', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        return LandingResponse::success(null, trans_message('landing.email_verification.resent'));
    }

    public function check(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return LandingResponse::error(
                trans_message('landing.not_authenticated'),
                401,
                null,
                ['data' => ['verified' => false]]
            );
        }

        return LandingResponse::success([
            'verified' => $user->hasVerifiedEmail(),
            'email' => $user->email,
        ], trans_message('landing.email_verification.status_loaded'));
    }

    private function clearUserProfileCache(User $user): void
    {
        Cache::forget("user_with_roles_{$user->id}_" . ($user->current_organization_id ?? 'no_org'));
    }
}
