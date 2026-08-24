<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Customer\Auth;

use App\DTOs\Auth\RegisterDTO;
use App\Enums\ProjectOrganizationRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectParticipantInvitation;
use App\Models\User;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\Services\Customer\Auth\CustomerAuthService;
use App\Services\Project\ProjectParticipantInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class CustomerRegistrationAtomicityTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_rolls_back_user_organization_and_membership_when_owner_role_fails(): void
    {
        Notification::fake();
        $repository = Mockery::mock(app(UserRepositoryInterface::class))->makePartial();
        $repository->shouldReceive('assignRoleToUser')
            ->once()
            ->andThrow(new RuntimeException('role assignment failed'));
        $this->app->instance(UserRepositoryInterface::class, $repository);

        $thrown = null;

        try {
            app(CustomerAuthService::class)->register($this->registrationData('role-failure@example.test'));
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        self::assertInstanceOf(RuntimeException::class, $thrown);
        self::assertSame('role assignment failed', $thrown->getMessage());

        $this->assertDatabaseMissing('users', ['email' => 'role-failure@example.test']);
        $this->assertDatabaseMissing('organizations', ['name' => 'Atomic Role Failure']);
        self::assertSame(0, User::query()->where('email', 'role-failure@example.test')->count());
    }

    public function test_invitation_registration_rolls_back_account_when_acceptance_fails(): void
    {
        Notification::fake();
        $inviterOrganization = Organization::factory()->create();
        $inviter = User::factory()->create();
        $project = Project::factory()->create(['organization_id' => $inviterOrganization->id]);
        $invitation = ProjectParticipantInvitation::query()->create([
            'project_id' => $project->id,
            'organization_id' => $inviterOrganization->id,
            'invited_by_user_id' => $inviter->id,
            'role' => ProjectOrganizationRole::CUSTOMER->value,
            'status' => ProjectParticipantInvitation::STATUS_PENDING,
            'organization_name' => 'Atomic Invitation Target',
            'email' => 'invitation-failure@example.test',
        ]);

        $repository = Mockery::mock(app(UserRepositoryInterface::class))->makePartial();
        $repository->shouldReceive('assignRoleToUser')->once();
        $this->app->instance(UserRepositoryInterface::class, $repository);

        $invitations = Mockery::mock(app(ProjectParticipantInvitationService::class))->makePartial();
        $invitations->shouldReceive('acceptMatchingForOrganization')->once()->andReturn([
            'accepted' => 0,
            'skipped' => 0,
            'conflicted' => 0,
        ]);
        $invitations->shouldReceive('acceptByToken')
            ->once()
            ->andThrow(new RuntimeException('invitation acceptance failed'));
        $this->app->instance(ProjectParticipantInvitationService::class, $invitations);

        $thrown = null;

        try {
            app(CustomerAuthService::class)->registerByInvitation(
                $invitation->token,
                $this->registrationData('invitation-failure@example.test', 'Atomic Invitation Target'),
            );
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        self::assertInstanceOf(RuntimeException::class, $thrown);
        self::assertSame('invitation acceptance failed', $thrown->getMessage());

        $this->assertDatabaseMissing('users', ['email' => 'invitation-failure@example.test']);
        $this->assertDatabaseMissing('organizations', ['name' => 'Atomic Invitation Target']);
        $this->assertDatabaseHas('project_participant_invitations', [
            'id' => $invitation->id,
            'status' => ProjectParticipantInvitation::STATUS_PENDING,
            'accepted_by_user_id' => null,
        ]);
    }

    private function registrationData(string $email, string $organizationName = 'Atomic Role Failure'): RegisterDTO
    {
        return RegisterDTO::fromRequest([
            'name' => 'Atomic Customer',
            'email' => $email,
            'password' => 'StrongPassword123!',
            'organization_name' => $organizationName,
            'organization_tax_number' => null,
        ]);
    }
}
