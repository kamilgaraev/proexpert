<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\PermissionResolver;
use App\Exceptions\BusinessLogicException;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Services\Auth\WebAuthTokenService;
use App\Services\User\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class LegacyAdminPanelSecurityRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    #[DataProvider('identityChanges')]
    public function test_legacy_update_cannot_change_a_shared_global_identity(array $changes): void
    {
        $context = $this->actor();
        $member = $this->member($context);
        $other = Organization::factory()->create();
        $member->organizations()->attach($other->id, ['is_active' => true]);
        $before = $member->getRawOriginal();

        $this->deny(fn () => app(UserService::class)->updateAdminPanelUser(
            $member->id, $changes, $this->request($context),
        ), 403);

        self::assertSame($before['name'], $member->fresh()->name);
        self::assertSame($before['password'], $member->fresh()->getRawOriginal('password'));
        self::assertTrue($member->fresh()->is_active);
    }

    public static function identityChanges(): array
    {
        return [
            'password' => [['password' => 'AttackerPassword123!']],
            'status' => [['is_active' => false]],
            'name' => [['name' => 'Changed globally']],
        ];
    }

    public function test_legacy_update_preserves_local_edits_and_shared_role_only_updates(): void
    {
        $context = $this->actor();
        $member = $this->member($context);
        app(UserService::class)->updateAdminPanelUser($member->id, [
            'name' => 'Local member', 'password' => 'LegitimatePassword123!',
        ], $this->request($context));
        self::assertSame('Local member', $member->fresh()->name);
        self::assertTrue(Hash::check('LegitimatePassword123!', $member->fresh()->password));

        $member->organizations()->attach(Organization::factory()->create()->id, ['is_active' => true]);
        app(UserService::class)->updateAdminPanelUser($member->id, [
            'name' => 'Local member', 'is_active' => true, 'role_slugs' => ['foreman'],
        ], $this->request($context));
        $this->assertAssignment($member, $context, 'foreman', true);
        $this->assertAssignment($member, $context, 'supplier', false);
    }

    public function test_legacy_create_rejects_higher_roles_for_new_and_existing_users(): void
    {
        $context = $this->actor();
        $existing = $this->member($context);
        $assignment = UserRoleAssignment::assignRole($existing, 'accountant',
            AuthorizationContext::getOrganizationContext($context->organization->id));
        $assignment->update(['is_active' => false]);

        foreach (['new-admin-panel@example.com', $existing->email] as $email) {
            $this->deny(fn () => app(UserService::class)->createAdminPanelUser([
                'name' => 'Unchanged', 'email' => $email, 'password' => 'Password123!',
            ], 'accountant', $this->request($context)), 422);
        }

        $this->assertDatabaseMissing('users', ['email' => 'new-admin-panel@example.com']);
        self::assertFalse($assignment->fresh()->is_active);
        self::assertNotSame('Unchanged', $existing->fresh()->name);
    }

    public function test_legacy_role_update_rejects_grants_and_removal_of_protected_roles(): void
    {
        $context = $this->actor();
        $member = $this->member($context);
        $this->deny(fn () => app(UserService::class)->updateAdminPanelUser(
            $member->id, ['name' => 'Must roll back', 'role_slugs' => ['accountant']], $this->request($context),
        ), 422);
        $this->assertAssignment($member, $context, 'supplier', true);
        $this->assertDatabaseMissing('users', ['id' => $member->id, 'name' => 'Must roll back']);

        UserRoleAssignment::assignRole($member, 'accountant',
            AuthorizationContext::getOrganizationContext($context->organization->id));
        $this->deny(fn () => app(UserService::class)->syncAdminPanelUserRoles(
            $member, $context->organization->id, ['supplier'], $context->user,
        ), 403);
        $this->assertAssignment($member, $context, 'accountant', true);
    }

    public function test_legacy_custom_grants_cannot_exceed_the_actor_permissions(): void
    {
        $context = $this->actor();
        $member = $this->member($context);
        $allowed = $this->customRole($context, 'allowed-role', ['organization.view']);
        $forbidden = $this->customRole($context, 'forbidden-role', ['billing.manage']);

        $this->deny(fn () => app(UserService::class)->createAdminPanelUser([
            'name' => 'Forbidden', 'email' => 'forbidden-role@example.com', 'password' => 'Password123!',
        ], $forbidden->slug, $this->request($context)), 422);
        $this->deny(fn () => app(UserService::class)->syncAdminPanelUserRoles(
            $member, $context->organization->id, [$forbidden->slug], $context->user,
        ), 422);
        $this->assertAssignment($member, $context, 'supplier', true);

        app(UserService::class)->syncAdminPanelUserRoles(
            $member, $context->organization->id, [$allowed->slug], $context->user,
        );
        $this->assertAssignment($member, $context, $allowed->slug, true);
        $this->assertDatabaseMissing('users', ['email' => 'forbidden-role@example.com']);
    }

    public function test_legacy_sync_requires_an_actor_before_changing_roles(): void
    {
        $context = $this->actor();
        $member = $this->member($context);
        $this->deny(fn () => app(UserService::class)->syncAdminPanelUserRoles(
            $member, $context->organization->id, ['accountant'],
        ), 403);
        $this->assertAssignment($member, $context, 'supplier', true);
    }

    public function test_legacy_create_cannot_rename_an_existing_shared_identity(): void
    {
        $context = $this->actor();
        $member = $this->member($context);
        $member->organizations()->attach(Organization::factory()->create()->id, ['is_active' => true]);
        $this->deny(fn () => app(UserService::class)->createAdminPanelUser([
            'name' => 'Cross tenant rename', 'email' => $member->email, 'password' => 'Password123!',
        ], 'foreman', $this->request($context)), 403);
        $this->assertDatabaseMissing('user_role_assignments', ['user_id' => $member->id, 'role_slug' => 'foreman']);

        app(UserService::class)->createAdminPanelUser([
            'name' => $member->name, 'email' => $member->email, 'password' => 'IgnoredPassword123!',
        ], 'foreman', $this->request($context));
        $this->assertAssignment($member, $context, 'foreman', true);
        self::assertFalse(Hash::check('IgnoredPassword123!', $member->fresh()->password));
    }

    public function test_legacy_delete_cannot_remove_a_role_above_the_actor(): void
    {
        $context = $this->actor();
        $member = $this->member($context, 'accountant');
        $this->deny(fn () => app(UserService::class)->deleteAdminPanelUser(
            $member->id, $this->request($context),
        ), 403);
        $this->assertAssignment($member, $context, 'accountant', true);
        self::assertTrue($member->organizations()->whereKey($context->organization->id)->exists());
    }

    public function test_http_multi_role_create_rejects_the_whole_request_before_mutation(): void
    {
        $context = $this->actor();
        $sessionUuid = UserAuthSession::query()->where('user_id', $context->user->id)->value('session_uuid');
        $token = app(WebAuthTokenService::class)->issue($context->user, 'lk', $sessionUuid,
            $context->organization->id, false)->accessToken;
        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Origin' => 'https://lk.1мост.рф']);
        $member = $this->member($context);

        foreach (['multi-role@example.com', $member->email] as $email) {
            $this->postJson('/api/v1/landing/adminPanelUsers', [
                'name' => 'Must not persist', 'email' => $email,
                'password' => 'Password123!', 'password_confirmation' => 'Password123!',
                'role_slugs' => ['foreman', 'accountant'],
            ])->assertUnprocessable();
        }

        $this->assertDatabaseMissing('users', ['email' => 'multi-role@example.com']);
        self::assertNotSame('Must not persist', $member->fresh()->name);
        $this->assertDatabaseMissing('user_role_assignments', ['user_id' => $member->id, 'role_slug' => 'foreman']);
    }

    public function test_owner_can_create_new_and_existing_users_with_multiple_roles(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $existing = $this->member($context, 'foreman');
        $role = $this->customRole($context, 'multiple-roles', ['organization.view']);

        foreach (['allowed-multiple@example.com', $existing->email] as $email) {
            $member = app(UserService::class)->createAdminPanelUser([
                'name' => $existing->name, 'email' => $email, 'password' => 'Password123!',
            ], 'supplier', $this->request($context), ['supplier', $role->slug]);
            $this->assertAssignment($member, $context, 'supplier', true);
            $this->assertAssignment($member, $context, $role->slug, true);
        }

        $this->assertAssignment($existing, $context, 'foreman', false);
    }

    public function test_custom_role_delegation_preserves_supported_module_permission_forms(): void
    {
        $context = $this->actor();
        $authorizationContext = AuthorizationContext::getOrganizationContext($context->organization->id);
        UserRoleAssignment::query()->where('user_id', $context->user->id)->update(['is_active' => false]);
        $actorRole = $this->customRole($context, 'custom-manager', [
            'organization.manage', 'users.manage_admin', 'reports.view',
        ]);
        UserRoleAssignment::assignRole($context->user, $actorRole->slug, $authorizationContext, UserRoleAssignment::TYPE_CUSTOM);
        Cache::flush();
        self::assertTrue(app(AuthorizationService::class)->forCurrentChecks(true)->canCurrent(
            $context->user, 'reports.view', ['organization_id' => $context->organization->id],
        ));

        foreach (['short' => 'view', 'qualified' => 'reports.view'] as $form => $permission) {
            $role = $this->customRole($context, 'reports-'.$form, []);
            $role->update(['module_permissions' => ['reports' => [$permission]]]);
            self::assertContains('reports.view', app(PermissionResolver::class)
                ->forCurrentChecks()->getCustomRolePermissions($role->slug, $context->organization->id));
            $member = app(UserService::class)->createAdminPanelUser([
                'name' => 'Report reader', 'email' => $form.'@example.com', 'password' => 'Password123!',
            ], $role->slug, $this->request($context));
            $this->assertAssignment($member, $context, $role->slug, true);
        }
    }

    public function test_custom_permission_names_preserve_aliases_wildcards_and_nested_actions(): void
    {
        $context = $this->actor();
        $role = $this->customRole($context, 'permission-forms', []);
        $role->update(['module_permissions' => [
            'budget-estimates' => ['estimates.view', 'view', 'items.edit', '*'],
            'contract-management' => ['agreements.view'],
        ]]);

        $permissions = app(PermissionResolver::class)->forCurrentChecks()
            ->getCustomRolePermissions($role->slug, $context->organization->id);
        foreach (['estimates.view', 'budget-estimates.view', 'budget-estimates.items.edit',
            'budget-estimates.*', 'agreements.view'] as $permission) {
            self::assertContains($permission, $permissions);
        }
        self::assertNotContains('budget-estimates.estimates.view', $permissions);
        self::assertNotContains('contract-management.agreements.view', $permissions);
    }

    private function actor(): AdminApiTestContext
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_admin');
        $context->user->organizations()->updateExistingPivot($context->organization->id, ['is_owner' => false]);

        return $context;
    }

    private function member(AdminApiTestContext $context, string $role = 'supplier'): User
    {
        $member = User::factory()->create(['current_organization_id' => $context->organization->id, 'is_active' => true]);
        $member->organizations()->attach($context->organization->id, ['is_active' => true, 'is_owner' => false]);
        UserRoleAssignment::assignRole($member, $role,
            AuthorizationContext::getOrganizationContext($context->organization->id));

        return $member;
    }

    private function request(AdminApiTestContext $context): Request
    {
        $request = Request::create('/api/v1/landing/adminPanelUsers');
        $request->attributes->set('current_organization_id', $context->organization->id);
        $request->setUserResolver(fn () => $context->user);

        return $request;
    }

    private function customRole(AdminApiTestContext $context, string $slug, array $permissions): OrganizationCustomRole
    {
        return OrganizationCustomRole::query()->create([
            'organization_id' => $context->organization->id, 'name' => $slug, 'slug' => $slug,
            'created_by' => $context->user->id, 'system_permissions' => $permissions,
            'module_permissions' => [], 'interface_access' => ['lk', 'admin'], 'is_active' => true,
        ]);
    }

    private function assertAssignment(User $member, AdminApiTestContext $context, string $role, bool $active): void
    {
        $this->assertDatabaseHas('user_role_assignments', [
            'user_id' => $member->id, 'role_slug' => $role, 'is_active' => $active,
            'context_id' => AuthorizationContext::getOrganizationContext($context->organization->id)->id,
        ]);
    }

    private function deny(callable $action, int $status): void
    {
        try {
            $action();
            self::fail('Forbidden user management action was allowed.');
        } catch (BusinessLogicException $exception) {
            self::assertSame($status, $exception->getCode());
        }
    }
}
