<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\User;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class AssistantRealAuthorizationFixture
{
    public Organization $organization;
    public Organization $foreignOrganization;
    public User $owner;
    public User $administrator;
    public User $member;
    public User $foreignOwner;
    public OrganizationCustomRole $memberRole;
    public UserRoleAssignment $memberAssignment;
    public UserRoleAssignment $ownerAssignment;
    public OrganizationPackageSubscription $subscription;
    private OrganizationCommercialAccount $account;

    public static function create(array $packages = ['working-entry']): self
    {
        $fixture = new self;
        $fixture->registerModules();
        $fixture->organization = Organization::factory()->verified()->create();
        $fixture->foreignOrganization = Organization::factory()->verified()->create();
        $fixture->owner = $fixture->newActor($fixture->organization, true);
        $fixture->ownerAssignment = $fixture->assignSystemRole($fixture->owner, $fixture->organization, 'organization_owner');
        $fixture->administrator = $fixture->newActor($fixture->organization);
        $fixture->assignSystemRole($fixture->administrator, $fixture->organization, 'organization_admin');
        $fixture->foreignOwner = $fixture->newActor($fixture->foreignOrganization, true);
        $fixture->assignSystemRole($fixture->foreignOwner, $fixture->foreignOrganization, 'organization_owner');
        $fixture->account = OrganizationCommercialAccount::query()->create([
            'organization_id' => $fixture->organization->id,
            'responsible_user_id' => $fixture->owner->id,
            'status' => 'active', 'offer_type' => 'packages', 'quote_version' => 1,
            'current_period_start_at' => now(), 'current_period_end_at' => now()->addMonth(),
            'auto_renew_enabled' => false,
        ]);
        $fixture->activatePackages($packages);
        $fixture->member = $fixture->addMember(['ai-assistant' => [
            'ai_assistant.chat', 'ai_assistant.reports.generate', 'ai_assistant.analytics.view', 'ai_assistant.usage.view',
        ]]);
        $fixture->memberAssignment = UserRoleAssignment::query()->where('user_id', $fixture->member->id)->firstOrFail();
        $fixture->memberRole = OrganizationCustomRole::query()->where('slug', $fixture->memberAssignment->role_slug)->firstOrFail();

        return $fixture;
    }

    public function activatePackages(array $slugs): void
    {
        $catalog = app(PackageCatalogService::class);
        foreach ($slugs as $slug) {
            if ($catalog->package($slug) === null) {
                throw new InvalidArgumentException('Unknown canonical package: '.$slug);
            }
            $subscription = OrganizationPackageSubscription::query()->updateOrCreate([
                'organization_id' => $this->organization->id, 'package_slug' => $slug,
            ], [
                'commercial_account_id' => $this->account->id, 'status' => 'active', 'access_source' => 'paid_package',
                'price_paid' => 39900, 'current_period_start_at' => now(), 'current_period_end_at' => now()->addMonth(),
            ]);
            if ($slug === 'working-entry') {
                $this->subscription = $subscription;
            }
        }
    }

    public function addMember(array $modulePermissions = [], array $systemPermissions = []): User
    {
        $user = $this->newActor($this->organization);
        $role = OrganizationCustomRole::query()->create([
            'organization_id' => $this->organization->id, 'name' => 'Участник помощника',
            'slug' => 'assistant_member_'.Str::lower(Str::random(16)),
            'system_permissions' => $systemPermissions, 'module_permissions' => $modulePermissions,
            'interface_access' => ['admin', 'lk', 'mobile'], 'is_active' => true, 'created_by' => $this->owner->id,
        ]);
        UserRoleAssignment::query()->create([
            'user_id' => $user->id, 'role_slug' => $role->slug, 'role_type' => UserRoleAssignment::TYPE_CUSTOM,
            'context_id' => AuthorizationContext::getOrganizationContext($this->organization->id)->id,
            'assigned_by' => $this->owner->id, 'is_active' => true,
        ]);

        return $user;
    }

    private function newActor(Organization $organization, bool $owner = false): User
    {
        $user = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $organization->users()->attach($user->id, [
            'is_owner' => $owner, 'is_active' => true, 'settings' => null,
            'project_access_mode' => $owner ? 'all_projects' : 'assigned_projects',
        ]);

        return $user;
    }

    private function assignSystemRole(User $user, Organization $organization, string $slug): UserRoleAssignment
    {
        return UserRoleAssignment::query()->create([
            'user_id' => $user->id, 'role_slug' => $slug, 'role_type' => UserRoleAssignment::TYPE_SYSTEM,
            'context_id' => AuthorizationContext::getOrganizationContext($organization->id)->id,
            'assigned_by' => $user->id, 'is_active' => true,
        ]);
    }

    private function registerModules(): void
    {
        foreach (app(PackageCatalogService::class)->moduleDefinitions() as $slug => $definition) {
            Module::query()->updateOrCreate(['slug' => $slug], [
                'name' => $definition['name'], 'version' => $definition['version'] ?? '1.0.0',
                'type' => $definition['type'], 'billing_model' => $definition['billing_model'] ?? 'free',
                'category' => $definition['category'] ?? 'general', 'permissions' => $definition['permissions'] ?? [],
                'limits' => $definition['limits'] ?? [], 'dependencies' => $definition['dependencies'] ?? [],
                'is_active' => true, 'is_system_module' => $definition['is_system_module'] ?? false,
                'can_deactivate' => $definition['can_deactivate'] ?? true,
            ]);
        }
    }
}
