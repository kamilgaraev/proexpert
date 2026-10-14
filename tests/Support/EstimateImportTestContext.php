<?php

declare(strict_types=1);

namespace Tests\Support;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportAccessContext;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\ImportSession;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\Module;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Services\Modules\PackageCatalogService;

final readonly class EstimateImportTestContext
{
    public function __construct(public User $user, public Organization $organization, public Project $project) {}

    public static function create(): self
    {
        $organization = Organization::factory()->verified()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($user->id, ['is_owner' => true, 'is_active' => true, 'project_access_mode' => 'all_projects']);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        UserRoleAssignment::assignRole($user, 'organization_owner', AuthorizationContext::getOrganizationContext($organization->id));
        AuthorizationContext::getProjectContext($project->id, $organization->id);
        $definition = app(PackageCatalogService::class)->moduleDefinitions()['budget-estimates'];
        Module::query()->updateOrCreate(['slug' => 'budget-estimates'], [
            'name' => $definition['name'], 'version' => $definition['version'] ?? '1.0.0',
            'type' => $definition['type'], 'billing_model' => $definition['billing_model'] ?? 'free',
            'category' => $definition['category'] ?? 'general', 'permissions' => $definition['permissions'] ?? [],
            'is_active' => true, 'is_system_module' => $definition['is_system_module'] ?? false,
            'can_deactivate' => $definition['can_deactivate'] ?? true,
        ]);
        $account = OrganizationCommercialAccount::query()->create([
            'organization_id' => $organization->id, 'responsible_user_id' => $user->id,
            'status' => 'active', 'offer_type' => 'packages', 'quote_version' => 1,
            'current_period_start_at' => now(), 'current_period_end_at' => now()->addMonth(),
        ]);
        OrganizationPackageSubscription::query()->create([
            'organization_id' => $organization->id, 'commercial_account_id' => $account->id,
            'package_slug' => 'working-entry', 'status' => 'active', 'access_source' => 'paid_package', 'price_paid' => 0,
            'current_period_start_at' => now(), 'current_period_end_at' => now()->addMonth(),
        ]);

        return new self($user, $organization, $project);
    }

    public function access(): ImportAccessContext
    {
        return new ImportAccessContext($this->user, (int) $this->organization->id, (int) $this->project->id);
    }

    public function session(array $attributes = []): ImportSession
    {
        return ImportSession::query()->forceCreate(array_merge([
            'user_id' => $this->user->id, 'organization_id' => $this->organization->id,
            'status' => 'uploading', 'file_name' => 'estimate.xlsx', 'file_format' => 'xlsx', 'file_size' => 100,
            'options' => ['project_id' => $this->project->id], 'stats' => [],
        ], $attributes, ['options' => array_merge(['project_id' => $this->project->id, 'execution_user_id' => $this->user->id], $attributes['options'] ?? [])]));
    }
}
