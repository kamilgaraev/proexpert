<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Landing\Support\StoreSupportRequest;
use App\Http\Responses\LandingResponse;
use App\Mail\SupportRequestMail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SupportController extends Controller
{
    public function store(StoreSupportRequest $request): JsonResponse
    {
        /** @var User|null $user */
        $user = Auth::user();
        $validated = $request->validated();

        $senderName = $user?->name ?? (string) ($validated['name'] ?? '');
        $senderEmail = $user?->email ?? (string) ($validated['email'] ?? '');
        $supportEmail = (string) config('mail.support_address', config('mail.from.address'));

        try {
            Mail::to($supportEmail)->send(new SupportRequestMail(
                senderName: $senderName,
                senderEmail: $senderEmail,
                subjectText: $validated['subject'],
                messageText: $validated['message'],
                userId: $user?->id,
            ));
        } catch (Throwable $e) {
            Log::error('landing.support.mail_failed', [
                'user_id' => $user?->id,
                'sender_email' => $senderEmail,
                'recipient' => $supportEmail,
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(
                trans_message('support.request_failed'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return LandingResponse::success(null, trans_message('support.request_sent'));
    }
}
