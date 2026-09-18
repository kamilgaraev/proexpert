<?php

declare(strict_types=1);

namespace Tests\Feature\Project;

use App\Enums\ProjectOrganizationRole;
use App\Exceptions\BusinessLogicException;
use App\Mail\ProjectParticipantInvitationMail;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectParticipantInvitation;
use App\Models\User;
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
        config()->set('app.customer_frontend_url', 'https://customer.test');

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
                && $mail->acceptUrl === 'https://customer.test/invitations/'.urlencode($originalToken);
        });

        $this->invitationService->resend($this->project, $invitation, $this->ownerUser);

        Mail::assertSent(ProjectParticipantInvitationMail::class, 2);
        $resent = $invitation->fresh();
        $this->assertNotSame($originalToken, $resent?->token);

        Mail::assertSent(ProjectParticipantInvitationMail::class, function (ProjectParticipantInvitationMail $mail) use ($resent): bool {
            return $mail->hasTo('guest@example.com')
                && $resent !== null
                && $mail->acceptUrl === 'https://customer.test/invitations/'.urlencode((string) $resent->token);
        });
    }

    private function createOrganizationUser(Organization $organization, ?string $email = null): User
    {
        $user = User::factory()->create([
            'email' => $email ?? fake()->unique()->safeEmail(),
            'current_organization_id' => $organization->id,
        ]);

        $organization->users()->attach($user->id, [
            'is_owner' => true,
            'is_active' => true,
        ]);

        return $user;
    }
}
