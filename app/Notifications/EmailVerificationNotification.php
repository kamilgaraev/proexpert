<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Carbon;

class EmailVerificationNotification extends VerifyEmail
{
    use Queueable;

    public function toMail($notifiable)
    {
        $verificationUrl = $this->verificationUrl($notifiable);

        return (new MailMessage)
            ->subject('Подтверждение email в ProHelper')
            ->markdown('emails.email_verification', [
                'user' => $notifiable,
                'verificationUrl' => $verificationUrl
            ]);
    }

    protected function verificationUrl($notifiable)
    {
        $frontendUrl = env('FRONTEND_URL', config('app.url'));
        
        $params = [
            'id' => $notifiable->getKey(),
            'hash' => sha1($notifiable->getEmailForVerification()),
            'expires' => Carbon::now()->addMinutes(60)->timestamp,
        ];
        
        return $frontendUrl . '/verify-email?' . http_build_query($params);
    }
}

