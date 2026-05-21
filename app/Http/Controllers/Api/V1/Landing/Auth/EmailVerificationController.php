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
                    'РќРµРІРµСЂРЅР°СЏ СЃСЃС‹Р»РєР° РґР»СЏ РїРѕРґС‚РІРµСЂР¶РґРµРЅРёСЏ email',
                    403
                );
            }

            if (!hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
                Log::warning('Email verification failed: invalid hash', [
                    'user_id' => $id,
                    'provided_hash' => $hash,
                ]);

                return LandingResponse::error(
                    'РќРµРІРµСЂРЅР°СЏ СЃСЃС‹Р»РєР° РґР»СЏ РїРѕРґС‚РІРµСЂР¶РґРµРЅРёСЏ email',
                    403
                );
            }

            if ($user->hasVerifiedEmail()) {
                return LandingResponse::success(null, 'Email СѓР¶Рµ РїРѕРґС‚РІРµСЂР¶РґРµРЅ');
            }

            if ($user->markEmailAsVerified()) {
                event(new Verified($user));

                $this->clearUserProfileCache($user);

                Log::info('Email verified successfully', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                ]);
            }

            return LandingResponse::success(null, 'Email СѓСЃРїРµС€РЅРѕ РїРѕРґС‚РІРµСЂР¶РґРµРЅ');
        } catch (\Throwable $e) {
            Log::error('Email verification failed', [
                'user_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(
                'РћС€РёР±РєР° РїСЂРё РїРѕРґС‚РІРµСЂР¶РґРµРЅРёРё email',
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
            return \App\Http\Responses\LandingResponse::fromPayload([
                'success' => false,
                'message' => 'РџРѕР»СЊР·РѕРІР°С‚РµР»СЊ РЅРµ Р°РІС‚РѕСЂРёР·РѕРІР°РЅ'
            ], 401);
        }

        if ($user->hasVerifiedEmail()) {
            return \App\Http\Responses\LandingResponse::fromPayload([
                'success' => false,
                'message' => 'Email СѓР¶Рµ РїРѕРґС‚РІРµСЂР¶РґРµРЅ'
            ], 400);
        }

        $user->sendEmailVerificationNotification();

        Log::info('Email verification resent', [
            'user_id' => $user->id,
            'email' => $user->email
        ]);

        return \App\Http\Responses\LandingResponse::fromPayload([
            'success' => true,
            'message' => 'РџРёСЃСЊРјРѕ СЃ РїРѕРґС‚РІРµСЂР¶РґРµРЅРёРµРј РѕС‚РїСЂР°РІР»РµРЅРѕ РїРѕРІС‚РѕСЂРЅРѕ'
        ]);
    }

    public function check(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return \App\Http\Responses\LandingResponse::fromPayload([
                'success' => false,
                'message' => 'РџРѕР»СЊР·РѕРІР°С‚РµР»СЊ РЅРµ Р°РІС‚РѕСЂРёР·РѕРІР°РЅ',
                'verified' => false
            ], 401);
        }

        return \App\Http\Responses\LandingResponse::fromPayload([
            'success' => true,
            'verified' => $user->hasVerifiedEmail(),
            'email' => $user->email
        ]);
    }
}
