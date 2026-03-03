<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\OrganizationModuleActivation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class TrialExpiredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected OrganizationModuleActivation $activation
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $moduleName = $this->activation->module->name ?? 'Модуль';
        $organizationName = $this->activation->organization->name ?? 'вашей организации';
        $lkUrl = config('app.frontend_url', config('app.url'));

        return (new MailMessage)
            ->subject("Триальный период модуля «{$moduleName}» завершён — ProHelper")
            ->markdown('emails.trial_expired', [
                'moduleName' => $moduleName,
                'organizationName' => $organizationName,
                'lkUrl' => $lkUrl,
            ]);
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'trial_expired',
            'module_id' => $this->activation->module_id,
            'module_name' => $this->activation->module->name ?? null,
            'organization_id' => $this->activation->organization_id,
            'organization_name' => $this->activation->organization->name ?? null,
            'expired_at' => $this->activation->trial_ends_at?->toISOString(),
        ];
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
