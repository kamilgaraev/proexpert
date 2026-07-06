<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing\Auth;

use App\DTOs\Auth\RegisterDTO;
use App\Services\Auth\JwtAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class LandingRegistrationVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_registration_endpoint_requires_email_verification_without_token_or_cookie(): void
    {
        Notification::fake();
        config(['auth_tokens.sessions.enabled' => true]);

        $response = $this->postJson('/api/v1/landing/auth/register', $this->registrationPayload(
            'landing-register-contract@example.com'
        ));

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'verification_required')
            ->assertJsonPath('data.email_verified', false)
            ->assertJsonPath('data.can_enter_portal', false)
            ->assertJsonMissingPath('data.token');

        $payload = $response->json();
        $cookieNames = array_map(
            static fn ($cookie): string => $cookie->getName(),
            $response->headers->getCookies()
        );

        $this->assertArrayNotHasKey('token', $payload);
        $this->assertNotContains((string) config('auth_tokens.landing_cookie.name'), $cookieNames);
    }

    public function test_landing_registration_service_does_not_create_session_or_return_token(): void
    {
        Notification::fake();
        config(['auth_tokens.sessions.enabled' => true]);

        $result = app(JwtAuthService::class)->register(
            RegisterDTO::fromRequest($this->registrationPayload('landing-register-service@example.com'))
        );

        $this->assertTrue($result['success']);
        $this->assertSame('verification_required', $result['status']);
        $this->assertSame(false, $result['email_verified']);
        $this->assertSame(false, $result['can_enter_portal']);
        $this->assertArrayNotHasKey('token', $result);
        $this->assertDatabaseCount('user_auth_sessions', 0);
    }

    /**
     * @return array<string, string>
     */
    private function registrationPayload(string $email): array
    {
        return [
            'name' => 'Registration Owner',
            'email' => $email,
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
            'organization_name' => 'Registration Contract Organization',
        ];
    }
}
