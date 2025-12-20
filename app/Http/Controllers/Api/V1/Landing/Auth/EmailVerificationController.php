<?php

namespace App\Http\Controllers\Api\V1\Landing\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Auth\Events\Verified;

class EmailVerificationController extends Controller
{
    public function verify(Request $request, $id, $hash): JsonResponse
    {
        $user = User::findOrFail($id);

        if (!hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            Log::warning('Email verification failed: invalid hash', [
                'user_id' => $id,
                'provided_hash' => $hash
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Неверная ссылка для подтверждения email'
            ], 403);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'success' => true,
                'message' => 'Email уже подтвержден'
            ]);
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
            
            Log::info('Email verified successfully', [
                'user_id' => $user->id,
                'email' => $user->email
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Email успешно подтвержден'
        ]);
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

