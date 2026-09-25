<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing\Auth;

use App\Jobs\Auth\CompleteRegistrationSideEffects;
use App\Enums\ProjectOrganizationRole;
use App\Models\AuthRegistrationAttempt;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectParticipantInvitation;
use App\Models\User;
use App\Notifications\EmailVerificationNotification;
use App\Repositories\UserRepository;
use App\Services\Project\ProjectParticipantInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class RegistrationSideEffectsTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_dispatches_side_effects_only_after_commit_and_not_on_replay(): void
    {
        Queue::fake();
        $payload = $this->payload();
        $request = fn () => $this->withHeaders([
            'Origin' => (string) config('web_auth.origins.lk.0'),
            'Idempotency-Key' => 'registration-side-effects-key',
        ])->postJson('/api/v1/landing/auth/register', $payload);

        $request()->assertCreated();
        Queue::assertPushed(
            CompleteRegistrationSideEffects::class,
            static fn (CompleteRegistrationSideEffects $job): bool => $job->afterCommit === true,
        );

        Queue::assertPushed(CompleteRegistrationSideEffects::class, 1);

        $request()->assertCreated();
        Queue::assertPushed(CompleteRegistrationSideEffects::class, 1);
    }

    public function test_job_records_completed_steps_and_does_not_repeat_email_on_retry(): void
    {
        Notification::fake();
        $organization = Organization::factory()->create(['tax_number' => null]);
        $user = User::factory()->create([
            'email_verified_at' => null,
            'current_organization_id' => $organization->id,
        ]);
        $user->organizations()->attach($organization->id, ['is_owner' => true, 'is_active' => true]);
        AuthRegistrationAttempt::query()->create([
            'audience' => 'lk',
            'idempotency_key' => 'job-idempotency-key',
            'request_hash' => str_repeat('a', 64),
            'status' => 'completed',
            'user_id' => $user->id,
            'response' => [],
            'side_effects' => [],
            'expires_at' => now()->addDay(),
        ]);
        $job = new CompleteRegistrationSideEffects($user->id, $organization->id);

        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);

        Notification::assertSentToTimes($user, EmailVerificationNotification::class, 1);
        $state = AuthRegistrationAttempt::query()->where('user_id', $user->id)->value('side_effects');
        $state = is_string($state) ? json_decode($state, true, 16, JSON_THROW_ON_ERROR) : $state;
        self::assertSame('completed', $state['invitations'] ?? null);
        self::assertSame('completed', $state['contractor_sync'] ?? null);
        self::assertSame('completed', $state['email_verification'] ?? null);
    }

    public function test_job_does_not_repeat_a_side_effect_left_executing_by_a_crashed_worker(): void
    {
        Notification::fake();
        $organization = Organization::factory()->create(['tax_number' => null]);
        $user = User::factory()->create([
            'email_verified_at' => null,
            'current_organization_id' => $organization->id,
        ]);
        $user->organizations()->attach($organization->id, ['is_owner' => true, 'is_active' => true]);
        AuthRegistrationAttempt::query()->create([
            'audience' => 'lk',
            'idempotency_key' => 'crashed-side-effect-key',
            'request_hash' => str_repeat('b', 64),
            'status' => 'completed',
            'user_id' => $user->id,
            'response' => [],
            'side_effects' => [
                'invitations' => 'completed',
                'contractor_sync' => 'completed',
                'email_verification' => 'executing',
            ],
            'expires_at' => now()->addDay(),
        ]);

        app()->call([new CompleteRegistrationSideEffects($user->id, $organization->id), 'handle']);

        Notification::assertNothingSent();
    }

    public function test_common_registration_creates_owner_and_auto_accepts_project_invitation(): void
    {
        Queue::fake();
        Mail::fake();
        $projectOrganization = Organization::factory()->create();
        $projectOwner = User::factory()->create(['current_organization_id' => $projectOrganization->id]);
        $projectOrganization->users()->attach($projectOwner->id, ['is_owner' => true, 'is_active' => true]);
        app(UserRepository::class)->assignRoleToUser($projectOwner->id, 'organization_owner', $projectOrganization->id);
        $project = Project::factory()->create(['organization_id' => $projectOrganization->id]);
        $recipientEmail = 'project-customer-registration@example.test';
        $invitation = app(ProjectParticipantInvitationService::class)->create(
            $project,
            $projectOrganization->id,
            $projectOwner,
            [
                'role' => ProjectOrganizationRole::CUSTOMER->value,
                'organization_name' => 'Независимая организация заказчика',
                'email' => $recipientEmail,
            ],
        );

        $this->withHeaders([
            'Origin' => (string) config('web_auth.origins.lk.0'),
            'Idempotency-Key' => 'project-invite-owner-registration',
        ])->postJson('/api/v1/landing/auth/register', [
            ...$this->payload(),
            'email' => $recipientEmail,
            'organization_name' => 'Независимая организация заказчика',
        ])->assertCreated();

        $user = User::query()->where('email', $recipientEmail)->firstOrFail();
        $organization = $user->currentOrganization()->firstOrFail();
        $context = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($organization->id);
        self::assertDatabaseHas('user_role_assignments', [
            'user_id' => $user->id,
            'role_slug' => 'organization_owner',
            'context_id' => $context->id,
            'is_active' => true,
        ]);
        self::assertDatabaseMissing('user_role_assignments', [
            'user_id' => $user->id,
            'role_slug' => 'customer_owner',
            'context_id' => $context->id,
        ]);

        app()->call([new CompleteRegistrationSideEffects($user->id, $organization->id), 'handle']);

        self::assertDatabaseHas('project_participant_invitations', [
            'id' => $invitation->id,
            'status' => ProjectParticipantInvitation::STATUS_ACCEPTED,
            'accepted_organization_id_snapshot' => $organization->id,
        ]);
        self::assertDatabaseHas('project_organization', [
            'project_id' => $project->id,
            'organization_id' => $organization->id,
            'role_new' => ProjectOrganizationRole::CUSTOMER->value,
            'is_active' => true,
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();
        $this->withHeaders(['Origin' => (string) config('web_auth.origins.lk.0')])
            ->postJson('/api/v1/landing/auth/login', [
                'email' => $recipientEmail,
                'password' => 'Password1',
            ])
            ->assertOk();
        $this->withHeaders(['Origin' => (string) config('web_auth.origins.admin.0')])
            ->postJson('/api/v1/admin/auth/login', [
                'email' => $recipientEmail,
                'password' => 'Password1',
            ])
            ->assertOk();
    }

    /** @return array<string, bool|string> */
    private function payload(): array
    {
        return [
            'name' => 'Side Effects Owner',
            'email' => 'registration-side-effects@example.test',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
            'organization_name' => 'Side Effects Organization',
            'terms_accepted' => true,
            'privacy_accepted' => true,
        ];
    }
}
