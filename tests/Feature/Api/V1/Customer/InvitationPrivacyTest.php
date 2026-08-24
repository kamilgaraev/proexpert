<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Customer;

use App\Services\Project\ProjectParticipantInvitationService;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

use function trans_message;

final class InvitationPrivacyTest extends TestCase
{
    public function test_decline_failure_hides_token_and_exception_from_response_and_log_context(): void
    {
        $token = 'secret-invitation-token-that-must-never-be-logged';
        $technicalMessage = 'SQL connection detail that must never reach the client';
        $service = Mockery::mock(ProjectParticipantInvitationService::class);
        $service->shouldReceive('declineByToken')
            ->once()
            ->with($token)
            ->andThrow(new RuntimeException($technicalMessage));
        $this->app->instance(ProjectParticipantInvitationService::class, $service);
        $logger = Log::spy();

        $response = $this->postJson("/api/v1/customer/invitations/{$token}/decline");

        $response
            ->assertStatus(400)
            ->assertJsonPath('message', trans_message('customer.auth.invitation_decline_error'));
        self::assertStringNotContainsString($technicalMessage, (string) $response->getContent());
        self::assertStringNotContainsString($token, (string) $response->getContent());

        $logger->shouldHaveReceived('error')
            ->once()
            ->withArgs(static function (string $event, array $context) use ($token, $technicalMessage): bool {
                $encoded = json_encode($context, JSON_THROW_ON_ERROR);

                return $event === 'customer.invitation.decline.failed'
                    && !str_contains($encoded, $token)
                    && !str_contains($encoded, $technicalMessage)
                    && isset($context['token_fingerprint'], $context['exception_class']);
            });
    }
}
