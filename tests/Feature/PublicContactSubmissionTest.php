<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendPublicContactNotification;
use App\Models\ContactForm;
use App\Services\Notification\TelegramService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\Support\LegalAcceptanceFixture;
use Tests\TestCase;

class PublicContactSubmissionTest extends TestCase
{
    public function test_urlencoded_submission_is_saved_and_queued_without_inline_notifications(): void
    {
        Bus::fake();
        Mail::fake();
        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldNotReceive('sendContactFormNotification');
        $this->app->instance(TelegramService::class, $telegram);

        $payload = $this->payload();
        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8',
        ])->post('/api/public/contact', $payload);

        $response->assertCreated()->assertJsonPath('success', true);
        $contact = ContactForm::query()->where('email', $payload['email'])->sole();
        self::assertSame('Анна & партнёры', $contact->name);
        self::assertSame($payload['message'], $contact->message);
        self::assertTrue($contact->consent_to_personal_data);
        self::assertSame(ContactForm::CHANNEL_PUBLIC_FORM, $contact->channel);
        self::assertSame('/contact#form', $contact->page_source);
        self::assertNull($contact->utm_source);
        self::assertTrue($contact->notification_delivery['pending']);
        Bus::assertDispatched(SendPublicContactNotification::class, fn ($job) => $job->contactFormId === $contact->id);
        Mail::assertNothingSent();
    }

    public function test_urlencoded_submission_without_consent_is_rejected_without_saving_or_queuing(): void
    {
        Bus::fake();
        Mail::fake();
        $payload = array_replace($this->payload(), ['consent_to_personal_data' => 'false']);

        $this->withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8',
        ])->post('/api/public/contact', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors('consent_to_personal_data');

        $this->assertDatabaseMissing('contact_forms', ['email' => $payload['email']]);
        Bus::assertNotDispatched(SendPublicContactNotification::class);
        Mail::assertNothingSent();
    }

    private function payload(): array
    {
        return [
            'name' => ' Анна & партнёры ',
            'email' => 'contact+demo@example.test',
            'subject' => 'Запрос демонстрации',
            'message' => "Материалы: бетон + арматура & документы.\nВторой объект.",
            'consent_to_personal_data' => 'true',
            'consent_version' => config('legal.version'),
            'page_source' => '/contact#form',
            'utm_source' => 'yandex',
            'analytics_consent' => '0',
            'legal_documents' => LegalAcceptanceFixture::payload(['contactConsent'])['legal_documents'],
        ];
    }
}
