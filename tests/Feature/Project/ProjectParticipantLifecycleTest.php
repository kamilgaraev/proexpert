<?php

declare(strict_types=1);

namespace Tests\Feature\Project;

use App\Enums\ProjectOrganizationRole;
use App\Enums\AuthSessionStatus;
use App\Exceptions\BusinessLogicException;
use App\Mail\ProjectParticipantInvitationMail;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectParticipantInvitation;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Repositories\UserRepository;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Services\Auth\WebAuthTokenService;
use Illuminate\Support\Str;
use App\Services\Project\ProjectParticipantInvitationService;
use App\Services\Project\ProjectParticipantService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProjectParticipantLifecycleTest extends TestCase
{
    private Organization $ownerOrganization;
    private User $ownerUser;
    private Project $project;
    private ProjectParticipantService $participantService;
    private ProjectParticipantInvitationService $invitationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerOrganization = Organization::factory()->create();
        $this->ownerUser = $this->createOrganizationUser($this->ownerOrganization);
        $this->project = Project::factory()->create([
            'organization_id' => $this->ownerOrganization->id,
        ]);

        $this->participantService = app(ProjectParticipantService::class);
        $this->invitationService = app(ProjectParticipantInvitationService::class);
    }

    public function test_it_enforces_single_active_customer_and_allows_switch_after_deactivation(): void
    {
        $firstCustomer = Organization::factory()->create();
        $secondCustomer = Organization::factory()->create();

        $this->participantService->attach(
            $this->project,
            $firstCustomer->id,
            ProjectOrganizationRole::CUSTOMER,
            $this->ownerUser
        );

        try {
            $this->participantService->attach(
                $this->project,
                $secondCustomer->id,
                ProjectOrganizationRole::CUSTOMER,
                $this->ownerUser
            );

            $this->fail('Ожидалась ошибка при добавлении второго активного заказчика.');
        } catch (BusinessLogicException $exception) {
            $this->assertSame(409, $exception->getCode());
        }

        $this->participantService->setActiveState($this->project, $firstCustomer->id, false);
        $this->participantService->attach(
            $this->project,
            $secondCustomer->id,
            ProjectOrganizationRole::CUSTOMER,
            $this->ownerUser
        );

        $this->assertDatabaseHas('project_organization', [
            'project_id' => $this->project->id,
            'organization_id' => $firstCustomer->id,
            'role_new' => ProjectOrganizationRole::CUSTOMER->value,
            'is_active' => false,
        ]);

        $this->assertDatabaseHas('project_organization', [
            'project_id' => $this->project->id,
            'organization_id' => $secondCustomer->id,
            'role_new' => ProjectOrganizationRole::CUSTOMER->value,
            'is_active' => true,
        ]);
    }

    public function test_it_rejects_expired_and_cancelled_invites_and_resend_rotates_token(): void
    {
        $targetOrganization = Organization::factory()->create();
        $targetUser = $this->createOrganizationUser($targetOrganization);

        $expiredInvitation = $this->invitationService->create(
            $this->project,
            $this->ownerOrganization->id,
            $this->ownerUser,
            [
                'organization_id' => $targetOrganization->id,
                'role' => ProjectOrganizationRole::CUSTOMER->value,
            ]
        );
        $expiredInvitation->update(['expires_at' => now()->subDay()]);

        try {
            $this->invitationService->acceptByToken($expiredInvitation->token, $targetUser, $targetOrganization);
            $this->fail('Ожидалась ошибка при принятии просроченного приглашения.');
        } catch (BusinessLogicException $exception) {
            $this->assertSame(410, $exception->getCode());
        }

        $cancelledInvitation = $this->invitationService->create(
            $this->project,
            $this->ownerOrganization->id,
            $this->ownerUser,
            [
                'organization_id' => $targetOrganization->id,
                'role' => ProjectOrganizationRole::CUSTOMER->value,
            ]
        );
        $this->invitationService->cancel($this->project, $cancelledInvitation, $this->ownerUser);

        try {
            $this->invitationService->acceptByToken($cancelledInvitation->token, $targetUser, $targetOrganization);
            $this->fail('Ожидалась ошибка при принятии отмененного приглашения.');
        } catch (BusinessLogicException $exception) {
            $this->assertSame(410, $exception->getCode());
        }

        $resendOrganization = Organization::factory()->create();
        $resendUser = $this->createOrganizationUser($resendOrganization);

        $resendInvitation = $this->invitationService->create(
            $this->project,
            $this->ownerOrganization->id,
            $this->ownerUser,
            [
                'organization_id' => $resendOrganization->id,
                'role' => ProjectOrganizationRole::OBSERVER->value,
            ]
        );

        $oldToken = $resendInvitation->token;
        $oldExpiresAt = $resendInvitation->expires_at;

        $resentInvitation = $this->invitationService->resend($this->project, $resendInvitation, $this->ownerUser);

        $this->assertNotSame($oldToken, $resentInvitation->token);
        $this->assertSame(ProjectParticipantInvitation::STATUS_PENDING, $resentInvitation->status);
        $this->assertNotNull($resentInvitation->resent_at);
        $this->assertTrue($resentInvitation->expires_at->gt($oldExpiresAt));

        try {
            $this->invitationService->acceptByToken($oldToken, $resendUser, $resendOrganization);
            $this->fail('Старый токен после resend должен быть инвалидирован.');
        } catch (BusinessLogicException $exception) {
            $this->assertSame(404, $exception->getCode());
        }

        $acceptedInvitation = $this->invitationService->acceptByToken(
            $resentInvitation->token,
            $resendUser,
            $resendOrganization
        );

        $this->assertSame(ProjectParticipantInvitation::STATUS_ACCEPTED, $acceptedInvitation->status);
        $this->assertSame($resendOrganization->id, $acceptedInvitation->accepted_organization_id_snapshot);
        $this->assertDatabaseHas('project_organization', [
            'project_id' => $this->project->id,
            'organization_id' => $resendOrganization->id,
            'role_new' => ProjectOrganizationRole::OBSERVER->value,
            'is_active' => true,
        ]);
    }

    public function test_auto_accept_returns_accepted_and_conflicted_counters(): void
    {
        $acceptedProject = Project::factory()->create([
            'organization_id' => $this->ownerOrganization->id,
        ]);
        $conflictedProject = Project::factory()->create([
            'organization_id' => $this->ownerOrganization->id,
        ]);

        $existingCustomer = Organization::factory()->create();
        $newCustomer = Organization::factory()->create([
            'email' => 'new-customer@example.com',
        ]);
        $newCustomerUser = $this->createOrganizationUser($newCustomer, 'new-customer@example.com');

        $acceptedInvite = $this->invitationService->create(
            $acceptedProject,
            $this->ownerOrganization->id,
            $this->ownerUser,
            [
                'role' => ProjectOrganizationRole::CUSTOMER->value,
                'organization_name' => 'Новый заказчик',
                'email' => $newCustomerUser->email,
            ]
        );

        $conflictedInvite = $this->invitationService->create(
            $conflictedProject,
            $this->ownerOrganization->id,
            $this->ownerUser,
            [
                'role' => ProjectOrganizationRole::CUSTOMER->value,
                'organization_name' => 'Новый заказчик',
                'email' => $newCustomerUser->email,
            ]
        );

        $this->participantService->attach(
            $conflictedProject,
            $existingCustomer->id,
            ProjectOrganizationRole::CUSTOMER,
            $this->ownerUser
        );

        $stats = $this->invitationService->acceptMatchingForOrganization($newCustomerUser, $newCustomer);

        $this->assertSame([
            'accepted' => 1,
            'skipped' => 0,
            'conflicted' => 1,
        ], $stats);

        $this->assertDatabaseHas('project_participant_invitations', [
            'id' => $acceptedInvite->id,
            'status' => ProjectParticipantInvitation::STATUS_ACCEPTED,
            'accepted_organization_id_snapshot' => $newCustomer->id,
        ]);

        $this->assertDatabaseHas('project_participant_invitations', [
            'id' => $conflictedInvite->id,
            'status' => ProjectParticipantInvitation::STATUS_PENDING,
        ]);

        $this->assertDatabaseHas('project_organization', [
            'project_id' => $acceptedProject->id,
            'organization_id' => $newCustomer->id,
            'role_new' => ProjectOrganizationRole::CUSTOMER->value,
            'is_active' => true,
        ]);
    }

    public function test_invitation_list_hides_cancelled_and_allows_role_change(): void
    {
        $pendingInvitation = $this->invitationService->create(
            $this->project,
            $this->ownerOrganization->id,
            $this->ownerUser,
            [
                'role' => ProjectOrganizationRole::CUSTOMER->value,
                'organization_name' => 'ООО Будущий Заказчик',
                'email' => 'pending-invite@example.com',
            ]
        );

        $cancelledInvitation = $this->invitationService->create(
            $this->project,
            $this->ownerOrganization->id,
            $this->ownerUser,
            [
                'role' => ProjectOrganizationRole::CUSTOMER->value,
                'organization_name' => 'ГУП Отменённый',
                'email' => 'cancelled-invite@example.com',
            ]
        );
        $this->invitationService->cancel($this->project, $cancelledInvitation, $this->ownerUser);

        $visible = $this->invitationService->list($this->project);

        $this->assertTrue($visible->contains('id', $pendingInvitation->id));
        $this->assertFalse($visible->contains('id', $cancelledInvitation->id));

        $updated = $this->invitationService->updateRole(
            $this->project,
            $pendingInvitation,
            ProjectOrganizationRole::CONTRACTOR->value
        );

        $this->assertSame(ProjectOrganizationRole::CONTRACTOR->value, $updated->role);

        try {
            $this->invitationService->updateRole(
                $this->project,
                $cancelledInvitation->fresh(),
                ProjectOrganizationRole::CONTRACTOR->value
            );
            $this->fail('Ожидалась ошибка при смене роли отменённого приглашения.');
        } catch (BusinessLogicException $exception) {
            $this->assertSame(409, $exception->getCode());
        }
    }

    public function test_create_and_resend_send_invitation_email(): void
    {
        Mail::fake();
        config()->set('app.frontend_url', 'https://lk.test');

        $invitation = $this->invitationService->create(
            $this->project,
            $this->ownerOrganization->id,
            $this->ownerUser,
            [
                'role' => ProjectOrganizationRole::CUSTOMER->value,
                'organization_name' => 'ООО Будущий Заказчик',
                'email' => 'guest@example.com',
                'message' => 'Ждём вас на объекте.',
            ]
        );

        $originalToken = (string) $invitation->token;

        Mail::assertSent(ProjectParticipantInvitationMail::class, function (ProjectParticipantInvitationMail $mail) use ($originalToken): bool {
            $mail->assertHasSubject(trans_message('project_invitations.email.subject'));
            $mail->assertSeeInHtml(trans_message('project_invitations.email.accept_button'));
            $mail->assertSeeInHtml('Ждём вас на объекте.');

            return $mail->hasTo('guest@example.com')
                && $mail->acceptUrl === 'https://lk.test/project-invitations/'.urlencode($originalToken);
        });

        $this->invitationService->resend($this->project, $invitation, $this->ownerUser);

        Mail::assertSent(ProjectParticipantInvitationMail::class, 2);
        $resent = $invitation->fresh();
        $this->assertNotSame($originalToken, $resent?->token);

        Mail::assertSent(ProjectParticipantInvitationMail::class, function (ProjectParticipantInvitationMail $mail) use ($resent): bool {
            return $mail->hasTo('guest@example.com')
                && $resent !== null
                && $mail->acceptUrl === 'https://lk.test/project-invitations/'.urlencode((string) $resent->token);
        });
    }

    public function test_existing_organization_owner_can_accept_even_when_contact_email_differs(): void
    {
        $organization = Organization::factory()->create(['email' => 'contact@example.com']);
        $owner = $this->createOrganizationUser($organization, 'owner@example.com');
        $invitation = $this->invitationService->create($this->project, $this->ownerOrganization->id, $this->ownerUser, [
            'organization_id' => $organization->id,
            'email' => 'contact@example.com',
            'role' => ProjectOrganizationRole::CUSTOMER->value,
        ]);

        $accepted = $this->invitationService->acceptByTokenAsOrganizationOwner($invitation->token, $owner, $organization);

        $this->assertSame(ProjectParticipantInvitation::STATUS_ACCEPTED, $accepted->status);
        $this->assertDatabaseHas('project_organization', [
            'project_id' => $this->project->id,
            'organization_id' => $organization->id,
            'role_new' => ProjectOrganizationRole::CUSTOMER->value,
            'is_active' => true,
        ]);
    }

    public function test_new_organization_invitation_requires_matching_inn_when_provided(): void
    {
        $owner = $this->createOrganizationUser(Organization::factory()->create(['tax_number' => null]), 'match@example.com');
        $organization = $owner->currentOrganization;
        $invitation = $this->invitationService->create($this->project, $this->ownerOrganization->id, $this->ownerUser, [
            'organization_name' => 'Новая организация',
            'email' => 'match@example.com',
            'inn' => '7701234567',
            'role' => ProjectOrganizationRole::CUSTOMER->value,
        ]);

        try {
            $this->invitationService->acceptByToken($invitation->token, $owner, $organization);
            $this->fail('Приглашение с указанным ИНН нельзя принять без ИНН организации.');
        } catch (BusinessLogicException $exception) {
            $this->assertSame(422, $exception->getCode());
        }

        $organization->update(['tax_number' => '7707654321']);
        try {
            $this->invitationService->acceptByToken($invitation->token, $owner, $organization);
            $this->fail('Приглашение нельзя принять с другим ИНН.');
        } catch (BusinessLogicException $exception) {
            $this->assertSame(422, $exception->getCode());
        }
    }

    public function test_landing_accept_requires_active_owner_and_rejects_foreign_organization(): void
    {
        $organization = Organization::factory()->create(['email' => 'contact@example.com']);
        $owner = $this->createOrganizationUser($organization, 'owner@example.com');
        $nonOwner = User::factory()->create([
            'email' => 'member@example.com',
            'current_organization_id' => $organization->id,
        ]);
        $organization->users()->attach($nonOwner->id, ['is_owner' => false, 'is_active' => true]);
        $invitation = $this->invitationService->create($this->project, $this->ownerOrganization->id, $this->ownerUser, [
            'organization_id' => $organization->id,
            'email' => 'contact@example.com',
            'role' => ProjectOrganizationRole::CUSTOMER->value,
        ]);

        $this->withHeaders($this->landingHeaders($nonOwner, $organization))
            ->postJson('/api/v1/landing/project-participant-invitations/'.$invitation->token.'/accept')
            ->assertForbidden();

        $this->withHeaders($this->landingHeaders($this->ownerUser, $this->ownerOrganization))
            ->postJson('/api/v1/landing/project-participant-invitations/'.$invitation->token.'/accept')
            ->assertForbidden();

        $this->withHeaders($this->landingHeaders($owner, $organization))
            ->postJson('/api/v1/landing/project-participant-invitations/'.$invitation->token.'/accept')
            ->assertOk()
            ->assertJsonPath('data.organization.id', $organization->id)
            ->assertJsonPath('data.invitation.status', ProjectParticipantInvitation::STATUS_ACCEPTED);
    }

    public function test_expired_owner_role_assignment_cannot_accept_invitation(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->createOrganizationUser($organization);
        $context = AuthorizationContext::getOrganizationContext($organization->id);
        UserRoleAssignment::query()
            ->where('user_id', $owner->id)
            ->where('context_id', $context->id)
            ->where('role_slug', 'organization_owner')
            ->update(['expires_at' => now()->subMinute()]);
        $invitation = $this->invitationService->create($this->project, $this->ownerOrganization->id, $this->ownerUser, [
            'organization_id' => $organization->id,
            'role' => ProjectOrganizationRole::CUSTOMER->value,
        ]);

        try {
            $this->invitationService->acceptByTokenAsOrganizationOwner($invitation->token, $owner, $organization);
            $this->fail('Просроченная роль владельца не должна принимать приглашение.');
        } catch (BusinessLogicException $exception) {
            $this->assertSame(403, $exception->getCode());
        }
    }

    public function test_project_invitation_preview_does_not_expose_recipient_email_or_inn(): void
    {
        $invitation = $this->invitationService->create($this->project, $this->ownerOrganization->id, $this->ownerUser, [
            'organization_name' => 'Новая организация',
            'email' => 'private@example.com',
            'inn' => '7701234567',
            'role' => ProjectOrganizationRole::CUSTOMER->value,
        ]);

        $response = $this->getJson('/api/v1/landing/project-participant-invitations/'.$invitation->token);

        $response->assertOk()
            ->assertJsonPath('data.status', ProjectParticipantInvitation::STATUS_PENDING)
            ->assertJsonPath('data.can_accept', true)
            ->assertJsonPath('data.next_action', 'register')
            ->assertJsonMissingPath('data.email')
            ->assertJsonMissingPath('data.inn');
        $this->assertStringNotContainsString('private@example.com', $response->getContent());
        $this->assertStringNotContainsString('7701234567', $response->getContent());
    }

    private function createOrganizationUser(Organization $organization, ?string $email = null): User
    {
        $user = User::factory()->create([
            'email' => $email ?? fake()->unique()->safeEmail(),
            'current_organization_id' => $organization->id,
            'is_active' => true,
        ]);

        $organization->users()->attach($user->id, [
            'is_owner' => true,
            'is_active' => true,
        ]);
        app(UserRepository::class)->assignRoleToUser($user->id, 'organization_owner', $organization->id);

        return $user;
    }

    /** @return array<string, string> */
    private function landingHeaders(User $user, Organization $organization): array
    {
        $sessionUuid = (string) Str::uuid();
        UserAuthSession::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'session_uuid' => $sessionUuid,
            'device_fingerprint' => hash('sha256', $sessionUuid),
            'device_name' => 'Project invite test',
            'ip_address' => '127.0.0.1',
            'risk_score' => 0,
            'risk_flags' => [],
            'status' => AuthSessionStatus::Active,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
        $tokens = app(WebAuthTokenService::class)->issue($user, 'lk', $sessionUuid, $organization->id, false);

        return [
            'Authorization' => 'Bearer '.$tokens->accessToken,
            'Origin' => (string) config('web_auth.origins.lk.0'),
            'X-CSRF-Token' => $tokens->csrfToken,
        ];
    }
}
