<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

class EmailVerificationNotification extends VerifyEmail
{
    use Queueable;

    public function __construct(
        private readonly ?string $frontendUrl = null
    ) {
    }

    public function toMail($notifiable): MailMessage
    {
        $verificationUrl = $this->verificationUrl($notifiable);

        return (new MailMessage())
            ->subject('Подтверждение email в ProHelper')
            ->markdown('emails.email_verification', [
                'user' => $notifiable,
                'verificationUrl' => $verificationUrl,
            ]);
    }

    protected function verificationUrl($notifiable): string
    {
        $frontendUrl = $this->frontendUrl ?: config('app.frontend_url');
        $routeName = rtrim((string) $frontendUrl, '/') === rtrim((string) config('app.customer_frontend_url'), '/')
            ? 'customer.v1.auth.verification.verify'
            : 'api.v1.landing.verification.verify';
        $signedUrl = URL::temporarySignedRoute(
            $routeName,
            Carbon::now()->addMinutes(60),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );

        return rtrim((string) $frontendUrl, '/') . '/verify-email?' . (string) parse_url($signedUrl, PHP_URL_QUERY);
    }
}
