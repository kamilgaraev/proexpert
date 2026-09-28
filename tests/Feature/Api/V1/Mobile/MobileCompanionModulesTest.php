<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Contractors\Brigades\BrigadesModule;
use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeProfile;
use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeProjectAssignment;
use App\BusinessModules\Contractors\Brigades\Support\BrigadeStatuses;
use App\BusinessModules\Features\CatalogManagement\CatalogManagementModule;
use App\BusinessModules\Features\ChangeManagement\ChangeManagementModule;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeApproval;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeImpact;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest;
use App\BusinessModules\Features\ContractManagement\ContractManagementModule;
use App\BusinessModules\Features\ExecutiveDocumentation\Enums\ExecutiveDocumentStatusEnum;
use App\BusinessModules\Features\ExecutiveDocumentation\ExecutiveDocumentationModule;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentTransmittal;
use App\BusinessModules\Features\ProjectManagement\ProjectManagementModule;
use App\BusinessModules\Features\VideoMonitoring\Models\VideoCamera;
use App\BusinessModules\Features\VideoMonitoring\VideoMonitoringModule;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\Contract\ContractStatusEnum;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Material;
use App\Models\Module;
use App\Models\OrganizationCommercialAccount;
use App\Models\OrganizationPackageSubscription;
use App\Models\Project;
use App\Modules\Contracts\ModuleInterface;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class MobileCompanionModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_companions_list_and_show_remaining_modules(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $records = $this->seedCompanionRecords($context);
        $this->registerCompanionEntitlements($context);
        $this->assertCompanionProjectPermissions($context, $records['project-management'], [
            'contracts.view',
            'change-management.view',
            'executive-documentation.view',
            'projects.view',
            'materials.view',
            'brigades.view',
            'video-monitoring.view',
        ]);

        foreach ($records as $slug => $recordId) {
            $listResponse = $this->withHeaders($context->mobileAuthHeaders())
                ->getJson('/api/v1/mobile/companions/'.$slug);
            $this->assertHttpStatus($listResponse, 200);
            $listResponse
                ->assertJsonPath('data.module.slug', $slug)
                ->assertJsonStructure([
                    'data' => [
                        'module' => ['slug', 'title', 'description', 'icon', 'route'],
                        'items' => [
                            [
                                'id',
                                'title',
                                'primary_label',
                                'secondary_label',
                                'available_actions',
                            ],
                        ],
                        'filters' => ['statuses'],
                        'empty_state' => ['title', 'description'],
                        'permission_state' => ['title', 'description'],
                        'meta' => ['current_page', 'per_page', 'total', 'last_page'],
                    ],
                ]);

            $detailResponse = $this->withHeaders($context->mobileAuthHeaders())
                ->getJson('/api/v1/mobile/companions/'.$slug.'/'.$recordId);
            $this->assertHttpStatus($detailResponse, 200);
            $detailResponse
                ->assertJsonPath('data.module.slug', $slug)
                ->assertJsonPath('data.item.id', $recordId)
                ->assertJsonStructure([
                    'data' => [
                        'sections' => [
                            [
                                'title',
                                'rows' => [
                                    ['label', 'value'],
                                ],
                            ],
                        ],
                    ],
                ]);
            $this->assertJsonPathWithBody($listResponse, 'data.items.0.id', $recordId);
        }
    }

    public function test_mobile_companions_filter_and_search(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $records = $this->seedCompanionRecords($context);
        $this->registerCompanionEntitlements($context);
        $this->assertCompanionProjectPermissions($context, $records['project-management'], ['change-management.view']);

        $response = $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/companions/change-management?status=draft&q=Tower');
        $this->assertHttpStatus($response, 200);
        $response
            ->assertJsonPath('data.items.0.id', $records['change-management'])
            ->assertJsonPath('data.meta.total', 1);
    }

    public function test_mobile_companions_return_permission_state_for_unavailable_module(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'machine_operator');
        $this->seedCompanionRecords($context, grantProjectManagerRole: false);
        $this->registerCompanionEntitlements($context);

        $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/companions/contract-management')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'PERMISSION_DENIED');
    }

    public function test_mobile_companion_action_submits_change_request(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $records = $this->seedCompanionRecords($context);
        $this->registerCompanionEntitlements($context);

        $this->assertCompanionProjectPermissions($context, $records['project-management'], [
            'change-management.create',
        ]);
        $response = $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/companions/change-management/'.$records['change-management'].'/actions/submit');
        $this->assertHttpStatus($response, 200);
        $response->assertJsonPath('data.item.status', 'submitted');

        $this->assertDatabaseHas('change_management_change_requests', [
            'id' => $records['change-management'],
            'status' => 'submitted',
        ]);
    }

    public function test_mobile_change_management_actions_cover_review_implementation_and_close_transitions(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $records = $this->seedCompanionRecords($context);
        $this->registerCompanionEntitlements($context);
        $this->assertCompanionProjectPermissions($context, $records['project-management'], [
            'change-management.edit',
            'change-management.change-orders.approve',
        ]);

        $reviewChange = ChangeRequest::query()->findOrFail($records['change-management']);
        $reviewChange->forceFill(['status' => 'impact_assessment'])->save();
        ChangeImpact::query()->create([
            'organization_id' => $context->organization->id,
            'change_request_id' => $reviewChange->id,
            'cost_delta' => 0,
            'schedule_delta_days' => 0,
            'requires_customer_approval' => true,
            'affected_schedule_task_ids' => [],
            'affected_estimate_item_ids' => [],
            'affected_contract_ids' => [],
        ]);

        $detailResponse = $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/companions/change-management/'.$reviewChange->id);
        $this->assertHttpStatus($detailResponse, 200);
        $detailResponse
            ->assertJsonPath('data.item.status', 'impact_assessment')
            ->assertJsonPath('data.item.available_actions.0.key', 'start_internal_review');

        $response = $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/companions/change-management/'.$reviewChange->id.'/actions/start_internal_review');
        $this->assertHttpStatus($response, 200);
        $response->assertJsonPath('data.item.status', 'internal_review');

        $response = $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/companions/change-management/'.$reviewChange->id.'/actions/start_customer_review');
        $this->assertHttpStatus($response, 200);
        $response->assertJsonPath('data.item.status', 'customer_review');

        $implementedChange = ChangeRequest::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => ChangeRequest::query()->findOrFail($records['change-management'])->project_id,
            'created_by_user_id' => $context->user->id,
            'change_number' => 'CHG-MOBILE-IMPLEMENT',
            'title' => 'Approved mobile change',
            'reason' => 'Approved before field work',
            'description' => 'Apply the approved change',
            'initiator_type' => 'contractor',
            'status' => 'approved',
            'reporting_currency' => 'RUB',
            'approved_at' => now(),
        ]);
        ChangeApproval::query()->create([
            'organization_id' => $context->organization->id,
            'change_request_id' => $implementedChange->id,
            'approved_by_user_id' => $context->user->id,
            'approval_type' => 'customer',
            'status' => 'approved',
            'comment' => 'Одобрено заказчиком',
            'approved_cost_minor' => 0,
            'currency' => 'RUB',
            'decided_at' => now(),
        ]);

        $response = $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/companions/change-management/'.$implementedChange->id.'/actions/implement', [
                'comment' => 'Работы выполнены',
            ]);
        $this->assertHttpStatus($response, 200);
        $response->assertJsonPath('data.item.status', 'implemented');

        $response = $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/companions/change-management/'.$implementedChange->id.'/actions/close');
        $this->assertHttpStatus($response, 200);
        $response->assertJsonPath('data.item.status', 'closed');

        $this->assertDatabaseHas('change_management_change_requests', [
            'id' => $reviewChange->id,
            'status' => 'customer_review',
        ]);
        $this->assertDatabaseHas('change_management_change_requests', [
            'id' => $implementedChange->id,
            'status' => 'closed',
            'implementation_comment' => 'Работы выполнены',
        ]);
    }

    public function test_mobile_change_management_rejects_an_action_outside_its_current_state(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $records = $this->seedCompanionRecords($context);

        $this->registerCompanionEntitlements($context);
        $this->assertCompanionProjectPermissions($context, $records['project-management'], [
            'change-management.edit',
        ]);
        $response = $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/companions/change-management/'.$records['change-management'].'/actions/start_internal_review');
        $this->assertHttpStatus($response, 422);

        $this->assertDatabaseHas('change_management_change_requests', [
            'id' => $records['change-management'],
            'status' => 'draft',
        ]);
    }

    public function test_mobile_companion_rejects_unadvertised_executive_transmittal_acknowledgement(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $records = $this->seedCompanionRecords($context, transmittedExecutiveSet: true);
        $this->registerCompanionEntitlements($context);

        $this->assertCompanionProjectPermissions($context, $records['project-management'], [
            'executive-documentation.view',
        ]);
        $response = $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/companions/executive-documentation/'.$records['executive-documentation'].'/actions/acknowledge_transmittal', [
                'comment' => 'Received on site',
            ]);
        $this->assertHttpStatus($response, 422);
        $response->assertJsonPath('message', trans_message('mobile_companions.errors.action_not_available'));

        $this->assertDatabaseHas('executive_document_transmittals', [
            'document_set_id' => $records['executive-documentation'],
            'acknowledged_by' => null,
            'acknowledged_at' => null,
            'acknowledgement_comment' => null,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function seedCompanionRecords(
        AdminApiTestContext $context,
        bool $transmittedExecutiveSet = false,
        bool $grantProjectManagerRole = true,
    ): array {
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Tower A',
            'status' => 'active',
            'budget_amount' => 1000000,
        ]);
        $context->user->assignedProjects()->attach($project->id, [
            'is_active' => true,
            'role' => 'project_manager',
            'assigned_by_user_id' => $context->user->id,
            'assigned_at' => now(),
        ]);
        if ($grantProjectManagerRole) {
            UserRoleAssignment::assignRole(
                user: $context->user,
                roleSlug: 'project_manager',
                context: AuthorizationContext::getProjectContext((int) $project->id, (int) $context->organization->id),
            );
        }

        $contractor = Contractor::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Build Partner',
            'contractor_type' => Contractor::TYPE_MANUAL,
        ]);

        $contract = Contract::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'contract_side_type' => ContractSideTypeEnum::CONTRACT->value,
            'number' => 'C-001',
            'date' => now()->toDateString(),
            'subject' => 'Concrete works',
            'base_amount' => 100000,
            'total_amount' => 100000,
            'status' => ContractStatusEnum::ACTIVE->value,
            'is_fixed_amount' => true,
            'is_self_execution' => false,
        ]);

        $change = ChangeRequest::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $context->user->id,
            'change_number' => 'CHG-001',
            'title' => 'Tower facade change',
            'reason' => 'Design update',
            'description' => 'Facade material update',
            'initiator_type' => 'contractor',
            'status' => 'draft',
        ]);

        $executiveSet = ExecutiveDocumentSet::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by' => $context->user->id,
            'set_number' => 'ED-001',
            'title' => 'Concrete acceptance pack',
            'status' => $transmittedExecutiveSet
                ? ExecutiveDocumentStatusEnum::TRANSMITTED->value
                : ExecutiveDocumentStatusEnum::DRAFT->value,
            'transmitted_at' => $transmittedExecutiveSet ? now() : null,
        ]);

        if ($transmittedExecutiveSet) {
            ExecutiveDocumentTransmittal::query()->create([
                'organization_id' => $context->organization->id,
                'document_set_id' => $executiveSet->id,
                'transmitted_by' => $context->user->id,
                'transmittal_number' => 'TR-001',
                'transmitted_at' => now(),
            ]);
        }

        $material = Material::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Concrete M300',
            'code' => 'CONCRETE-M300',
            'category' => 'Concrete',
            'default_price' => 8000,
            'is_active' => true,
        ]);

        $brigade = BrigadeProfile::query()->create([
            'organization_id' => $context->organization->id,
            'owner_user_id' => $context->user->id,
            'name' => 'Concrete brigade',
            'slug' => 'concrete-brigade-'.$context->organization->id,
            'team_size' => 8,
            'contact_person' => 'Ivan Petrov',
            'contact_phone' => '+79990000000',
            'contact_email' => 'brigade@example.test',
            'availability_status' => BrigadeStatuses::AVAILABILITY_AVAILABLE,
            'verification_status' => BrigadeStatuses::PROFILE_APPROVED,
        ]);
        BrigadeProjectAssignment::query()->create([
            'brigade_id' => $brigade->id,
            'project_id' => $project->id,
            'contractor_organization_id' => $context->organization->id,
            'status' => BrigadeStatuses::ASSIGNMENT_PLANNED,
        ]);

        $camera = VideoCamera::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by' => $context->user->id,
            'name' => 'Gate camera',
            'zone' => 'Gate',
            'source_type' => 'rtsp',
            'source_url' => 'rtsp://camera.local/stream',
            'status' => 'online',
            'last_online_at' => now(),
            'is_enabled' => true,
        ]);

        return [
            'contract-management' => $contract->id,
            'change-management' => $change->id,
            'executive-documentation' => $executiveSet->id,
            'project-management' => $project->id,
            'catalog-management' => $material->id,
            'brigades' => $brigade->id,
            'video-monitoring' => $camera->id,
        ];
    }

    /**
     * @param  list<string>|null  $allowedPermissions
     */
    private function registerCompanionEntitlements(AdminApiTestContext $context): void
    {
        $modules = [
            [new ContractManagementModule, 'ModuleList/features/contract-management.json'],
            [new ChangeManagementModule, 'ModuleList/features/change-management.json'],
            [new ExecutiveDocumentationModule, 'ModuleList/features/executive-documentation.json'],
            [new ProjectManagementModule, 'ModuleList/features/project-management.json'],
            [new CatalogManagementModule, 'ModuleList/features/catalog-management.json'],
            [new BrigadesModule, 'ModuleList/features/brigades.json'],
            [new VideoMonitoringModule, 'ModuleList/features/video-monitoring.json'],
        ];

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

        foreach ([
            ['working-entry', 39900],
            ['finance-contracts', 9900],
            ['quality-safety', 6900],
        ] as [$packageSlug, $price]) {
            OrganizationPackageSubscription::query()->create([
                'organization_id' => $context->organization->id,
                'commercial_account_id' => $account->id,
                'package_slug' => $packageSlug,
                'status' => 'active',
                'access_source' => 'paid_package',
                'price_paid' => $price,
                'current_period_start_at' => $now,
                'current_period_end_at' => $now->copy()->addDays(30),
            ]);
        }

        $access = $this->app->make(AccessController::class);
        $access->clearAccessCache((int) $context->organization->id);
        foreach (['contract-management', 'change-management', 'executive-documentation', 'project-management', 'catalog-management', 'brigades', 'video-monitoring'] as $slug) {
            self::assertTrue($access->hasModuleAccess((int) $context->organization->id, $slug));
        }
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
                'category' => $manifest['category'] ?? 'collaboration',
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

    /** @param list<string> $permissions */
    private function assertCompanionProjectPermissions(AdminApiTestContext $context, int $projectId, array $permissions): void
    {
        self::assertDatabaseHas('projects', [
            'id' => $projectId,
            'organization_id' => $context->organization->id,
        ]);
        self::assertDatabaseHas('project_user', [
            'project_id' => $projectId,
            'user_id' => $context->user->id,
            'is_active' => true,
            'role' => 'project_manager',
        ]);

        $authorization = $this->app->make(AuthorizationService::class);
        self::assertTrue($authorization->canAccessInterface($context->user, 'mobile'));
        foreach ($permissions as $permission) {
            self::assertTrue($authorization->can($context->user, $permission, [
                'organization_id' => $context->organization->id,
                'project_id' => $projectId,
                'strict_project_scope' => true,
            ]), "Expected the project role to grant {$permission} in strict project scope.");
        }
    }

    private function assertHttpStatus(TestResponse $response, int $expectedStatus): void
    {
        self::assertSame(
            $expectedStatus,
            $response->getStatusCode(),
            'Unexpected mobile HTTP response: '.$response->getContent(),
        );
    }

    private function assertJsonPathWithBody(TestResponse $response, string $path, mixed $expected): void
    {
        self::assertSame(
            $expected,
            $response->json($path),
            "Unexpected JSON at {$path}: ".$response->getContent(),
        );
    }
}
