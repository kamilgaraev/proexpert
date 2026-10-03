<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ContactForm;
use App\Services\Public\ContactFormNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use RuntimeException;

class SendPublicContactNotification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 75;

    public int $uniqueFor = 600;

    public function __construct(public int $contactFormId)
    {
        $this->onConnection('redis')->onQueue('notifications');
    }

    public function uniqueId(): string
    {
        return 'public-contact:'.$this->contactFormId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->releaseAfter(30)->expireAfter(120)];
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(ContactFormNotificationService $notifications): void
    {
        $contactForm = ContactForm::find($this->contactFormId);
        if ($contactForm && ! $notifications->deliver($contactForm)) {
            throw new RuntimeException('Public contact notification delivery pending');
        }
    }
}
