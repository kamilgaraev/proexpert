<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeProfile;
use App\BusinessModules\Contractors\Brigades\Support\BrigadeStatuses;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest;
use App\BusinessModules\Features\ExecutiveDocumentation\Enums\ExecutiveDocumentStatusEnum;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentTransmittal;
use App\BusinessModules\Features\VideoMonitoring\Models\VideoCamera;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\Contract\ContractStatusEnum;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Material;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class MobileCompanionModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_companions_list_and_show_remaining_modules(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $records = $this->seedCompanionRecords($context);
        $this->allowAccess();

        foreach ($records as $slug => $recordId) {
            $this->withHeaders($context->authHeaders())
                ->getJson('/api/v1/mobile/companions/' . $slug)
                ->assertOk()
                ->assertJsonPath('data.module.slug', $slug)
                ->assertJsonPath('data.items.0.id', $recordId)
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

            $this->withHeaders($context->authHeaders())
                ->getJson('/api/v1/mobile/companions/' . $slug . '/' . $recordId)
                ->assertOk()
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
        }
    }

    public function test_mobile_companions_filter_and_search(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $records = $this->seedCompanionRecords($context);
        $this->allowAccess();

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/mobile/companions/change-management?status=draft&q=Tower')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $records['change-management'])
            ->assertJsonPath('data.meta.total', 1);
    }

    public function test_mobile_companions_return_permission_state_for_unavailable_module(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $this->seedCompanionRecords($context);
        $this->allowAccess(allowedPermissions: []);

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/mobile/companions/contract-management')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'PERMISSION_DENIED');
    }

    public function test_mobile_companion_action_submits_change_request(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $records = $this->seedCompanionRecords($context);
        $this->allowAccess();

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/companions/change-management/' . $records['change-management'] . '/actions/submit')
            ->assertOk()
            ->assertJsonPath('data.item.status', 'submitted');

        $this->assertDatabaseHas('change_management_change_requests', [
            'id' => $records['change-management'],
            'status' => 'submitted',
        ]);
    }

    public function test_mobile_companion_action_acknowledges_executive_transmittal(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $records = $this->seedCompanionRecords($context, transmittedExecutiveSet: true);
        $this->allowAccess();

        $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/mobile/companions/executive-documentation/' . $records['executive-documentation'] . '/actions/acknowledge_transmittal', [
                'comment' => 'Received on site',
            ])
            ->assertOk()
            ->assertJsonPath('data.item.status', 'transmitted');

        $this->assertDatabaseHas('executive_document_transmittals', [
            'document_set_id' => $records['executive-documentation'],
            'acknowledged_by' => $context->user->id,
            'acknowledgement_comment' => 'Received on site',
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function seedCompanionRecords(AdminApiTestContext $context, bool $transmittedExecutiveSet = false): array
    {
        $project = Project::factory()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Tower A',
            'status' => 'active',
            'budget_amount' => 1000000,
        ]);

        $contractor = Contractor::query()->create([
            'organization_id' => $context->organization->id,
            'name' => 'Build Partner',
            'contractor_type' => Contractor::TYPE_MANUAL,
        ]);

        $contract = Contract::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'contract_side_type' => ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_CONTRACTOR->value,
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
            'slug' => 'concrete-brigade-' . $context->organization->id,
            'team_size' => 8,
            'contact_person' => 'Ivan Petrov',
            'contact_phone' => '+79990000000',
            'contact_email' => 'brigade@example.test',
            'availability_status' => BrigadeStatuses::AVAILABILITY_AVAILABLE,
            'verification_status' => BrigadeStatuses::PROFILE_APPROVED,
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
     * @param list<string>|null $allowedPermissions
     */
    private function allowAccess(?array $allowedPermissions = null): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturn(true);
        });

        $this->mock(AuthorizationService::class, function (MockInterface $mock) use ($allowedPermissions): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturnUsing(
                static fn (User $user, string $permission): bool => $allowedPermissions === null
                    || in_array($permission, $allowedPermissions, true)
            );
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['foreman']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
            $mock->shouldReceive('getUserPermissionsStructured')->andReturn([
                'modules' => [],
            ]);
        });
    }
}
