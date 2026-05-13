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

class EstimateSectionControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_cascade_action_from_admin_removes_nested_sections_and_items(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project);
        $parent = $this->createSection($estimate, ['name' => 'Parent section']);
        $child = $this->createSection($estimate, [
            'name' => 'Child section',
            'parent_section_id' => $parent->id,
        ]);
        $item = $this->createItem($estimate, $this->createMeasurementUnit($context->organization), [
            'estimate_section_id' => $child->id,
        ]);
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/sections/{$parent->id}?action=delete_cascade");

        $response->assertOk()
            ->assertJsonPath('success', true);
        $this->assertDatabaseMissing('estimate_sections', ['id' => $parent->id]);
        $this->assertDatabaseMissing('estimate_sections', ['id' => $child->id]);
        $this->assertSoftDeleted('estimate_items', ['id' => $item->id]);
    }

    public function test_move_items_up_action_preserves_children_and_items_under_parent_level(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project);
        $parent = $this->createSection($estimate, ['name' => 'Parent section']);
        $child = $this->createSection($estimate, [
            'name' => 'Child section',
            'parent_section_id' => $parent->id,
        ]);
        $item = $this->createItem($estimate, $this->createMeasurementUnit($context->organization), [
            'estimate_section_id' => $parent->id,
        ]);
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/sections/{$parent->id}?action=move_items_up");

        $response->assertOk()
            ->assertJsonPath('success', true);
        $this->assertDatabaseMissing('estimate_sections', ['id' => $parent->id]);
        $this->assertDatabaseHas('estimate_sections', [
            'id' => $child->id,
            'parent_section_id' => null,
        ]);
        $this->assertDatabaseHas('estimate_items', [
            'id' => $item->id,
            'estimate_section_id' => null,
            'deleted_at' => null,
        ]);
    }

    public function test_reorder_rejects_self_parent_without_mutation(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project);
        $section = $this->createSection($estimate, [
            'name' => 'Stable section',
            'sort_order' => 1,
            'parent_section_id' => null,
        ]);
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/sections/reorder", [
                'sections' => [
                    [
                        'id' => $section->id,
                        'sort_order' => 5,
                        'parent_section_id' => $section->id,
                    ],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Раздел нельзя вложить сам в себя.');

        $section->refresh();
        $this->assertSame(1, $section->sort_order);
        $this->assertNull($section->parent_section_id);
    }

    private function createEstimate(Organization $organization, Project $project, array $overrides = []): Estimate
    {
        return Estimate::query()->create(array_merge([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'number' => 'SECTION-EST-' . random_int(10000, 99999),
            'name' => 'Section workflow',
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
            'name' => 'Estimate section ' . random_int(1000, 9999),
            'sort_order' => 1,
            'is_summary' => false,
        ], $overrides));
    }

    private function createItem(Estimate $estimate, MeasurementUnit $unit, array $overrides = []): EstimateItem
    {
        return EstimateItem::query()->create(array_merge([
            'estimate_id' => $estimate->id,
            'position_number' => '1',
            'item_type' => EstimatePositionItemType::WORK->value,
            'name' => 'Section item ' . random_int(1000, 9999),
            'measurement_unit_id' => $unit->id,
            'quantity' => 1,
            'unit_price' => 1000,
            'direct_costs' => 1000,
            'total_amount' => 1000,
            'is_manual' => true,
        ], $overrides));
    }

    private function createMeasurementUnit(Organization $organization, array $overrides = []): MeasurementUnit
    {
        return MeasurementUnit::query()->create(array_merge([
            'organization_id' => $organization->id,
            'name' => 'Section unit ' . random_int(1000, 9999),
            'short_name' => 'su' . random_int(1000, 9999),
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
