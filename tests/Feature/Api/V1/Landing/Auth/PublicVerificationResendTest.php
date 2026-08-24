<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing\Auth;

use App\Models\User;
use App\Notifications\EmailVerificationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class PublicVerificationResendTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_unknown_and_verified_email_receive_the_same_opaque_response(): void
    {
        Notification::fake();
        $unverified = User::factory()->create([
            'email' => 'verification-existing@example.test',
            'email_verified_at' => null,
        ]);
        User::factory()->create([
            'email' => 'verification-verified@example.test',
            'email_verified_at' => now(),
        ]);

        $existing = $this->request('Verification-Existing@Example.Test');
        $unknown = $this->request('verification-unknown@example.test');
        $verified = $this->request('verification-verified@example.test');

        $existing->assertOk()->assertJsonPath('success', true);
        $unknown->assertOk()->assertJsonPath('success', true);
        $verified->assertOk()->assertJsonPath('success', true);
        self::assertSame($existing->getContent(), $unknown->getContent());
        self::assertSame($existing->getContent(), $verified->getContent());
        Notification::assertSentTo($unverified, EmailVerificationNotification::class);
        Notification::assertCount(1);
    }

    public function test_endpoint_is_public_but_requires_the_landing_origin_and_auth_throttle(): void
    {
        $route = app('router')->getRoutes()->match(
            request()->create('/api/v1/landing/auth/email/verification-notification', 'POST'),
        );

        self::assertContains('origin.web:lk', $route->gatherMiddleware());
        self::assertContains('throttle:auth', $route->gatherMiddleware());
        self::assertNotContains('auth:api_landing', $route->gatherMiddleware());
    }

    private function request(string $email): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Origin', (string) config('web_auth.origins.lk.0'))
            ->postJson('/api/v1/landing/auth/email/verification-notification', ['email' => $email]);
    }
}
