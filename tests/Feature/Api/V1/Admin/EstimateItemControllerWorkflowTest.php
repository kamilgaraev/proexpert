<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\EstimatePositionItemType;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class EstimateItemControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_is_estimate_scoped_and_tolerates_admin_pagination(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project);
        $unit = $this->createMeasurementUnit($context->organization);
        $item = $this->createItem($estimate, $unit, ['name' => 'Current item']);

        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $foreignEstimate = $this->createEstimate($foreignOrganization, $foreignProject);
        $foreignUnit = $this->createMeasurementUnit($foreignOrganization);
        $foreignItem = $this->createItem($foreignEstimate, $foreignUnit, ['name' => 'Foreign item']);
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/items?per_page=-1&page=1");

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('meta.total', 1);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($item->id, $ids);
        $this->assertNotContains($foreignItem->id, $ids);
    }

    public function test_item_detail_mutations_and_delete_are_scoped_to_project_estimate(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project);
        $unit = $this->createMeasurementUnit($context->organization);
        $item = $this->createItem($estimate, $unit, ['name' => 'Original item']);

        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $foreignEstimate = $this->createEstimate($foreignOrganization, $foreignProject);
        $foreignUnit = $this->createMeasurementUnit($foreignOrganization);
        $foreignItem = $this->createItem($foreignEstimate, $foreignUnit);
        $this->allowAdminAccess();

        $showResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/items/{$item->id}");
        $showResponse->assertOk();
        $showResponse->assertJsonPath('data.id', $item->id);

        $updateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/items/{$item->id}", [
                'quantity' => 3,
                'unit_price' => 1500,
            ]);
        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('data.quantity', 3);
        $updateResponse->assertJsonPath('data.unit_price', 1500);

        foreach (['getJson', 'putJson', 'deleteJson'] as $method) {
            $foreignResponse = $method === 'putJson'
                ? $this->withHeaders($context->authHeaders())->{$method}("/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/items/{$foreignItem->id}", ['name' => 'Leaked update'])
                : $this->withHeaders($context->authHeaders())->{$method}("/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/items/{$foreignItem->id}");

            $this->assertContains($foreignResponse->status(), [403, 404]);
        }

        $deleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/items/{$item->id}");
        $deleteResponse->assertOk();
        $deleteResponse->assertJsonPath('success', true);
        $this->assertSoftDeleted('estimate_items', ['id' => $item->id]);
    }

    public function test_bulk_update_and_move_reject_foreign_estimate_entities_without_mutation(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project);
        $unit = $this->createMeasurementUnit($context->organization);
        $item = $this->createItem($estimate, $unit, ['name' => 'Original scoped item']);

        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $foreignEstimate = $this->createEstimate($foreignOrganization, $foreignProject);
        $foreignUnit = $this->createMeasurementUnit($foreignOrganization);
        $foreignItem = $this->createItem($foreignEstimate, $foreignUnit);
        $foreignSection = $this->createSection($foreignEstimate);
        $this->allowAdminAccess();

        $bulkResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/items/bulk", [
                'items' => [
                    ['id' => $foreignItem->id, 'name' => 'Leaked bulk update'],
                ],
            ]);
        $bulkResponse->assertStatus(422);
        $bulkResponse->assertJsonValidationErrors(['items.0.id']);

        $moveResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/items/{$item->id}/move", [
                'section_id' => $foreignSection->id,
            ]);
        $moveResponse->assertStatus(422);
        $moveResponse->assertJsonValidationErrors(['section_id']);

        $item->refresh();
        $this->assertSame('Original scoped item', $item->name);
        $this->assertNull($item->estimate_section_id);
    }

    private function createEstimate(Organization $organization, Project $project, array $overrides = []): Estimate
    {
        return Estimate::query()->create(array_merge([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'number' => 'ITEM-EST-' . random_int(10000, 99999),
            'name' => 'Estimate item workflow',
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

    private function createItem(Estimate $estimate, MeasurementUnit $unit, array $overrides = []): EstimateItem
    {
        return EstimateItem::query()->create(array_merge([
            'estimate_id' => $estimate->id,
            'position_number' => '1',
            'item_type' => EstimatePositionItemType::WORK->value,
            'name' => 'Estimate item ' . random_int(1000, 9999),
            'measurement_unit_id' => $unit->id,
            'quantity' => 1,
            'unit_price' => 1000,
            'direct_costs' => 1000,
            'overhead_amount' => 0,
            'profit_amount' => 0,
            'total_amount' => 1000,
            'is_manual' => true,
        ], $overrides));
    }

    private function createSection(Estimate $estimate, array $overrides = []): EstimateSection
    {
        return EstimateSection::query()->create(array_merge([
            'estimate_id' => $estimate->id,
            'section_number' => '1',
            'name' => 'Estimate section ' . random_int(1000, 9999),
            'sort_order' => 1,
            'is_summary' => false,
        ], $overrides));
    }

    private function createMeasurementUnit(Organization $organization, array $overrides = []): MeasurementUnit
    {
        return MeasurementUnit::query()->create(array_merge([
            'organization_id' => $organization->id,
            'name' => 'Estimate item unit ' . random_int(1000, 9999),
            'short_name' => 'iu' . random_int(1000, 9999),
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
