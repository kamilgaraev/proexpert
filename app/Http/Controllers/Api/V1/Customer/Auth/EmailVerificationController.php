<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customer\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\CustomerResponse;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

use function trans_message;

class EmailVerificationController extends Controller
{
    public function verify(Request $request, int $id, string $hash): JsonResponse
    {
        try {
            $user = User::query()->findOrFail($id);

            if (!hash_equals($hash, sha1($user->getEmailForVerification()))) {
                return CustomerResponse::error(trans_message('customer.auth.email_verify_invalid'), 403);
            }

            $expires = (int) $request->query('expires', 0);

            if ($expires > 0 && $expires < time()) {
                return CustomerResponse::error(trans_message('customer.auth.email_verify_expired'), 403);
            }

            if ($user->hasVerifiedEmail()) {
                return CustomerResponse::success(
                    ['verified' => true],
                    trans_message('customer.auth.email_already_verified')
                );
            }

            if ($user->markEmailAsVerified()) {
                event(new Verified($user));
            }

            return CustomerResponse::success(
                ['verified' => true],
                trans_message('customer.auth.email_verified')
            );
        } catch (Throwable $exception) {
            Log::error('customer.auth.email.verify.failed', [
                'user_id' => $id,
                'ip' => $request->ip(),
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.auth.email_verify_error'), 500);
        }
    }

    public function resend(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user instanceof User) {
                return CustomerResponse::error(trans_message('customer.unauthorized'), 401);
            }

            if ($user->hasVerifiedEmail()) {
                return CustomerResponse::error(trans_message('customer.auth.email_already_verified'), 400);
            }

            $user->sendFrontendEmailVerificationNotification((string) config('app.customer_frontend_url'));

            return CustomerResponse::success(
                ['verified' => false],
                trans_message('customer.auth.email_resent')
            );
        } catch (Throwable $exception) {
            Log::error('customer.auth.email.resend.failed', [
                'user_id' => $request->user()?->id,
                'ip' => $request->ip(),
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.auth.email_resend_error'), 500);
        }
    }

    public function check(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user instanceof User) {
                return CustomerResponse::error(
                    trans_message('customer.unauthorized'),
                    401,
                    null,
                    ['verified' => false]
                );
            }

            return CustomerResponse::success(
                [
                    'verified' => $user->hasVerifiedEmail(),
                    'email' => $user->email,
                ],
                trans_message('customer.auth.email_status_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.auth.email.check.failed', [
                'user_id' => $request->user()?->id,
                'ip' => $request->ip(),
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.auth.email_check_error'), 500);
        }
    }
}
