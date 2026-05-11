<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\ContractorInvitation;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\SubscriptionLimitsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ContractorInvitationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Notification::fake();
    }

    public function test_index_separates_sent_and_received_invitations_by_current_organization(): void
    {
        $this->allowAdminAccess();
        $context = AdminApiTestContext::create();
        $recipientContext = AdminApiTestContext::create();
        $foreignContext = AdminApiTestContext::create();

        $sentInvitation = $this->createInvitation(
            $context->organization,
            $recipientContext->organization,
            $context->user,
            ['invitation_message' => 'Sent invitation']
        );
        $receivedInvitation = $this->createInvitation(
            $recipientContext->organization,
            $context->organization,
            $recipientContext->user,
            ['invitation_message' => 'Received invitation']
        );
        $this->createInvitation(
            $foreignContext->organization,
            $recipientContext->organization,
            $foreignContext->user,
            ['invitation_message' => 'Foreign invitation']
        );

        $sentResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/contractor-invitations?type=sent&per_page=10');

        $sentResponse->assertOk();
        $sentResponse->assertJsonPath('success', true);
        $sentResponse->assertJsonPath('data.data.data.0.id', $sentInvitation->id);
        $sentResponse->assertJsonPath('data.meta.type', 'sent');

        $sentIds = collect($sentResponse->json('data.data.data'))->pluck('id')->all();

        $this->assertContains($sentInvitation->id, $sentIds);
        $this->assertNotContains($receivedInvitation->id, $sentIds);

        $receivedResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/contractor-invitations?type=received&per_page=10');

        $receivedResponse->assertOk();
        $receivedResponse->assertJsonPath('success', true);
        $receivedResponse->assertJsonPath('data.data.data.0.id', $receivedInvitation->id);
        $receivedResponse->assertJsonPath('data.meta.type', 'received');

        $receivedIds = collect($receivedResponse->json('data.data.data'))->pluck('id')->all();

        $this->assertContains($receivedInvitation->id, $receivedIds);
        $this->assertNotContains($sentInvitation->id, $receivedIds);
    }

    public function test_show_allows_sender_and_recipient_but_hides_unrelated_invitation(): void
    {
        $this->allowAdminAccess();
        $senderContext = AdminApiTestContext::create();
        $recipientOrganization = $this->attachOrganizationContextToUser($senderContext->user);
        $outsiderOrganization = $this->attachOrganizationContextToUser($senderContext->user);
        $invitation = $this->createInvitation(
            $senderContext->organization,
            $recipientOrganization,
            $senderContext->user,
            ['invitation_message' => 'Visible to both sides']
        );

        $senderResponse = $this->withHeaders($senderContext->authHeaders())
            ->getJson("/api/v1/admin/contractor-invitations/{$invitation->id}");

        $senderResponse->assertOk();
        $senderResponse->assertJsonPath('success', true);
        $senderResponse->assertJsonPath('data.id', $invitation->id);
        $senderResponse->assertJsonPath('data.invited_organization.id', $recipientOrganization->id);

        $this->resetAdminGuard();

        $recipientResponse = $this->withHeaders($this->authHeadersFor($senderContext->user, $recipientOrganization))
            ->getJson("/api/v1/admin/contractor-invitations/{$invitation->id}");

        $recipientResponse->assertOk();
        $recipientResponse->assertJsonPath('success', true);
        $recipientResponse->assertJsonPath('data.id', $invitation->id);
        $recipientResponse->assertJsonPath('data.organization.id', $senderContext->organization->id);

        $this->resetAdminGuard();

        $outsiderResponse = $this->withHeaders($this->authHeadersFor($senderContext->user, $outsiderOrganization))
            ->getJson("/api/v1/admin/contractor-invitations/{$invitation->id}");

        $outsiderResponse->assertNotFound();
        $outsiderResponse->assertJsonPath('success', false);
    }

    public function test_cancel_is_allowed_only_for_sender_pending_invitation(): void
    {
        $this->allowAdminAccess();
        $senderContext = AdminApiTestContext::create();
        $recipientOrganization = $this->attachOrganizationContextToUser($senderContext->user);
        $invitation = $this->createInvitation(
            $senderContext->organization,
            $recipientOrganization,
            $senderContext->user
        );

        $recipientCancelResponse = $this->withHeaders($this->authHeadersFor($senderContext->user, $recipientOrganization))
            ->patchJson("/api/v1/admin/contractor-invitations/{$invitation->id}/cancel");

        $recipientCancelResponse->assertNotFound();
        $recipientCancelResponse->assertJsonPath('success', false);
        $this->assertSame(ContractorInvitation::STATUS_PENDING, $invitation->fresh()->status);

        $this->resetAdminGuard();

        $senderCancelResponse = $this->withHeaders($senderContext->authHeaders())
            ->patchJson("/api/v1/admin/contractor-invitations/{$invitation->id}/cancel");

        $senderCancelResponse->assertOk();
        $senderCancelResponse->assertJsonPath('success', true);
        $this->assertSame(ContractorInvitation::STATUS_EXPIRED, $invitation->fresh()->status);

        $this->resetAdminGuard();

        $secondCancelResponse = $this->withHeaders($senderContext->authHeaders())
            ->patchJson("/api/v1/admin/contractor-invitations/{$invitation->id}/cancel");

        $secondCancelResponse->assertNotFound();
        $secondCancelResponse->assertJsonPath('success', false);
    }

    public function test_store_creates_pending_invitation_and_rejects_duplicate_active_invitation(): void
    {
        $this->allowAdminAccess();
        $this->allowContractorInvitationLimit();
        $context = AdminApiTestContext::create();
        $targetOrganization = Organization::factory()->verified()->create([
            'name' => 'Target Contractor',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/contractor-invitations', [
                'invited_organization_id' => $targetOrganization->id,
                'message' => 'Please join the project network',
                'metadata' => [
                    'source' => 'admin-test',
                ],
            ]);

        $response->assertCreated();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.status', ContractorInvitation::STATUS_PENDING);
        $response->assertJsonPath('data.invited_organization.id', $targetOrganization->id);
        $response->assertJsonPath('data.metadata.source', 'admin-test');

        $this->assertDatabaseHas('contractor_invitations', [
            'organization_id' => $context->organization->id,
            'invited_organization_id' => $targetOrganization->id,
            'status' => ContractorInvitation::STATUS_PENDING,
        ]);

        $duplicateResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/contractor-invitations', [
                'invited_organization_id' => $targetOrganization->id,
            ]);

        $duplicateResponse->assertStatus(422);
        $duplicateResponse->assertJsonValidationErrors(['invited_organization_id']);
        $this->assertStringContainsString(
            'Активное приглашение для данной организации уже существует.',
            $duplicateResponse->json('errors.invited_organization_id.0')
        );
    }

    private function allowAdminAccess(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
        });
    }

    private function allowContractorInvitationLimit(): void
    {
        $this->mock(SubscriptionLimitsService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canCreateContractorInvitation')->andReturn(true);
        });
    }

    private function resetAdminGuard(): void
    {
        auth()->guard('api_admin')->forgetUser();
    }

    private function attachOrganizationContextToUser(User $user): Organization
    {
        $organization = Organization::factory()->verified()->create();

        $organization->users()->attach($user->id, [
            'is_owner' => true,
            'is_active' => true,
            'settings' => null,
        ]);

        \App\Domain\Authorization\Models\UserRoleAssignment::assignRole(
            user: $user,
            roleSlug: 'web_admin',
            context: \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($organization->id)
        );

        return $organization;
    }

    private function authHeadersFor(User $user, Organization $organization): array
    {
        return [
            'Authorization' => 'Bearer ' . JWTAuth::claims([
                'organization_id' => $organization->id,
            ])->fromUser($user),
            'Accept' => 'application/json',
        ];
    }

    private function createInvitation(
        Organization $organization,
        Organization $invitedOrganization,
        mixed $invitedBy,
        array $attributes = []
    ): ContractorInvitation {
        return ContractorInvitation::query()->create(array_merge([
            'organization_id' => $organization->id,
            'invited_organization_id' => $invitedOrganization->id,
            'invited_by_user_id' => $invitedBy->id,
            'status' => ContractorInvitation::STATUS_PENDING,
            'expires_at' => now()->addDays(7),
        ], $attributes));
    }
}
