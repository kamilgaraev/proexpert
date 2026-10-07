<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class RegistrationConsentTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_requires_both_consents(): void
    {
        Notification::fake();
        $payload = $this->payload('consent-missing@example.test');
        unset($payload['terms_accepted'], $payload['privacy_accepted']);

        $this->registrationRequest('consent-missing-key')
            ->postJson('/api/v1/landing/auth/register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['terms_accepted', 'privacy_accepted']);

        $payload = $this->payload('consent-false@example.test');
        $payload['terms_accepted'] = false;

        $this->registrationRequest('consent-false-key')
            ->postJson('/api/v1/landing/auth/register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('terms_accepted');
    }

    public function test_registration_with_empty_requisites_records_versioned_evidence_atomically(): void
    {
        Notification::fake();
        config([
            'web_auth.registration.terms_version' => 'terms-2026-08-24',
            'web_auth.registration.privacy_version' => 'privacy-2026-08-24',
        ]);

        $this->registrationRequest('consent-record-key')
            ->postJson('/api/v1/landing/auth/register', $this->payload('consent-record@example.test'))
            ->assertCreated();

        $userId = (int) $this->app['db']->table('users')
            ->where('email', 'consent-record@example.test')
            ->value('id');

        $this->assertDatabaseHas('legal_acceptance_events', [
            'user_id' => $userId,
            'document_key' => 'offer',
            'version' => config('legal.version'),
        ]);
        $this->assertDatabaseHas('legal_acceptance_events', [
            'user_id' => $userId,
            'document_key' => 'privacy',
            'action' => 'acknowledged',
            'version' => config('legal.version'),
        ]);
        $this->assertDatabaseCount('legal_acceptance_events', 3);
        $this->assertDatabaseCount('user_consents', 0);
        $snapshot = $this->app['db']->table('legal_acceptance_events')->where('document_key', 'offer')->value('snapshot');
        self::assertSame('', json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR)['provider']['name']);
    }

    public function test_consent_persistence_failure_rolls_back_user_organization_and_attempt(): void
    {
        Notification::fake();
        \Illuminate\Support\Facades\Event::listen('eloquent.creating: App\\Models\\LegalAcceptanceEvent', static function ($event): void {
            if ($event->document_key === 'processing') {
                throw new \RuntimeException('Simulated evidence persistence failure');
            }
        });
        $organizationsBefore = $this->app['db']->table('organizations')->pluck('name', 'id')->all();

        try {
            $this->registrationRequest('consent-rollback-key')
                ->postJson('/api/v1/landing/auth/register', $this->payload('consent-rollback@example.test'))
                ->assertServerError();
        } finally {
            \Illuminate\Support\Facades\Event::forget('eloquent.creating: App\\Models\\LegalAcceptanceEvent');
        }

        $this->assertDatabaseCount('users', 0);
        self::assertSame($organizationsBefore, $this->app['db']->table('organizations')->pluck('name', 'id')->all());
        $this->assertDatabaseMissing('organizations', ['name' => 'Consent Organization']);
        $this->assertDatabaseCount('organization_user', 0);
        $this->assertDatabaseCount('user_consents', 0);
        $this->assertDatabaseCount('legal_acceptance_events', 0);
        $this->assertDatabaseCount('auth_registration_attempts', 0);
    }

    private function registrationRequest(string $key): self
    {
        return $this->withHeaders([
            'Origin' => (string) config('web_auth.origins.lk.0'),
            'Idempotency-Key' => $key,
        ]);
    }

    /** @return array<string, bool|string> */
    private function payload(string $email): array
    {
        return [
            'name' => 'Consent Owner',
            'email' => $email,
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
            'organization_name' => 'Consent Organization',
            ...\Tests\Support\LegalAcceptanceFixture::payload(['offer', 'processing', 'privacy']),
            'terms_accepted' => true,
            'privacy_accepted' => true,
        ];
    }
}
