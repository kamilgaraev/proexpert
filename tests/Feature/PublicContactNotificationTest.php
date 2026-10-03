<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendPublicContactNotification;
use App\Mail\PublicContactFormMail;
use App\Models\ContactForm;
use App\Services\Notification\TelegramService;
use App\Services\Public\ContactFormNotificationService;
use App\Services\Public\ContactFormService;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PublicContactNotificationTest extends TestCase
{
    public function test_submission_is_saved_and_queued_without_sending_notifications_inline(): void
    {
        Bus::fake();
        Mail::fake();
        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldNotReceive('sendContactFormNotification');
        $this->app->instance(TelegramService::class, $telegram);

        $contact = app(ContactFormService::class)->submit($this->payload());

        self::assertSame('new', $contact->status);
        self::assertTrue($contact->notification_delivery['pending']);
        self::assertSame(0, $contact->notification_delivery['attempts']);
        self::assertFalse($contact->is_processed);
        Bus::assertDispatched(SendPublicContactNotification::class, fn ($job) => $job->contactFormId === $contact->id && $job->connection === 'redis' && $job->queue === 'notifications');
        Mail::assertNothingSent();
    }

    public function test_partial_success_is_persisted_and_retry_does_not_duplicate_email(): void
    {
        config(['telegram.notifications.contact_forms' => true, 'services.public_contact.recipients' => ['sales@example.test']]);
        Mail::fake();
        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendContactFormNotification')->once()->andReturn(false);
        $this->app->instance(TelegramService::class, $telegram);
        $contact = $this->contact();
        $notifications = app(ContactFormNotificationService::class);

        self::assertFalse($notifications->deliver($contact));
        self::assertTrue($contact->fresh()->is_processed);
        self::assertNotEmpty($contact->fresh()->notification_delivery['email_sent_at']);

        $retryTelegram = Mockery::mock(TelegramService::class);
        $retryTelegram->shouldReceive('sendContactFormNotification')->once()->andReturnUsing(function (ContactForm $model): bool {
            $model->update(['telegram_data' => ['sent_at' => now()->toISOString(), 'message_id' => 123]]);

            return true;
        });
        $this->app->instance(TelegramService::class, $retryTelegram);
        self::assertTrue($notifications->deliver($contact->fresh()));
        self::assertFalse($contact->fresh()->notification_delivery['pending']);
        Mail::assertSent(PublicContactFormMail::class, 1);
        self::assertTrue($notifications->deliver($contact->fresh()));
        Mail::assertSent(PublicContactFormMail::class, 1);
    }

    public function test_email_failure_keeps_delivery_pending_and_telegram_is_not_resent(): void
    {
        config(['telegram.notifications.contact_forms' => true, 'services.public_contact.recipients' => ['sales@example.test']]);
        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldNotReceive('sendContactFormNotification');
        $this->app->instance(TelegramService::class, $telegram);
        $pendingMail = Mockery::mock(PendingMail::class);
        $pendingMail->shouldReceive('send')->once()->andThrow(new RuntimeException('simulated mail failure'));
        Mail::shouldReceive('to')->once()->andReturn($pendingMail);
        $contact = $this->contact();
        $contact->update(['telegram_data' => ['sent_at' => now()->toISOString(), 'message_id' => 123]]);

        self::assertFalse(app(ContactFormNotificationService::class)->deliver($contact));
        self::assertTrue($contact->fresh()->notification_delivery['pending']);
        self::assertArrayNotHasKey('email_sent_at', $contact->fresh()->notification_delivery);
    }

    public function test_recovery_dispatches_only_due_new_notifications_and_preserves_legacy_forms(): void
    {
        Bus::fake();
        $due = $this->contact();
        $future = $this->contact();
        $future->update(['notification_delivery' => ['pending' => true, 'next_attempt_at' => now()->addHour()->toISOString()]]);
        $legacy = ContactForm::create($this->payload());

        $this->artisan('contacts:recover-notifications')->assertExitCode(0);

        Bus::assertDispatchedTimes(SendPublicContactNotification::class, 1);
        Bus::assertDispatched(SendPublicContactNotification::class, fn ($job) => $job->contactFormId === $due->id);
        self::assertNull($legacy->fresh()->notification_delivery);
    }

    public function test_missing_configuration_stops_after_ten_attempts_without_falsely_marking_processed(): void
    {
        config(['telegram.notifications.contact_forms' => false, 'services.public_contact.recipients' => []]);
        $contact = $this->contact();
        $contact->update(['notification_delivery' => ['pending' => true, 'attempts' => 9]]);

        self::assertTrue(app(ContactFormNotificationService::class)->deliver($contact));
        self::assertFalse($contact->fresh()->is_processed);
        self::assertFalse($contact->fresh()->notification_delivery['pending']);
        self::assertNotEmpty($contact->fresh()->notification_delivery['failed_at']);
    }

    private function contact(): ContactForm
    {
        return ContactForm::create(array_replace($this->payload(), [
            'channel' => ContactForm::CHANNEL_PUBLIC_FORM,
            'notification_delivery' => ['pending' => true, 'attempts' => 0, 'next_attempt_at' => now()->toISOString()],
        ]))->refresh();
    }

    private function payload(): array
    {
        return ['name' => 'Test buyer', 'email' => 'buyer@example.test', 'subject' => 'Demo',
            'message' => 'Please show the application', 'consent_to_personal_data' => true,
            'consent_version' => 'test', 'page_source' => '/contact'];
    }
}
