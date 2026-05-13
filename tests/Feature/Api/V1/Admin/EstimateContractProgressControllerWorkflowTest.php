<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BudgetEstimates\Services\Integration\EstimateContractIntegrationService;
use App\BusinessModules\Features\BudgetEstimates\Services\Integration\JournalEstimateIntegrationService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\ContractWorkTypeCategoryEnum;
use App\Enums\ContractorType;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Estimate;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class EstimateContractProgressControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_link_and_unlink_reject_foreign_contract_without_calling_integration_service(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project);

        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $foreignContractor = $this->createContractor($foreignOrganization);
        $foreignContract = $this->createContract($foreignOrganization, $foreignProject, $foreignContractor);
        $this->allowAdminAccess();

        $this->mock(EstimateContractIntegrationService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('linkToContract');
            $mock->shouldNotReceive('unlinkFromContract');
        });

        $link = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/contract", [
                'contract_id' => $foreignContract->id,
            ]);
        $link->assertStatus(422)
            ->assertJsonValidationErrors(['contract_id']);

        $unlink = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/contract", [
                'contract_id' => $foreignContract->id,
            ]);
        $unlink->assertStatus(422)
            ->assertJsonValidationErrors(['contract_id']);
    }

    public function test_progress_endpoints_return_backend_contract_used_by_admin(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project, [
            'total_amount' => 100000,
        ]);
        $this->allowAdminAccess();

        $this->mock(JournalEstimateIntegrationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getActualVsPlannedVolumes')
                ->once()
                ->andReturn([
                    [
                        'item_id' => 44,
                        'item_name' => 'Concrete',
                        'planned_volume' => 10,
                        'actual_volume' => 4,
                        'remaining_volume' => 6,
                        'completion_percent' => 40,
                        'is_completed' => false,
                        'is_over_planned' => false,
                    ],
                ]);

            $mock->shouldReceive('getEstimateCompletionStats')
                ->once()
                ->andReturn([
                    'total_items' => 3,
                    'completed_items' => 1,
                    'in_progress_items' => 1,
                    'not_started_items' => 1,
                    'overall_completion_percent' => 45.5,
                    'estimated_amount' => 100000,
                    'completed_amount' => 45500,
                ]);
        });

        $actual = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/progress/actual-vs-planned");
        $actual->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.items.0.item_id', 44)
            ->assertJsonPath('data.items.0.completion_percent', 40);

        $stats = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/progress/completion-stats");
        $stats->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_items', 3)
            ->assertJsonPath('data.overall_completion_percentage', 45.5)
            ->assertJsonPath('data.total_planned_amount', 100000)
            ->assertJsonPath('data.total_actual_amount', 45500);
    }

    private function createEstimate(Organization $organization, Project $project, array $overrides = []): Estimate
    {
        return Estimate::query()->create(array_merge([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'number' => 'CON-PROG-' . random_int(10000, 99999),
            'name' => 'Contract progress workflow',
            'type' => 'local',
            'status' => 'draft',
            'estimate_date' => '2026-06-01',
            'total_direct_costs' => 0,
            'total_overhead_costs' => 0,
            'total_estimated_profit' => 0,
            'total_amount' => 0,
            'total_amount_with_vat' => 0,
        ], $overrides));
    }

    private function createContractor(Organization $organization): Contractor
    {
        return Contractor::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Contractor ' . random_int(1000, 9999),
            'contact_person' => 'Manager',
            'email' => 'contractor' . random_int(1000, 9999) . '@example.test',
            'inn' => (string) random_int(1000000000, 9999999999),
            'contractor_type' => ContractorType::MANUAL->value,
        ]);
    }

    private function createContract(Organization $organization, Project $project, Contractor $contractor): Contract
    {
        return Contract::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'contract_side_type' => ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_CONTRACTOR->value,
            'number' => 'CON-' . random_int(10000, 99999),
            'date' => '2026-06-01',
            'subject' => 'Contract subject',
            'work_type_category' => ContractWorkTypeCategoryEnum::SMR->value,
            'base_amount' => 100000,
            'total_amount' => 100000,
            'gp_percentage' => 0,
            'planned_advance_amount' => 0,
            'actual_advance_amount' => 0,
            'status' => ContractStatusEnum::ACTIVE->value,
            'start_date' => '2026-06-01',
            'end_date' => '2026-09-01',
            'is_fixed_amount' => true,
            'is_multi_project' => false,
            'is_self_execution' => false,
        ]);
    }

    private function allowAdminAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturn(true);
        });

        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
        });
    }
}
