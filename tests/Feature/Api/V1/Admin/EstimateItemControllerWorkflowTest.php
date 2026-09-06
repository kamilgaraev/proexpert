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

    public function test_project_item_routes_reject_estimate_from_another_project_in_same_organization(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $otherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $otherProject);
        $unit = $this->createMeasurementUnit($context->organization);
        $this->createItem($estimate, $unit);
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/items");

        $response->assertNotFound();
    }

    public function test_reorder_rejects_foreign_items_and_sections_without_mutation(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project);
        $unit = $this->createMeasurementUnit($context->organization);
        $item = $this->createItem($estimate, $unit, ['position_number' => '1']);

        $foreignEstimate = $this->createEstimate($context->organization, $project, ['number' => 'ITEM-FOREIGN-EST']);
        $foreignItem = $this->createItem($foreignEstimate, $unit, ['position_number' => '9']);
        $foreignSection = $this->createSection($foreignEstimate);
        $this->allowAdminAccess();

        $foreignItemResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/items/reorder", [
                'items' => [
                    [
                        'id' => $foreignItem->id,
                        'estimate_section_id' => null,
                        'sort_order' => 0,
                    ],
                ],
            ]);

        $foreignItemResponse->assertStatus(422);
        $foreignItemResponse->assertJsonValidationErrors(['items.0.id']);

        $foreignSectionResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/items/reorder", [
                'items' => [
                    [
                        'id' => $item->id,
                        'estimate_section_id' => $foreignSection->id,
                        'sort_order' => 0,
                    ],
                ],
            ]);

        $foreignSectionResponse->assertStatus(422);
        $foreignSectionResponse->assertJsonValidationErrors(['items.0.estimate_section_id']);

        $item->refresh();
        $foreignItem->refresh();
        $this->assertNull($item->estimate_section_id);
        $this->assertSame('9', $foreignItem->position_number);
    }

    public function test_reorder_uses_request_order_for_section_numbering_and_moves_items_between_sections(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project);
        $unit = $this->createMeasurementUnit($context->organization);
        $firstSection = $this->createSection($estimate, ['section_number' => '1', 'sort_order' => 1]);
        $secondSection = $this->createSection($estimate, ['section_number' => '2', 'sort_order' => 2]);

        $firstItem = $this->createItem($estimate, $unit, [
            'estimate_section_id' => $firstSection->id,
            'name' => 'First item',
            'position_number' => '1',
        ]);
        $secondItem = $this->createItem($estimate, $unit, [
            'estimate_section_id' => $firstSection->id,
            'name' => 'Second item',
            'position_number' => '2',
        ]);
        $thirdItem = $this->createItem($estimate, $unit, [
            'estimate_section_id' => $firstSection->id,
            'name' => 'Third item',
            'position_number' => '3',
        ]);
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/items/reorder", [
                'items' => [
                    [
                        'id' => $firstItem->id,
                        'estimate_section_id' => $secondSection->id,
                        'sort_order' => 1,
                    ],
                    [
                        'id' => $thirdItem->id,
                        'estimate_section_id' => $secondSection->id,
                        'sort_order' => 0,
                    ],
                    [
                        'id' => $secondItem->id,
                        'estimate_section_id' => $firstSection->id,
                        'sort_order' => 0,
                    ],
                ],
                'numbering_mode' => 'section',
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $firstItem->refresh();
        $secondItem->refresh();
        $thirdItem->refresh();

        $this->assertSame($secondSection->id, $thirdItem->estimate_section_id);
        $this->assertSame('1', $thirdItem->position_number);
        $this->assertSame($secondSection->id, $firstItem->estimate_section_id);
        $this->assertSame('2', $firstItem->position_number);
        $this->assertSame($firstSection->id, $secondItem->estimate_section_id);
        $this->assertSame('1', $secondItem->position_number);
    }

    public function test_reorder_supports_hierarchical_numbering_inside_section(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project);
        $unit = $this->createMeasurementUnit($context->organization);
        $section = $this->createSection($estimate, ['section_number' => '4', 'sort_order' => 1]);

        $firstItem = $this->createItem($estimate, $unit, [
            'estimate_section_id' => $section->id,
            'position_number' => '4.1',
        ]);
        $secondItem = $this->createItem($estimate, $unit, [
            'estimate_section_id' => $section->id,
            'position_number' => '4.2',
        ]);
        $this->allowAdminAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/items/reorder", [
                'items' => [
                    [
                        'id' => $secondItem->id,
                        'estimate_section_id' => $section->id,
                        'sort_order' => 0,
                    ],
                    [
                        'id' => $firstItem->id,
                        'estimate_section_id' => $section->id,
                        'sort_order' => 1,
                    ],
                ],
                'numbering_mode' => 'hierarchical',
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $firstItem->refresh();
        $secondItem->refresh();

        $this->assertSame('4.2', $firstItem->position_number);
        $this->assertSame('4.1', $secondItem->position_number);
    }

    public function test_reorder_preserves_existing_order_in_other_sections_and_at_root(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project);
        $unit = $this->createMeasurementUnit($context->organization);
        $target = $this->createSection($estimate, ['section_number' => '1', 'sort_order' => 0]);
        $untouched = $this->createSection($estimate, ['section_number' => '2', 'sort_order' => 1]);
        $first = $this->createItem($estimate, $unit, ['estimate_section_id' => $target->id, 'position_number' => '1']);
        $second = $this->createItem($estimate, $unit, ['estimate_section_id' => $target->id, 'position_number' => '2']);
        $untouchedLast = $this->createItem($estimate, $unit, ['estimate_section_id' => $untouched->id, 'position_number' => '2']);
        $untouchedFirst = $this->createItem($estimate, $unit, ['estimate_section_id' => $untouched->id, 'position_number' => '1']);
        $rootLast = $this->createItem($estimate, $unit, ['position_number' => '2']);
        $rootFirst = $this->createItem($estimate, $unit, ['position_number' => '1']);
        $this->allowAdminAccess();

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/items/reorder", [
                'items' => [
                    ['id' => $second->id, 'estimate_section_id' => $target->id, 'sort_order' => 0],
                    ['id' => $first->id, 'estimate_section_id' => $target->id, 'sort_order' => 1],
                ],
                'numbering_mode' => 'section',
            ])->assertOk();

        $this->assertSame('1', $second->fresh()->position_number);
        $this->assertSame('2', $first->fresh()->position_number);
        $this->assertSame('1', $untouchedFirst->fresh()->position_number);
        $this->assertSame('2', $untouchedLast->fresh()->position_number);
        $this->assertSame('1', $rootFirst->fresh()->position_number);
        $this->assertSame('2', $rootLast->fresh()->position_number);
    }

    public function test_reorder_moves_work_resources_and_recalculates_parent_section_totals(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project);
        $unit = $this->createMeasurementUnit($context->organization);
        $sourceParent = $this->createSection($estimate, ['section_total_amount' => 1500]);
        $source = $this->createSection($estimate, ['parent_section_id' => $sourceParent->id, 'section_total_amount' => 1500]);
        $destinationParent = $this->createSection($estimate, ['section_total_amount' => 0]);
        $destination = $this->createSection($estimate, ['parent_section_id' => $destinationParent->id, 'section_total_amount' => 0]);
        $work = $this->createItem($estimate, $unit, ['estimate_section_id' => $source->id, 'total_amount' => 1000]);
        $resource = $this->createItem($estimate, $unit, [
            'estimate_section_id' => $source->id,
            'parent_work_id' => $work->id,
            'item_type' => EstimatePositionItemType::MATERIAL->value,
            'total_amount' => 300,
        ]);
        $remaining = $this->createItem($estimate, $unit, ['estimate_section_id' => $source->id, 'total_amount' => 500]);
        $this->allowAdminAccess();

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/items/reorder", [
                'items' => [
                    ['id' => $work->id, 'estimate_section_id' => $destination->id, 'sort_order' => 0],
                ],
                'numbering_mode' => 'section',
            ])->assertOk();

        $this->assertSame($destination->id, $work->fresh()->estimate_section_id);
        $this->assertSame($destination->id, $resource->fresh()->estimate_section_id);
        $this->assertSame($work->id, $resource->fresh()->parent_work_id);
        $this->assertSame($source->id, $remaining->fresh()->estimate_section_id);
        $this->assertEquals(500, $source->fresh()->section_total_amount);
        $this->assertEquals(500, $sourceParent->fresh()->section_total_amount);
        $this->assertEquals(1000, $destination->fresh()->section_total_amount);
        $this->assertEquals(1000, $destinationParent->fresh()->section_total_amount);
    }

    public function test_move_appends_work_with_resources_to_destination(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project);
        $unit = $this->createMeasurementUnit($context->organization);
        $source = $this->createSection($estimate);
        $destination = $this->createSection($estimate);
        $existing = $this->createItem($estimate, $unit, ['estimate_section_id' => $destination->id]);
        $work = $this->createItem($estimate, $unit, ['estimate_section_id' => $source->id]);
        $resource = $this->createItem($estimate, $unit, [
            'estimate_section_id' => $source->id,
            'parent_work_id' => $work->id,
            'item_type' => EstimatePositionItemType::MATERIAL->value,
        ]);
        $this->allowAdminAccess();

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/items/{$work->id}/move", [
                'section_id' => $destination->id,
            ])->assertOk();

        $this->assertSame($destination->id, $work->fresh()->estimate_section_id);
        $this->assertSame($destination->id, $resource->fresh()->estimate_section_id);
        $this->assertSame($work->id, $resource->fresh()->parent_work_id);
        $this->assertSame('1', $existing->fresh()->position_number);
        $this->assertSame('2', $work->fresh()->position_number);
        $this->assertEquals(0, $source->fresh()->section_total_amount);
        $this->assertEquals(2000, $destination->fresh()->section_total_amount);
    }

    public function test_reorder_rejects_conflicting_resource_destination_atomically(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project);
        $unit = $this->createMeasurementUnit($context->organization);
        $source = $this->createSection($estimate, ['section_total_amount' => 1000]);
        $destination = $this->createSection($estimate, ['section_total_amount' => 0]);
        $work = $this->createItem($estimate, $unit, ['estimate_section_id' => $source->id]);
        $resource = $this->createItem($estimate, $unit, [
            'estimate_section_id' => $source->id,
            'parent_work_id' => $work->id,
            'item_type' => EstimatePositionItemType::MATERIAL->value,
        ]);
        $this->allowAdminAccess();

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/items/reorder", [
                'items' => [
                    ['id' => $work->id, 'estimate_section_id' => $destination->id, 'sort_order' => 0],
                    ['id' => $resource->id, 'estimate_section_id' => $source->id, 'sort_order' => 1],
                ],
            ])->assertStatus(422);

        $this->assertSame($source->id, $work->fresh()->estimate_section_id);
        $this->assertSame($source->id, $resource->fresh()->estimate_section_id);
        $this->assertEquals(1000, $source->fresh()->section_total_amount);
        $this->assertEquals(0, $destination->fresh()->section_total_amount);
    }

    public function test_move_preserves_selected_and_existing_numbering_modes(): void
    {
        foreach ([['hierarchical', true], ['global', true], ['hierarchical', false], ['global', false]] as [$mode, $remembered]) {
            $context = AdminApiTestContext::create();
            $project = Project::factory()->create(['organization_id' => $context->organization->id]);
            $estimate = $this->createEstimate($context->organization, $project);
            $unit = $this->createMeasurementUnit($context->organization);
            $source = $this->createSection($estimate, ['section_number' => '1', 'sort_order' => 1]);
            $destination = $this->createSection($estimate, ['section_number' => '2', 'sort_order' => 2]);
            $untouched = $this->createSection($estimate, ['section_number' => '3', 'sort_order' => 3]);
            $work = $this->createItem($estimate, $unit, ['estimate_section_id' => $source->id]);
            $this->createItem($estimate, $unit, ['estimate_section_id' => $destination->id]);
            $untouchedItem = $this->createItem($estimate, $unit, ['estimate_section_id' => $untouched->id]);
            $this->allowAdminAccess();
            $url = "/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/items";

            $this->withHeaders($context->authHeaders())->postJson("{$url}/recalculate-numbers", [
                'numbering_mode' => $mode,
            ])->assertOk();
            $this->assertSame($mode, $estimate->fresh()->metadata['numbering_mode']);
            $untouchedNumber = $untouchedItem->fresh()->position_number;
            if (!$remembered) {
                $estimate->update(['metadata' => []]);
            }

            $this->withHeaders($context->authHeaders())->postJson("{$url}/{$work->id}/move", [
                'section_id' => $destination->id,
            ])->assertOk();

            $this->assertSame($destination->id, $work->fresh()->estimate_section_id);
            $this->assertSame($untouchedNumber, $untouchedItem->fresh()->position_number);
            $this->assertSame($mode, $estimate->fresh()->metadata['numbering_mode']);
            $this->assertSame($mode === 'hierarchical' ? '2.2' : '2', $work->fresh()->position_number);
        }
    }

    public function test_move_places_work_before_after_and_at_root_with_resources(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project, ['metadata' => ['numbering_mode' => 'section']]);
        $unit = $this->createMeasurementUnit($context->organization);
        $source = $this->createSection($estimate);
        $destination = $this->createSection($estimate);
        $first = $this->createItem($estimate, $unit, ['estimate_section_id' => $destination->id, 'position_number' => '1']);
        $last = $this->createItem($estimate, $unit, ['estimate_section_id' => $destination->id, 'position_number' => '2']);
        $work = $this->createItem($estimate, $unit, ['estimate_section_id' => $source->id]);
        $resource = $this->createItem($estimate, $unit, ['estimate_section_id' => $source->id, 'parent_work_id' => $work->id]);
        $root = $this->createItem($estimate, $unit);
        $this->allowAdminAccess();
        $url = "/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/items/{$work->id}/move";

        foreach ([['before', '1', '2'], ['after', '2', '1']] as [$placement, $workNumber, $firstNumber]) {
            $this->withHeaders($context->authHeaders())->postJson($url, [
                'section_id' => $destination->id,
                'anchor_item_id' => $first->id,
                'placement' => $placement,
            ])->assertOk();
            $this->assertSame($workNumber, $work->fresh()->position_number);
            $this->assertSame($firstNumber, $first->fresh()->position_number);
            $this->assertSame('3', $last->fresh()->position_number);
            $this->assertSame($destination->id, $resource->fresh()->estimate_section_id);
        }

        $this->withHeaders($context->authHeaders())->postJson($url, [
            'section_id' => null,
            'anchor_item_id' => $root->id,
            'placement' => 'before',
        ])->assertOk();
        $this->assertNull($work->fresh()->estimate_section_id);
        $this->assertNull($resource->fresh()->estimate_section_id);
        $this->assertSame($work->id, $resource->fresh()->parent_work_id);
        $this->assertSame('1', $work->fresh()->position_number);
        $this->assertSame('2', $root->fresh()->position_number);
        $this->assertEquals(2000, $destination->fresh()->section_total_amount);
    }

    public function test_move_rejects_invalid_anchor_without_changing_work_or_resources(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project);
        $unit = $this->createMeasurementUnit($context->organization);
        $source = $this->createSection($estimate);
        $destination = $this->createSection($estimate);
        $work = $this->createItem($estimate, $unit, ['estimate_section_id' => $source->id]);
        $resource = $this->createItem($estimate, $unit, ['estimate_section_id' => $source->id, 'parent_work_id' => $work->id]);
        $wrongSection = $this->createItem($estimate, $unit, ['estimate_section_id' => $source->id]);
        $foreignEstimate = $this->createEstimate($context->organization, $project);
        $foreignItem = $this->createItem($foreignEstimate, $unit);
        $this->allowAdminAccess();
        $url = "/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/items";

        foreach ([$work->id, $resource->id, $wrongSection->id, $foreignItem->id] as $anchorId) {
            $this->withHeaders($context->authHeaders())->postJson("{$url}/{$work->id}/move", [
                'section_id' => $destination->id,
                'anchor_item_id' => $anchorId,
                'placement' => 'before',
            ])->assertStatus(422);
            $this->assertSame($source->id, $work->fresh()->estimate_section_id);
            $this->assertSame($source->id, $resource->fresh()->estimate_section_id);
        }

        $this->withHeaders($context->authHeaders())->postJson("{$url}/{$resource->id}/move", [
            'section_id' => null,
        ])->assertStatus(422);
        $this->assertSame($work->id, $resource->fresh()->parent_work_id);
        $this->assertSame($source->id, $resource->fresh()->estimate_section_id);
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
