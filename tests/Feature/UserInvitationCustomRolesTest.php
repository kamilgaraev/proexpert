<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Exceptions\BusinessLogicException;
use App\Http\Requests\Api\V1\Landing\UserInvitation\StoreUserInvitationRequest;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\Logging\LoggingService;
use App\Services\UserInvitationCustomRoles;
use App\Services\UserInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('postgresql')]
final class UserInvitationCustomRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
    }

    public function test_request_accepts_only_custom_roles_and_requires_at_least_one_role(): void
    {
        $request = StoreUserInvitationRequest::create('/user-management/invitations', 'POST');
        $payload = [
            'email' => 'new.member@gmail.com',
            'name' => 'Новый сотрудник',
            'role_slugs' => [],
            'custom_role_ids' => [41],
        ];

        $valid = Validator::make($payload, $request->rules(), $request->messages(), $request->attributes());
        self::assertTrue($valid->passes(), $valid->errors()->first());

        $withoutRoles = Validator::make(
            [...$payload, 'custom_role_ids' => []],
            $request->rules(),
            $request->messages(),
            $request->attributes(),
        );
        self::assertTrue($withoutRoles->fails());
        self::assertTrue($withoutRoles->errors()->has('custom_role_ids'));
    }

    public function test_resolves_only_active_roles_of_requested_organization(): void
    {
        $actor = User::factory()->create();
        $organization = Organization::factory()->create();
        $first = $this->role($organization, $actor, 'Документы', 'documents');
        $second = $this->role($organization, $actor, 'Снабжение', 'supply');

        $resolved = app(UserInvitationCustomRoles::class)->resolve(
            (int) $organization->id,
            [(int) $second->id, (int) $first->id],
        );

        self::assertSame([
            ['id' => $first->id, 'slug' => 'documents', 'name' => 'Документы'],
            ['id' => $second->id, 'slug' => 'supply', 'name' => 'Снабжение'],
        ], $resolved->all());
    }

    public function test_rejects_foreign_or_inactive_role_without_partial_result(): void
    {
        $actor = User::factory()->create();
        $organization = Organization::factory()->create();
        $foreignOrganization = Organization::factory()->create();
        $valid = $this->role($organization, $actor, 'Документы', 'documents');
        $foreign = $this->role($foreignOrganization, $actor, 'Чужая', 'foreign');
        $inactive = $this->role($organization, $actor, 'Архив', 'archive', false);

        foreach ([$foreign->id, $inactive->id] as $unavailableId) {
            try {
                app(UserInvitationCustomRoles::class)->resolve(
                    (int) $organization->id,
                    [(int) $valid->id, (int) $unavailableId],
                );
                self::fail('Недоступная роль не была отклонена');
            } catch (BusinessLogicException $exception) {
                self::assertSame(trans_message('user_invitations.errors.invalid_roles'), $exception->getMessage());
            }
        }
    }

    public function test_invitation_preserves_and_assigns_custom_roles(): void
    {
        Mail::fake();
        $this->mockLogging();
        $organization = Organization::factory()->create();
        $actor = User::factory()->create();
        $role = $this->role($organization, $actor, 'Документы', 'documents');

        $invitation = app(UserInvitationService::class)->createInvitation([
            'email' => 'invited-role@example.test',
            'name' => 'Новый сотрудник',
            'role_slugs' => [],
            'custom_role_ids' => [$role->id],
        ], (int) $organization->id, $actor);

        self::assertSame([['id' => $role->id, 'slug' => 'documents', 'name' => 'Документы']], $invitation->custom_roles);
        self::assertSame(['Документы'], $invitation->role_names);

        $user = app(UserInvitationService::class)->acceptInvitation($invitation->token, ['password' => 'Strong-password-2026']);
        self::assertTrue($user->fresh()->hasVerifiedEmail());
        $context = AuthorizationContext::getOrganizationContext((int) $organization->id);
        self::assertDatabaseHas('user_role_assignments', [
            'user_id' => $user->id,
            'context_id' => $context->id,
            'role_slug' => 'documents',
            'role_type' => UserRoleAssignment::TYPE_CUSTOM,
            'assigned_by' => $actor->id,
            'is_active' => true,
        ]);
    }

    public function test_acceptance_rejects_custom_role_removed_after_invitation(): void
    {
        Mail::fake();
        $this->mockLogging();
        $organization = Organization::factory()->create();
        $actor = User::factory()->create();
        $role = $this->role($organization, $actor, 'Документы', 'documents');
        $invitation = app(UserInvitationService::class)->createInvitation([
            'email' => 'revoked-role@example.test',
            'name' => 'Новый сотрудник',
            'role_slugs' => [],
            'custom_role_ids' => [$role->id],
        ], (int) $organization->id, $actor);
        $role->update(['is_active' => false]);

        try {
            app(UserInvitationService::class)->acceptInvitation($invitation->token, ['password' => 'Strong-password-2026']);
            self::fail('Отозванная роль не была отклонена');
        } catch (BusinessLogicException $exception) {
            self::assertStringContainsString(trans_message('user_invitations.errors.invalid_roles'), $exception->getMessage());
        }

        self::assertDatabaseMissing('users', ['email' => 'revoked-role@example.test']);
        self::assertSame('pending', $invitation->fresh()->status->value);
    }

    public function test_rejects_duplicate_or_malformed_identifiers(): void
    {
        $organization = Organization::factory()->create();

        foreach ([[3, 3], [3, '3'], [0], [-1]] as $ids) {
            try {
                app(UserInvitationCustomRoles::class)->resolve((int) $organization->id, $ids);
                self::fail('Некорректные идентификаторы не были отклонены');
            } catch (BusinessLogicException $exception) {
                self::assertSame(trans_message('user_invitations.errors.invalid_roles'), $exception->getMessage());
            }
        }
    }

    public function test_restores_only_new_accounts_with_matching_accepted_invitation(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create();
        $createdAt = '2026-09-07 12:29:54';
        $users = [];

        foreach (['matching', 'pending', 'different_email', 'existing', 'verified'] as $case) {
            $user = User::factory()->unverified()->create(['created_at' => $createdAt]);
            if ($case === 'verified') {
                $user->forceFill(['email_verified_at' => '2026-09-07 12:30:00'])->save();
            }
            \App\Models\UserInvitation::create([
                'organization_id' => $organization->id,
                'invited_by_user_id' => $actor->id,
                'accepted_by_user_id' => $user->id,
                'email' => $case === 'different_email' ? 'different@example.test' : $user->email,
                'name' => $user->name,
                'role_slugs' => [],
                'status' => $case === 'pending' ? 'pending' : 'accepted',
                'accepted_at' => $case === 'existing' ? '2026-09-07 12:31:00' : $createdAt,
            ]);
            $users[$case] = $user;
        }

        $migration = require database_path('migrations/2026_09_07_130000_restore_invited_user_email_verification.php');
        $migration->up();
        $migration->up();

        self::assertSame($createdAt, $users['matching']->fresh()->email_verified_at->format('Y-m-d H:i:s'));
        foreach (['pending', 'different_email', 'existing'] as $case) {
            self::assertFalse($users[$case]->fresh()->hasVerifiedEmail(), $case);
        }
        self::assertSame('2026-09-07 12:30:00', $users['verified']->fresh()->email_verified_at->format('Y-m-d H:i:s'));
    }

    public function test_http_invitation_preserves_custom_roles_and_rejects_unavailable_roles(): void
    {
        Mail::fake();
        $organization = Organization::factory()->verified()->create();
        $owner = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($owner->id, ['is_owner' => true, 'is_active' => true]);
        $context = AuthorizationContext::getOrganizationContext((int) $organization->id);
        UserRoleAssignment::assignRole($owner, 'organization_owner', $context);
        $sessionUuid = (string) \Illuminate\Support\Str::uuid();
        \App\Models\UserAuthSession::query()->create([
            'user_id' => $owner->id,
            'organization_id' => $organization->id,
            'session_uuid' => $sessionUuid,
            'device_fingerprint' => hash('sha256', $sessionUuid),
            'device_name' => 'Invitation HTTP test',
            'ip_address' => '127.0.0.1',
            'risk_score' => 0,
            'risk_flags' => [],
            'status' => \App\Enums\AuthSessionStatus::Active,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
        $tokens = app(\App\Services\Auth\WebAuthTokenService::class)->issue(
            $owner, 'lk', $sessionUuid, (int) $organization->id, false,
        );
        $this->withHeaders([
            'Authorization' => 'Bearer '.$tokens->accessToken,
            'Accept' => 'application/json',
            'Origin' => 'https://lk.1мост.рф',
        ]);
        $role = $this->role($organization, $owner, 'Юрист', 'iurist');
        $payload = [
            'email' => 'invitation.http.regression@gmail.com',
            'name' => 'Учебный юрист',
            'role_slugs' => [],
            'custom_role_ids' => [(int) $role->id],
        ];

        $this->postJson('/api/v1/landing/user-management/invitations', $payload)->assertCreated();
        $invitation = UserInvitation::query()->where('email', $payload['email'])->sole();
        self::assertSame((int) $organization->id, (int) $invitation->organization_id);
        self::assertSame([], $invitation->role_slugs);
        self::assertEquals([['id' => $role->id, 'slug' => 'iurist', 'name' => 'Юрист']], $invitation->custom_roles);
        Mail::assertSent(\App\Mail\UserInvitationMail::class, 1);

        $this->postJson('/api/v1/landing/user-management/invitations', [
            ...$payload,
            'email' => 'invitation.mixed.regression@gmail.com',
            'role_slugs' => ['organization_admin'],
            'custom_role_ids' => [(string) $role->id],
        ])->assertCreated();
        $mixed = UserInvitation::query()->where('email', 'invitation.mixed.regression@gmail.com')->sole();
        self::assertSame(['organization_admin'], $mixed->role_slugs);
        self::assertEquals([['id' => $role->id, 'slug' => 'iurist', 'name' => 'Юрист']], $mixed->custom_roles);

        $foreign = $this->role(Organization::factory()->create(), $owner, 'Чужая', 'foreign');
        $inactive = $this->role($organization, $owner, 'Архивная', 'inactive', false);
        foreach ([[$foreign->id], [$inactive->id], [], [$role->id, $role->id], ['invalid']] as $roleIds) {
            $this->postJson('/api/v1/landing/user-management/invitations', [
                ...$payload,
                'email' => 'invitation.rejected.regression@gmail.com',
                'custom_role_ids' => $roleIds,
            ])->assertUnprocessable();
        }
        self::assertSame(2, UserInvitation::query()->count());
        Mail::assertSent(\App\Mail\UserInvitationMail::class, 2);
    }

    private function mockLogging(): void
    {
        $this->mock(LoggingService::class, function ($mock): void {
            $mock->shouldReceive('business')->zeroOrMoreTimes();
            $mock->shouldReceive('security')->zeroOrMoreTimes();
            $mock->shouldReceive('technical')->zeroOrMoreTimes();
            $mock->shouldReceive('audit')->zeroOrMoreTimes();
        });
    }

    private function role(
        Organization $organization,
        User $actor,
        string $name,
        string $slug,
        bool $active = true,
    ): OrganizationCustomRole {
        return OrganizationCustomRole::query()->create([
            'organization_id' => $organization->id,
            'name' => $name,
            'slug' => $slug,
            'system_permissions' => [],
            'module_permissions' => [],
            'interface_access' => ['lk'],
            'conditions' => null,
            'is_active' => $active,
            'created_by' => $actor->id,
        ]);
    }
}
