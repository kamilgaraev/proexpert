<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Features\MachineryOperations\MachineryOperationsModule;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryShiftReport;
use App\BusinessModules\Features\ProjectManagement\ProjectManagementModule;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\BusinessModules\Features\WorkforceManagement\WorkforceManagementModule;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Module;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\Project;
use App\Models\User;
use App\Modules\Contracts\ModuleInterface;
use App\Modules\Core\AccessController;
use Tests\Support\AdminApiTestContext;
use Tests\Support\MachineryOperationsAssetFactory;
use Tests\TestCase;

final class MobileProjectActionAuthorizationTest extends TestCase
{
    public function test_project_role_can_access_machinery_only_in_selected_accessible_project(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'worker');
        $allowedProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $siblingProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignContext = AdminApiTestContext::create(roleSlug: 'worker');
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);

        $this->assignProjectRole($context->user, $allowedProject, 'site_engineer');
        $this->grantProjectAccess($context->user, $allowedProject, $siblingProject);
        $this->activatePackages($context, [
            [new MachineryOperationsModule, 'ModuleList/features/machinery-operations.json'],
            [new ProjectManagementModule, 'ModuleList/features/project-management.json'],
        ], ['machinery', 'working-entry']);

        $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/machinery-operations/assets')
            ->assertForbidden();

        $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/machinery-operations/assets?project_id='.$allowedProject->id)
            ->assertOk();

        $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/machinery-operations/assets?project_id='.$siblingProject->id)
            ->assertForbidden();

        $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/machinery-operations/assets?project_id='.$foreignProject->id)
            ->assertForbidden();
    }

    public function test_project_role_approves_only_resource_bound_shift_and_rejects_conflicting_project(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'worker');
        $allowedProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $siblingProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->assignProjectRole($context->user, $allowedProject, 'site_engineer');
        $this->grantProjectAccess($context->user, $allowedProject, $siblingProject);
        $asset = MachineryOperationsAssetFactory::create((int) $context->organization->id, [
            'asset_code' => 'MOBILE-PROJECT-AUTH-1',
            'name' => 'Project authorization excavator',
            'current_project_id' => $allowedProject->id,
        ]);
        $shift = $this->submittedShift($context->user, (int) $context->organization->id, $allowedProject, $asset);
        $this->activatePackages($context, [
            [new MachineryOperationsModule, 'ModuleList/features/machinery-operations.json'],
            [new ProjectManagementModule, 'ModuleList/features/project-management.json'],
        ], ['machinery', 'working-entry']);

        $this->withHeaders([...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'mobile-project-authorized-approve'])
            ->postJson('/api/v1/mobile/machinery-operations/shift-reports/'.$shift->id.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $otherShift = $this->submittedShift($context->user, (int) $context->organization->id, $allowedProject, $asset);
        $this->withHeaders([...$context->mobileAuthHeaders(), 'Idempotency-Key' => 'mobile-project-conflicting-approve'])
            ->postJson('/api/v1/mobile/machinery-operations/shift-reports/'.$otherShift->id.'/approve', [
                'project_id' => $siblingProject->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('machinery_shift_reports', ['id' => $otherShift->id, 'status' => 'submitted']);
    }

    public function test_attendance_scan_permission_uses_qr_token_project_and_blocks_sibling_role(): void
    {
        $employeeContext = AdminApiTestContext::create(roleSlug: 'machine_operator');
        $projectA = Project::factory()->create(['organization_id' => $employeeContext->organization->id]);
        $projectB = Project::factory()->create(['organization_id' => $employeeContext->organization->id]);
        $this->assignProjectRole($employeeContext->user, $projectA, 'worker');
        $this->grantProjectAccess($employeeContext->user, $projectA, $projectB);
        $this->activatePackages($employeeContext, [
            [new WorkforceManagementModule, 'ModuleList/features/workforce-management.json'],
        ], ['workforce-output']);
        $employee = WorkforceEmployee::query()->create([
            'organization_id' => $employeeContext->organization->id,
            'user_id' => $employeeContext->user->id,
            'personnel_number' => 'MOBILE-AUTH-EMPLOYEE',
            'last_name' => 'Иванов',
            'first_name' => 'Иван',
            'employment_status' => 'active',
            'hire_date' => now()->subMonth()->toDateString(),
        ]);
        $qr = $this->withHeaders($employeeContext->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/workforce/attendance/qr', [
                'project_id' => $projectA->id,
                'work_date' => now()->toDateString(),
            ])
            ->assertOk();

        $token = (string) $qr->json('data.qr_token');
        $this->assertNotSame('', $token);

        $scanner = $this->projectActor($employeeContext, $projectB, 'foreman');
        $this->grantProjectAccess($scanner->user, $projectA, $projectB);

        $this->withHeaders($scanner->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/workforce/attendance/qr/scan', ['qr_token' => $token])
            ->assertForbidden();

        $this->assertDatabaseHas('workforce_attendance_qr_tokens', [
            'organization_id' => $employeeContext->organization->id,
            'employee_id' => $employee->id,
            'project_id' => $projectA->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseMissing('workforce_attendance_scan_events', [
            'organization_id' => $employeeContext->organization->id,
            'employee_id' => $employee->id,
            'result' => 'confirmed',
        ]);
    }

    private function projectActor(AdminApiTestContext $organizationContext, Project $project, string $role): AdminApiTestContext
    {
        $this->assignProjectRole($organizationContext->user, $project, $role);

        return $organizationContext;
    }

    private function assignProjectRole(User $user, Project $project, string $role): void
    {
        UserRoleAssignment::assignRole(
            $user,
            $role,
            AuthorizationContext::getProjectContext((int) $project->id, (int) $project->organization_id),
        );
    }

    private function grantProjectAccess(User $user, Project ...$projects): void
    {
        foreach ($projects as $project) {
            $user->assignedProjects()->syncWithoutDetaching([
                $project->id => [
                    'is_active' => true,
                    'role' => 'member',
                    'assigned_by_user_id' => $user->id,
                    'assigned_at' => now(),
                ],
            ]);
        }
    }

    private function submittedShift(User $user, int $organizationId, Project $project, MachineryAsset $asset): MachineryShiftReport
    {
        return MachineryShiftReport::query()->create([
            'organization_id' => $organizationId,
            'asset_id' => $asset->id,
            'project_id' => $project->id,
            'reported_by_user_id' => $user->id,
            'report_date' => now()->toDateString(),
            'status' => 'submitted',
            'planned_hours' => 8,
            'actual_hours' => 8,
            'fuel_consumed' => 10,
            'submitted_at' => now(),
        ]);
    }

    /** @param list<array{ModuleInterface, string}> $modules
     * @param  list<string>  $packages
     */
    private function activatePackages(AdminApiTestContext $context, array $modules, array $packages): void
    {
        foreach ($modules as [$module, $configFile]) {
            $this->registerModule($module, $configFile);
        }

        $now = now();
        $account = OrganizationCommercialAccount::query()->create([
            'organization_id' => $context->organization->id,
            'responsible_user_id' => $context->user->id,
            'status' => 'active',
            'offer_type' => 'packages',
            'quote_version' => 1,
            'current_period_start_at' => $now,
            'current_period_end_at' => $now->copy()->addDays(30),
        ]);

        foreach ($packages as $package) {
            OrganizationPackageSubscription::query()->create([
                'organization_id' => $context->organization->id,
                'commercial_account_id' => $account->id,
                'package_slug' => $package,
                'status' => 'active',
                'access_source' => 'paid_package',
                'price_paid' => 100,
                'current_period_start_at' => $now,
                'current_period_end_at' => $now->copy()->addDays(30),
            ]);
        }

        $access = $this->app->make(AccessController::class);
        $access->clearAccessCache((int) $context->organization->id);
    }

    private function registerModule(ModuleInterface $module, string $configFile): void
    {
        $manifest = $module->getManifest();
        Module::query()->updateOrCreate(
            ['slug' => $module->getSlug()],
            [
                'name' => $module->getName(),
                'version' => $module->getVersion(),
                'type' => $module->getType()->value,
                'billing_model' => $module->getBillingModel()->value,
                'category' => $manifest['category'] ?? 'construction',
                'description' => $module->getDescription(),
                'features' => $module->getFeatures(),
                'permissions' => $module->getPermissions(),
                'dependencies' => $module->getDependencies(),
                'conflicts' => $module->getConflicts(),
                'limits' => $module->getLimits(),
                'class_name' => $module::class,
                'config_file' => $configFile,
                'display_order' => $manifest['display_order'] ?? 0,
                'is_active' => true,
                'is_system_module' => false,
            ],
        );
    }
}
