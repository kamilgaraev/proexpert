<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class RegistrationIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_key_and_payload_replays_the_completed_registration(): void
    {
        Notification::fake();
        $payload = $this->payload('registration-replay@example.test');

        $first = $this->registrationRequest('registration-replay-key')->postJson(
            '/api/v1/landing/auth/register',
            $payload,
        );
        $second = $this->registrationRequest('registration-replay-key')->postJson(
            '/api/v1/landing/auth/register',
            $payload,
        );

        $first->assertCreated();
        $second->assertCreated();
        self::assertSame($first->getContent(), $second->getContent());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseCount('organization_user', 1);
        $this->assertDatabaseCount('auth_registration_attempts', 1);
    }

    public function test_reusing_key_for_different_payload_is_rejected_without_creating_data(): void
    {
        Notification::fake();

        $this->registrationRequest('registration-conflict-key')
            ->postJson('/api/v1/landing/auth/register', $this->payload('registration-first@example.test'))
            ->assertCreated();

        $this->registrationRequest('registration-conflict-key')
            ->postJson('/api/v1/landing/auth/register', $this->payload('registration-second@example.test'))
            ->assertConflict()
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('organizations', 1);
    }

    public function test_missing_idempotency_key_is_rejected_before_registration(): void
    {
        Notification::fake();

        $this->withHeader('Origin', (string) config('web_auth.origins.lk.0'))
            ->postJson('/api/v1/landing/auth/register', $this->payload('registration-no-key@example.test'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_expired_key_can_start_a_new_registration_attempt(): void
    {
        Notification::fake();
        $key = 'registration-expired-key';

        $this->registrationRequest($key)
            ->postJson('/api/v1/landing/auth/register', $this->payload('registration-expired-first@example.test'))
            ->assertCreated();
        $this->app['db']->table('auth_registration_attempts')
            ->where('idempotency_key', $key)
            ->update(['expires_at' => now()->subMinute()]);

        $this->registrationRequest($key)
            ->postJson('/api/v1/landing/auth/register', $this->payload('registration-expired-second@example.test'))
            ->assertCreated();

        $this->assertDatabaseCount('auth_registration_attempts', 1);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('organizations', 2);
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
            'name' => 'Registration Owner',
            'email' => $email,
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
            'organization_name' => 'Registration Organization',
            'terms_accepted' => true,
            'privacy_accepted' => true,
        ];
    }
}
