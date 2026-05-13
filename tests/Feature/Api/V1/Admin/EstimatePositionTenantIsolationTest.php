<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\EstimatePositionItemType;
use App\Models\Estimate;
use App\Models\EstimateSection;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class EstimatePositionTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_estimate_item_create_rejects_foreign_section_work_type_and_unit(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project);

        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $foreignEstimate = $this->createEstimate($foreignOrganization, $foreignProject);
        $foreignSection = $this->createSection($foreignEstimate);
        $foreignWorkType = $this->createWorkType($foreignOrganization);
        $foreignUnit = $this->createMeasurementUnit($foreignOrganization);
        $this->allowAdminAccess();

        $basePayload = [
            'item_type' => EstimatePositionItemType::WORK->value,
            'name' => 'Scoped estimate item',
            'quantity' => 5,
            'unit_price' => 1000,
        ];

        foreach ([
            'estimate_section_id' => $foreignSection->id,
            'work_type_id' => $foreignWorkType->id,
            'measurement_unit_id' => $foreignUnit->id,
        ] as $field => $value) {
            $response = $this->withHeaders($context->authHeaders())
                ->postJson("/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/items", $basePayload + [
                    $field => $value,
                ]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors([$field]);
        }

        $this->assertDatabaseMissing('estimate_items', [
            'estimate_id' => $estimate->id,
            'name' => 'Scoped estimate item',
        ]);
    }

    public function test_estimate_section_create_and_update_reject_foreign_parent_section_without_mutation(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project);
        $section = $this->createSection($estimate, ['name' => 'Original section']);

        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $foreignEstimate = $this->createEstimate($foreignOrganization, $foreignProject);
        $foreignSection = $this->createSection($foreignEstimate);
        $this->allowAdminAccess();

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/sections", [
                'name' => 'Scoped child section',
                'parent_section_id' => $foreignSection->id,
            ]);

        $createResponse->assertStatus(422);
        $createResponse->assertJsonValidationErrors(['parent_section_id']);
        $this->assertDatabaseMissing('estimate_sections', [
            'estimate_id' => $estimate->id,
            'name' => 'Scoped child section',
        ]);

        $updateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/sections/{$section->id}", [
                'name' => 'SHOULD-NOT-CHANGE',
                'parent_section_id' => $foreignSection->id,
            ]);

        $updateResponse->assertStatus(422);
        $updateResponse->assertJsonValidationErrors(['parent_section_id']);
        $section->refresh();
        $this->assertSame('Original section', $section->name);
        $this->assertNull($section->parent_section_id);
    }

    private function createEstimate(Organization $organization, Project $project, array $overrides = []): Estimate
    {
        return Estimate::query()->create(array_merge([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'number' => 'ISO-EST-' . random_int(10000, 99999),
            'name' => 'Isolation estimate',
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

    private function createSection(Estimate $estimate, array $overrides = []): EstimateSection
    {
        return EstimateSection::query()->create(array_merge([
            'estimate_id' => $estimate->id,
            'section_number' => '1',
            'name' => 'Isolation section ' . random_int(1000, 9999),
            'sort_order' => 1,
            'is_summary' => false,
        ], $overrides));
    }

    private function createWorkType(Organization $organization, array $overrides = []): WorkType
    {
        return WorkType::query()->create(array_merge([
            'organization_id' => $organization->id,
            'name' => 'Estimate work type ' . random_int(1000, 9999),
            'code' => 'EWT-' . random_int(1000, 9999),
            'default_price' => 1000,
            'is_active' => true,
        ], $overrides));
    }

    private function createMeasurementUnit(Organization $organization, array $overrides = []): MeasurementUnit
    {
        return MeasurementUnit::query()->create(array_merge([
            'organization_id' => $organization->id,
            'name' => 'Estimate unit ' . random_int(1000, 9999),
            'short_name' => 'eu' . random_int(1000, 9999),
            'type' => 'work',
            'is_default' => false,
            'is_system' => false,
        ], $overrides));
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
