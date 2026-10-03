<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ContactForm;
use App\Services\Public\ContactFormNotificationService;
use Illuminate\Console\Command;

class RecoverPublicContactNotifications extends Command
{
    protected $signature = 'contacts:recover-notifications';

    protected $description = 'Recover pending website contact notifications';

    public function handle(ContactFormNotificationService $notifications): int
    {
        ContactForm::query()
            ->where('channel', ContactForm::CHANNEL_PUBLIC_FORM)
            ->where('notification_delivery->pending', true)
            ->where('notification_delivery->next_attempt_at', '<=', now()->toISOString())
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->each(fn (ContactForm $contactForm) => $notifications->enqueue($contactForm));

        return self::SUCCESS;
    }
}
