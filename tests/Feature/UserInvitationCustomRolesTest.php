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
