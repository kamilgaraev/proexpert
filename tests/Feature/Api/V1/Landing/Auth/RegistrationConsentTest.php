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

    public function test_registration_records_server_versioned_consent_evidence_atomically(): void
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

        $this->assertDatabaseHas('user_consents', [
            'user_id' => $userId,
            'type' => 'terms',
            'version' => 'terms-2026-08-24',
        ]);
        $this->assertDatabaseHas('user_consents', [
            'user_id' => $userId,
            'type' => 'privacy',
            'version' => 'privacy-2026-08-24',
        ]);
        $this->assertDatabaseCount('user_consents', 2);
    }

    public function test_consent_persistence_failure_rolls_back_user_organization_and_attempt(): void
    {
        Notification::fake();
        config([
            'web_auth.registration.terms_version' => 'terms-valid',
            'web_auth.registration.privacy_version' => str_repeat('x', 65),
        ]);

        $this->registrationRequest('consent-rollback-key')
            ->postJson('/api/v1/landing/auth/register', $this->payload('consent-rollback@example.test'))
            ->assertServerError();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('organization_user', 0);
        $this->assertDatabaseCount('user_consents', 0);
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
            'terms_accepted' => true,
            'privacy_accepted' => true,
        ];
    }
}
