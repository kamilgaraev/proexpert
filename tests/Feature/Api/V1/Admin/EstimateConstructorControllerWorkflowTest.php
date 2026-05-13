<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\EstimatePositionItemType;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class EstimateConstructorControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_delete_returns_admin_contract_and_stays_inside_current_organization(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project);
        $unit = $this->createMeasurementUnit($context->organization);
        $item = $this->createItem($estimate, $unit);

        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $foreignEstimate = $this->createEstimate($foreignOrganization, $foreignProject);
        $foreignUnit = $this->createMeasurementUnit($foreignOrganization);
        $foreignItem = $this->createItem($foreignEstimate, $foreignUnit);

        $foreignResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/estimates/constructor/{$foreignEstimate->id}/bulk-delete", [
                'item_ids' => [$foreignItem->id],
            ]);

        $foreignResponse->assertNotFound();
        $foreignResponse->assertJsonPath('success', false);
        $this->assertNotSoftDeleted('estimate_items', ['id' => $foreignItem->id]);

        $ownResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/estimates/constructor/{$estimate->id}/bulk-delete", [
                'item_ids' => [$item->id],
            ]);

        $ownResponse->assertOk();
        $ownResponse->assertJsonPath('success', true);
        $ownResponse->assertJsonPath('data.deleted_count', 1);
        $this->assertSoftDeleted('estimate_items', ['id' => $item->id]);
    }

    public function test_move_and_copy_reject_entities_from_other_organizations_without_mutation(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project);
        $targetEstimate = $this->createEstimate($context->organization, $project, ['number' => 'TARGET-LOCAL']);
        $unit = $this->createMeasurementUnit($context->organization);
        $item = $this->createItem($estimate, $unit);

        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $foreignEstimate = $this->createEstimate($foreignOrganization, $foreignProject);
        $foreignSection = $this->createSection($foreignEstimate);

        $moveResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/estimates/constructor/{$estimate->id}/move-to-section", [
                'item_ids' => [$item->id],
                'section_id' => $foreignSection->id,
            ]);

        $moveResponse->assertNotFound();
        $moveResponse->assertJsonPath('success', false);
        $this->assertDatabaseHas('estimate_items', [
            'id' => $item->id,
            'estimate_section_id' => null,
        ]);

        $foreignCopyResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/estimates/constructor/{$estimate->id}/copy-items", [
                'item_ids' => [$item->id],
                'target_estimate_id' => $foreignEstimate->id,
            ]);

        $foreignCopyResponse->assertNotFound();
        $foreignCopyResponse->assertJsonPath('success', false);
        $this->assertDatabaseCount('estimate_items', 1);

        $ownCopyResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/estimates/constructor/{$estimate->id}/copy-items", [
                'item_ids' => [$item->id],
                'target_estimate_id' => $targetEstimate->id,
            ]);

        $ownCopyResponse->assertCreated();
        $ownCopyResponse->assertJsonPath('success', true);
        $ownCopyResponse->assertJsonPath('data.copied_count', 1);
        $this->assertDatabaseHas('estimate_items', [
            'estimate_id' => $targetEstimate->id,
            'name' => $item->name,
        ]);
    }

    private function createEstimate(Organization $organization, Project $project, array $overrides = []): Estimate
    {
        return Estimate::query()->create(array_merge([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'number' => 'CONSTR-' . random_int(10000, 99999),
            'name' => 'Constructor estimate',
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
            'name' => 'Constructor item ' . random_int(1000, 9999),
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
            'name' => 'Constructor section ' . random_int(1000, 9999),
            'sort_order' => 1,
            'is_summary' => false,
        ], $overrides));
    }

    private function createMeasurementUnit(Organization $organization, array $overrides = []): MeasurementUnit
    {
        return MeasurementUnit::query()->create(array_merge([
            'organization_id' => $organization->id,
            'name' => 'Constructor unit ' . random_int(1000, 9999),
            'short_name' => 'cu' . random_int(1000, 9999),
            'type' => 'work',
            'is_default' => false,
            'is_system' => false,
        ], $overrides));
    }
}
