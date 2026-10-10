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

final class PublicContactSubmissionTest extends TestCase
{
    public function test_phone_only_contact_is_saved_with_separate_consent_and_queued(): void
    {
        Bus::fake();
        $response = $this->postJson('/api/public/contact', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.email', null)
            ->assertJsonPath('data.phone', '+7 900 123-45-67');

        $id = $response->json('data.id');
        $this->assertDatabaseHas('contact_forms', ['id' => $id, 'email' => null, 'phone' => '+7 900 123-45-67']);
        $this->assertDatabaseHas('legal_acceptance_events', ['reference' => (string) $id, 'document_key' => 'contactConsent']);
        Bus::assertDispatched(SendPublicContactNotification::class, fn ($job): bool => $job->contactFormId === $id);
    }

    public function test_phone_is_required_even_if_email_is_present(): void
    {
        Bus::fake();
        foreach ([null, '', '   '] as $phone) {
            $this->postJson('/api/public/contact', [...$this->payload(), 'email' => 'buyer@example.test', 'phone' => $phone])
                ->assertUnprocessable()->assertJsonValidationErrors('phone');
        }

        $payload = $this->payload();
        unset($payload['phone']);
        $this->postJson('/api/public/contact', $payload)->assertUnprocessable()->assertJsonValidationErrors('phone');
        Bus::assertNotDispatched(SendPublicContactNotification::class);
        $this->assertDatabaseCount('contact_forms', 0);
    }

    public function test_invalid_phone_is_rejected(): void
    {
        Bus::fake();
        foreach (['abc', '123', '++7 900 123-45-67', '1234567890123456'] as $phone) {
            $this->postJson('/api/public/contact', [...$this->payload(), 'phone' => $phone])
                ->assertUnprocessable()->assertJsonValidationErrors('phone');
        }

        Bus::assertNotDispatched(SendPublicContactNotification::class);
    }

    public function test_email_is_validated_when_provided(): void
    {
        Bus::fake();
        $this->postJson('/api/public/contact', [...$this->payload(), 'email' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/public/contact', [...$this->payload(), 'email' => ' buyer@example.test '])
            ->assertCreated()->assertJsonPath('data.email', 'buyer@example.test');
    }

    public function test_phone_only_contact_still_requires_consent(): void
    {
        Bus::fake();
        $this->postJson('/api/public/contact', [...$this->payload(), 'consent_to_personal_data' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('consent_to_personal_data');
        Bus::assertNotDispatched(SendPublicContactNotification::class);
        $this->assertDatabaseCount('legal_acceptance_events', 0);
    }

    public function test_urlencoded_submission_is_saved_and_queued_without_inline_notifications(): void
    {
        Bus::fake();
        Mail::fake();
        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldNotReceive('sendContactFormNotification');
        $this->app->instance(TelegramService::class, $telegram);

        $payload = array_replace($this->payload(), [
            'name' => ' Анна & партнёры ',
            'email' => 'contact+demo@example.test',
            'message' => "Материалы: бетон + арматура & документы.\nВторой объект.",
            'consent_to_personal_data' => '1',
            'page_source' => '/contact#form',
            'utm_source' => 'yandex',
            'analytics_consent' => '0',
        ]);
        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8',
        ])->post('/api/public/contact', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.phone', '+7 900 123-45-67');
        $contact = ContactForm::query()->findOrFail($response->json('data.id'));
        self::assertSame('Анна & партнёры', $contact->name);
        self::assertSame($payload['email'], $contact->email);
        self::assertSame($payload['message'], $contact->message);
        self::assertTrue($contact->consent_to_personal_data);
        self::assertSame(ContactForm::CHANNEL_PUBLIC_FORM, $contact->channel);
        self::assertSame('/contact#form', $contact->page_source);
        self::assertNull($contact->utm_source);
        self::assertTrue($contact->notification_delivery['pending']);
        $this->assertDatabaseHas('legal_acceptance_events', [
            'reference' => (string) $contact->id,
            'document_key' => 'contactConsent',
        ]);
        Bus::assertDispatched(SendPublicContactNotification::class, fn ($job): bool => $job->contactFormId === $contact->id);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_urlencoded_submission_without_consent_is_rejected_without_saving_or_queuing(): void
    {
        Bus::fake();
        Mail::fake();
        foreach (['0', 'false'] as $consent) {
            $payload = array_replace($this->payload(), ['consent_to_personal_data' => $consent]);
            $this->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8',
            ])->post('/api/public/contact', $payload)
                ->assertUnprocessable()
                ->assertJsonPath('success', false)
                ->assertJsonValidationErrors('consent_to_personal_data');
        }

        $this->assertDatabaseCount('contact_forms', 0);
        $this->assertDatabaseCount('legal_acceptance_events', 0);
        Bus::assertNotDispatched(SendPublicContactNotification::class);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    private function payload(): array
    {
        return [
            'name' => 'Test buyer',
            'phone' => ' +7 900 123-45-67 ',
            'subject' => 'Demo',
            'message' => 'Please show the application',
            'consent_to_personal_data' => true,
            'consent_version' => config('legal.version'),
            'legal_documents' => LegalAcceptanceFixture::payload(['contactConsent'])['legal_documents'],
            'page_source' => '/contact',
        ];
    }
}
