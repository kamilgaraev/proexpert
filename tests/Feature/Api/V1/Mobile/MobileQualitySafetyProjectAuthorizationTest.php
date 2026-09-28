<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Addons\FileManagement\FileManagementModule;
use App\BusinessModules\Features\BudgetEstimates\BudgetEstimatesModule;
use App\BusinessModules\Features\ContractManagement\ContractManagementModule;
use App\BusinessModules\Features\ProjectManagement\ProjectManagementModule;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\QualityControl\QualityControlModule;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyBriefing;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyBriefingParticipant;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyViolation;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyWorkPermit;
use App\BusinessModules\Features\SafetyManagement\SafetyManagementModule;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\Project;
use App\Models\User;
use App\Modules\Contracts\ModuleInterface;
use App\Modules\Core\AccessController;
use App\Services\Auth\JwtTokenIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class MobileQualitySafetyProjectAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_quality_list_is_scoped_to_authorized_project_and_create_replay_rechecks_permission(): void
    {
        $actor = $this->createActor();
        $allowedProject = $actor['project'];
        $siblingProject = $this->createProject($actor, grantQualityAndSafety: false);
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $this->activateQualitySafetyModules($actor['organization'], $actor['user']);

        $allowedDefect = $this->createDefect($actor, $allowedProject, 'Allowed project defect');
        $siblingDefect = $this->createDefect($actor, $siblingProject, 'Sibling project defect');

        $this->withHeaders($actor['headers'])
            ->getJson('/api/v1/mobile/quality-control/defects?project_id='.$allowedProject->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $allowedDefect->id);

        $this->withHeaders($actor['headers'])
            ->getJson('/api/v1/mobile/quality-control/defects?project_id='.$siblingProject->id)
            ->assertForbidden();
        $this->withHeaders($actor['headers'])
            ->getJson('/api/v1/mobile/quality-control/defects?project_id='.$foreignProject->id)
            ->assertForbidden();

        $payload = [
            'project_id' => $allowedProject->id,
            'title' => 'Project-scoped quality defect',
            'severity' => 'minor',
            'inspection_required' => false,
        ];
        $headers = [...$actor['headers'], 'Idempotency-Key' => 'quality-project-create-20260928'];
        $created = $this->withHeaders($headers)
            ->postJson('/api/v1/mobile/quality-control/defects', $payload)
            ->assertCreated();

        $this->withHeaders($headers)
            ->postJson('/api/v1/mobile/quality-control/defects', $payload)
            ->assertCreated()
            ->assertJsonPath('data.id', $created->json('data.id'));

        $this->revokeProjectRole($actor['user'], $allowedProject, 'site_engineer');
        Cache::driver('array')->flush();
        $this->withHeaders($headers)
            ->postJson('/api/v1/mobile/quality-control/defects', $payload)
            ->assertForbidden();

        $this->withHeaders([
            ...$actor['headers'],
            'Idempotency-Key' => 'quality-sibling-create-20260928',
        ])
            ->postJson('/api/v1/mobile/quality-control/defects', [...$payload, 'project_id' => $siblingProject->id])
            ->assertForbidden();

        $this->assertDatabaseCount('quality_defects', 3);
        $this->assertDatabaseHas('quality_defects', ['id' => $siblingDefect->id]);
        $this->assertDatabaseHas('mobile_mutation_idempotencies', [
            'organization_id' => $actor['organization']->id,
            'user_id' => $actor['user']->id,
            'idempotency_key' => 'quality-project-create-20260928',
        ]);
        $this->assertDatabaseMissing('mobile_mutation_idempotencies', [
            'organization_id' => $actor['organization']->id,
            'user_id' => $actor['user']->id,
            'idempotency_key' => 'quality-sibling-create-20260928',
        ]);
    }

    public function test_quality_resource_action_uses_defect_project_and_rejects_conflicting_project_id(): void
    {
        $actor = $this->createActor();
        $allowedProject = $actor['project'];
        $siblingProject = $this->createProject($actor, grantQualityAndSafety: true);
        $ungrantedProject = $this->createProject($actor, grantQualityAndSafety: false);
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $this->activateQualitySafetyModules($actor['organization'], $actor['user']);
        $defect = $this->createDefect($actor, $allowedProject, 'Project-bound defect', 'in_progress');
        $ungrantedDefect = $this->createDefect($actor, $ungrantedProject, 'Unselected project defect', 'in_progress');
        $foreignDefect = QualityDefect::query()->create([
            'organization_id' => $foreignOrganization->id,
            'project_id' => $foreignProject->id,
            'defect_number' => 'QD-FOREIGN-PROJECT-AUTH-1',
            'title' => 'Foreign organization defect',
            'severity' => 'minor',
            'status' => 'in_progress',
            'inspection_required' => false,
        ]);

        $this->withHeaders([
            ...$actor['headers'],
            'Idempotency-Key' => 'quality-resource-conflict-20260928',
        ])
            ->postJson('/api/v1/mobile/quality-control/defects/'.$defect->id.'/resolve', [
                'project_id' => $siblingProject->id,
                'comment' => 'Подмена контекста проекта',
            ])
            ->assertForbidden();

        $this->assertSame('in_progress', $defect->fresh()->status->value);
        $this->assertDatabaseMissing('mobile_mutation_idempotencies', [
            'organization_id' => $actor['organization']->id,
            'user_id' => $actor['user']->id,
            'idempotency_key' => 'quality-resource-conflict-20260928',
        ]);

        $this->withHeaders([
            ...$actor['headers'],
            'Idempotency-Key' => 'quality-resource-ungranted-20260928',
        ])
            ->postJson('/api/v1/mobile/quality-control/defects/'.$ungrantedDefect->id.'/resolve', [
                'comment' => 'Недоступное действие',
            ])
            ->assertForbidden();
        $this->assertSame('in_progress', $ungrantedDefect->fresh()->status->value);

        $this->withHeaders([
            ...$actor['headers'],
            'Idempotency-Key' => 'quality-resource-foreign-20260928',
        ])
            ->postJson('/api/v1/mobile/quality-control/defects/'.$foreignDefect->id.'/resolve', [
                'comment' => 'Чужая организация',
            ])
            ->assertForbidden();
        $this->assertSame('in_progress', $foreignDefect->fresh()->status->value);
        $this->assertDatabaseMissing('mobile_mutation_idempotencies', [
            'organization_id' => $actor['organization']->id,
            'user_id' => $actor['user']->id,
            'idempotency_key' => 'quality-resource-foreign-20260928',
        ]);

        $this->withHeaders([
            ...$actor['headers'],
            'Idempotency-Key' => 'quality-resource-resolve-20260928',
        ])
            ->postJson('/api/v1/mobile/quality-control/defects/'.$defect->id.'/resolve', [
                'comment' => 'Результат проверен в проекте объекта',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'ready_for_review');
    }

    public function test_safety_project_permissions_gate_incident_list_create_and_replay(): void
    {
        $actor = $this->createActor();
        $allowedProject = $actor['project'];
        $siblingProject = $this->createProject($actor, grantQualityAndSafety: false);
        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $this->activateQualitySafetyModules($actor['organization'], $actor['user']);

        $this->withHeaders($actor['headers'])
            ->getJson('/api/v1/mobile/safety-management/incidents?project_id='.$allowedProject->id)
            ->assertOk();
        $this->withHeaders($actor['headers'])
            ->getJson('/api/v1/mobile/safety-management/incidents?project_id='.$siblingProject->id)
            ->assertForbidden();
        $this->withHeaders($actor['headers'])
            ->getJson('/api/v1/mobile/safety-management/incidents?project_id='.$foreignProject->id)
            ->assertForbidden();

        $payload = [
            'project_id' => $allowedProject->id,
            'title' => 'Проверка проектного доступа к инциденту',
            'incident_type' => 'unsafe_condition',
            'severity' => 'major',
            'occurred_at' => now()->toIso8601String(),
        ];
        $headers = [...$actor['headers'], 'Idempotency-Key' => 'safety-project-create-20260928'];
        $created = $this->withHeaders($headers)
            ->postJson('/api/v1/mobile/safety-management/incidents', $payload)
            ->assertCreated();

        $this->withHeaders($headers)
            ->postJson('/api/v1/mobile/safety-management/incidents', $payload)
            ->assertCreated()
            ->assertJsonPath('data.id', $created->json('data.id'));

        $this->revokeProjectRole($actor['user'], $allowedProject, 'site_engineer');
        Cache::driver('array')->flush();
        $this->withHeaders($headers)
            ->postJson('/api/v1/mobile/safety-management/incidents', $payload)
            ->assertForbidden();
        $this->withHeaders([
            ...$actor['headers'],
            'Idempotency-Key' => 'safety-sibling-create-20260928',
        ])
            ->postJson('/api/v1/mobile/safety-management/incidents', [...$payload, 'project_id' => $siblingProject->id])
            ->assertForbidden();

        $this->assertDatabaseCount('safety_incidents', 1);
        $this->assertDatabaseHas('mobile_mutation_idempotencies', [
            'organization_id' => $actor['organization']->id,
            'user_id' => $actor['user']->id,
            'idempotency_key' => 'safety-project-create-20260928',
        ]);
        $this->assertDatabaseMissing('mobile_mutation_idempotencies', [
            'organization_id' => $actor['organization']->id,
            'user_id' => $actor['user']->id,
            'idempotency_key' => 'safety-sibling-create-20260928',
        ]);
    }

    public function test_safety_resource_gates_use_persisted_permit_briefing_and_violation_projects(): void
    {
        $actor = $this->createActor();
        $allowedProject = $actor['project'];
        $siblingProject = $this->createProject($actor, grantQualityAndSafety: true);
        $this->activateQualitySafetyModules($actor['organization'], $actor['user']);

        $permit = SafetyWorkPermit::query()->create([
            'organization_id' => $actor['organization']->id,
            'project_id' => $allowedProject->id,
            'created_by_user_id' => $actor['user']->id,
            'responsible_user_id' => $actor['user']->id,
            'permit_number' => 'HSE-P-PROJECT-AUTH-1',
            'title' => 'Допуск для проверки области',
            'permit_type' => 'height_work',
            'location_name' => 'Секция А',
            'risk_level' => 'high',
            'valid_from' => now()->subHour(),
            'valid_until' => now()->addDay(),
            'required_controls' => ['Проверка доступа'],
            'status' => 'active',
        ]);
        $this->withHeaders($actor['headers'])
            ->getJson('/api/v1/mobile/safety-management/work-permits/'.$permit->id.'?project_id='.$siblingProject->id)
            ->assertForbidden();
        $this->withHeaders($actor['headers'])
            ->getJson('/api/v1/mobile/safety-management/work-permits/'.$permit->id)
            ->assertOk();

        $briefing = SafetyBriefing::query()->create([
            'organization_id' => $actor['organization']->id,
            'project_id' => $allowedProject->id,
            'conducted_by_user_id' => $actor['user']->id,
            'briefing_number' => 'HSE-B-PROJECT-AUTH-1',
            'title' => 'Инструктаж для проверки области',
            'briefing_type' => 'toolbox',
            'conducted_at' => now()->subHour(),
            'started_at' => now()->subHour(),
            'status' => 'awaiting_signatures',
            'signature_summary' => [
                'total' => 1,
                'signed' => 0,
                'pending' => 1,
                'absent' => 0,
                'refused' => 0,
                'resolved' => 0,
                'completion_percent' => 0,
                'all_resolved' => false,
            ],
            'topics' => ['Безопасный проход'],
        ]);
        SafetyBriefingParticipant::query()->create([
            'briefing_id' => $briefing->id,
            'user_id' => $actor['user']->id,
            'signature_status' => 'pending',
        ]);
        $this->withHeaders($actor['headers'])
            ->getJson('/api/v1/mobile/safety-management/briefings/'.$briefing->id.'?project_id='.$siblingProject->id)
            ->assertForbidden();
        $this->withHeaders($actor['headers'])
            ->getJson('/api/v1/mobile/safety-management/briefings/'.$briefing->id)
            ->assertOk();

        $createdViolation = $this->withHeaders([
            ...$actor['headers'],
            'Idempotency-Key' => 'safety-violation-create-auth-20260928',
        ])
            ->postJson('/api/v1/mobile/safety-management/violations', [
                'project_id' => $allowedProject->id,
                'title' => 'Нарушение для проверки области',
                'severity' => 'high',
            ])
            ->assertCreated();
        $violation = SafetyViolation::query()->findOrFail($createdViolation->json('data.id'));

        $this->withHeaders($actor['headers'])
            ->postJson('/api/v1/mobile/safety-management/violations/'.$violation->id.'/resolve', [
                'project_id' => $siblingProject->id,
                'resolution_comment' => 'Неверный проект',
            ])
            ->assertForbidden();
        $this->assertSame('open', $violation->fresh()->status);

        $this->withHeaders($actor['headers'])
            ->postJson('/api/v1/mobile/safety-management/violations/'.$violation->id.'/resolve', [
                'resolution_comment' => 'Нарушение устранено на исходном объекте',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');
    }

    /**
     * @return array{organization: Organization, user: User, project: Project, headers: array<string, string>}
     */
    private function createActor(): array
    {
        $organization = Organization::factory()->verified()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($user->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);
        UserRoleAssignment::assignRole(
            $user,
            'worker',
            AuthorizationContext::getOrganizationContext((int) $organization->id),
        );

        $project = $this->createProject(['organization' => $organization, 'user' => $user], grantQualityAndSafety: true);
        $token = app(JwtTokenIssuer::class)->issue($user, [
            'guard' => 'api_mobile',
            'organization_id' => $organization->id,
        ]);

        return [
            'organization' => $organization,
            'user' => $user,
            'project' => $project,
            'headers' => [
                'Authorization' => 'Bearer '.$token,
                'Accept' => 'application/json',
            ],
        ];
    }

    /** @param array{organization: Organization, user: User} $actor */
    private function createProject(array $actor, bool $grantQualityAndSafety): Project
    {
        $project = Project::factory()->create(['organization_id' => $actor['organization']->id]);
        $actor['user']->assignedProjects()->syncWithoutDetaching([
            $project->id => [
                'is_active' => true,
                'role' => 'member',
                'assigned_by_user_id' => $actor['user']->id,
                'assigned_at' => now(),
            ],
        ]);

        if ($grantQualityAndSafety) {
            UserRoleAssignment::assignRole(
                $actor['user'],
                'site_engineer',
                AuthorizationContext::getProjectContext((int) $project->id, (int) $actor['organization']->id),
            );
        }

        return $project;
    }

    private function revokeProjectRole(User $user, Project $project, string $role): void
    {
        $context = AuthorizationContext::getProjectContext((int) $project->id, (int) $project->organization_id);
        $user->roleAssignments()
            ->where('context_id', $context->id)
            ->where('role_slug', $role)
            ->update(['is_active' => false]);
    }

    private function createDefect(array $actor, Project $project, string $title, string $status = 'open'): QualityDefect
    {
        return QualityDefect::query()->create([
            'organization_id' => $actor['organization']->id,
            'project_id' => $project->id,
            'created_by' => $actor['user']->id,
            'defect_number' => 'QD-PROJECT-AUTH-'.uniqid(),
            'title' => $title,
            'severity' => 'minor',
            'status' => $status,
            'inspection_required' => false,
        ]);
    }

    private function activateQualitySafetyModules(Organization $organization, User $user): void
    {
        $this->registerModule(new ProjectManagementModule, 'ModuleList/features/project-management.json');
        $this->registerModule(new ContractManagementModule, 'ModuleList/features/contract-management.json');
        $this->registerModule(new BudgetEstimatesModule, 'ModuleList/features/budget-estimates.json');
        $this->registerModule(new FileManagementModule, 'ModuleList/addons/file-management.json');
        $this->registerModule(new QualityControlModule, 'ModuleList/features/quality-control.json');
        $this->registerModule(new SafetyManagementModule, 'ModuleList/features/safety-management.json');

        $now = now();
        $account = OrganizationCommercialAccount::query()->create([
            'organization_id' => $organization->id,
            'responsible_user_id' => $user->id,
            'status' => 'active',
            'offer_type' => 'packages',
            'quote_version' => 1,
            'current_period_start_at' => $now,
            'current_period_end_at' => $now->copy()->addDays(30),
        ]);
        OrganizationPackageSubscription::query()->create([
            'organization_id' => $organization->id,
            'commercial_account_id' => $account->id,
            'package_slug' => 'quality-safety',
            'status' => 'active',
            'access_source' => 'paid_package',
            'price_paid' => 6900,
            'current_period_start_at' => $now,
            'current_period_end_at' => $now->copy()->addDays(30),
        ]);

        $access = $this->app->make(AccessController::class);
        $access->clearAccessCache((int) $organization->id);
        foreach (['project-management', 'contract-management', 'budget-estimates', 'file-management'] as $dependency) {
            self::assertTrue(
                $access->hasModuleAccess((int) $organization->id, $dependency),
                "Required dependency module {$dependency} is not active for the test organization."
            );
        }
        self::assertTrue($access->hasModuleAccess((int) $organization->id, 'quality-control'));
        self::assertTrue($access->hasModuleAccess((int) $organization->id, 'safety-management'));
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
                'category' => $manifest['category'] ?? 'execution',
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
