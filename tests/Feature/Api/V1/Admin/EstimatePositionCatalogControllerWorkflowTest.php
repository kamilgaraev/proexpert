<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\EstimatePositionItemType;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimatePositionCatalog;
use App\Models\EstimatePositionCatalogCategory;
use App\Models\EstimatePositionPriceHistory;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\WorkType;
use App\Models\User;
use App\Services\EstimatePositionCatalog\EstimatePositionCatalogService;
use App\Services\EstimatePositionCatalog\ImportExportService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class EstimatePositionCatalogControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_updates_price_once_and_preserves_the_import_actor(): void
    {
        $context = AdminApiTestContext::create();
        $creator = User::factory()->create();
        $unit = $this->createMeasurementUnit($context->organization);
        $position = $this->createPosition($context->organization, $creator->id, $unit, ['unit_price' => 0]);
        $csv = "code,name,description,type,unit,price,direct,overhead,profit\n"
            . "{$position->code},Imported position,,work,{$unit->name},2,0,0,0\n";
        $file = UploadedFile::fake()->createWithContent('catalog.csv', $csv);

        $service = app(ImportExportService::class);
        $result = $service->importFromExcel($context->organization->id, $file, $context->user->id);
        $this->assertSame(['imported' => 1, 'skipped' => 0, 'errors' => []], $result);
        $this->assertSame('2.00', $position->fresh()->unit_price);
        $history = EstimatePositionPriceHistory::query()->where('catalog_item_id', $position->id)->get();
        $this->assertCount(1, $history);
        $this->assertSame($context->user->id, $history->first()->user_id);
        $this->assertSame('0.00', $history->first()->old_price);
        $this->assertSame('2.00', $history->first()->new_price);

        $this->assertSame(1, $service->importFromExcel($context->organization->id, $file, $context->user->id)['imported']);
        $this->assertSame(1, EstimatePositionPriceHistory::query()->where('catalog_item_id', $position->id)->count());
    }

    public function test_rejected_update_does_not_leave_price_history(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createMeasurementUnit($context->organization);
        $position = $this->createPosition($context->organization, $context->user->id, $unit, ['unit_price' => 0]);
        $other = $this->createPosition($context->organization, $context->user->id, $unit);
        $rejected = false;

        try {
            app(EstimatePositionCatalogService::class)->updatePosition(
                $position->id, $context->organization->id,
                ['unit_price' => 1, 'code' => $other->code], $context->user->id,
            );
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected);
        $this->assertSame('0.00', $position->fresh()->unit_price);
        $this->assertSame(0, EstimatePositionPriceHistory::query()->where('catalog_item_id', $position->id)->count());
    }

    public function test_catalog_resource_preserves_unit_code_and_legacy_symbol(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createMeasurementUnit($context->organization, ['short_name' => 'qa-unit']);
        $position = $this->createPosition($context->organization, $context->user->id, $unit);

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/estimate-positions/{$position->id}")
            ->assertOk()
            ->assertJsonPath('data.measurement_unit.id', $unit->id)
            ->assertJsonPath('data.measurement_unit.code', 'qa-unit')
            ->assertJsonPath('data.measurement_unit.short_name', 'qa-unit')
            ->assertJsonPath('data.measurement_unit.symbol', 'qa-unit');

        $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/estimate-positions/{$position->id}", ['name' => 'Updated unit display'])
            ->assertOk()
            ->assertJsonPath('data.measurement_unit.code', 'qa-unit');
    }

    public function test_price_update_writes_one_history_entry_with_actor_and_reason(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createMeasurementUnit($context->organization);
        $position = $this->createPosition($context->organization, $context->user->id, $unit, ['unit_price' => 0]);

        $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/estimate-positions/{$position->id}", [
                'unit_price' => 1, 'price_change_reason' => 'Teaching price correction',
            ])->assertOk();

        $history = EstimatePositionPriceHistory::query()->where('catalog_item_id', $position->id)->get();
        $this->assertCount(1, $history);
        $this->assertSame('0.00', $history->first()->old_price);
        $this->assertSame('1.00', $history->first()->new_price);
        $this->assertSame($context->user->id, $history->first()->user_id);
        $this->assertSame('Teaching price correction', $history->first()->change_reason);

        $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/estimate-positions/{$position->id}", [
                'name' => 'Name correction only', 'unit_price' => 1,
            ])->assertOk();
        $this->assertSame(1, EstimatePositionPriceHistory::query()->where('catalog_item_id', $position->id)->count());

        $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/estimate-positions/{$position->id}", ['unit_price' => 0])->assertOk();
        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/estimate-positions/{$position->id}/price-history")
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_index_is_tenant_scoped_and_tolerates_admin_registry_filters(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createMeasurementUnit($context->organization);
        $position = $this->createPosition($context->organization, $context->user->id, $unit, [
            'name' => 'Current concrete work',
            'code' => 'EST-CURRENT',
        ]);

        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignUnit = $this->createMeasurementUnit($foreignOrganization);
        $foreignPosition = $this->createPosition($foreignOrganization, $context->user->id, $foreignUnit, [
            'name' => 'Foreign concrete work',
            'code' => 'EST-FOREIGN',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/estimate-positions?category_id=&item_type=&is_active=&search=&sort_by=unknown_column&sort_direction=sideways&per_page=-1&page=1');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath(
            'meta.total',
            EstimatePositionCatalog::query()->where('organization_id', $context->organization->id)->count()
        );

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($position->id, $ids);
        $this->assertNotContains($foreignPosition->id, $ids);
    }

    public function test_store_show_update_search_and_delete_are_scoped_to_current_organization(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createMeasurementUnit($context->organization);
        $category = $this->createCategory($context->organization);
        $workType = $this->createWorkType($context->organization);

        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignUnit = $this->createMeasurementUnit($foreignOrganization);
        $foreignPosition = $this->createPosition($foreignOrganization, $context->user->id, $foreignUnit, [
            'code' => 'SHARED-CODE',
        ]);

        $createResponse = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/estimate-positions', [
                'organization_id' => $foreignOrganization->id,
                'category_id' => $category->id,
                'name' => 'Scoped catalog position',
                'code' => 'SHARED-CODE',
                'item_type' => EstimatePositionItemType::WORK->value,
                'measurement_unit_id' => $unit->id,
                'work_type_id' => $workType->id,
                'unit_price' => 1200,
            ]);

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('success', true);
        $createResponse->assertJsonPath('data.organization_id', $context->organization->id);
        $createResponse->assertJsonPath('data.code', 'SHARED-CODE');
        $positionId = $createResponse->json('data.id');

        $showResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/estimate-positions/{$positionId}");
        $showResponse->assertOk();
        $showResponse->assertJsonPath('data.id', $positionId);

        $updateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/estimate-positions/{$positionId}", [
                'name' => 'Updated catalog position',
                'unit_price' => 1300,
            ]);
        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('data.name', 'Updated catalog position');
        $updateResponse->assertJsonPath('data.unit_price', 1300);

        $searchResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/estimate-positions/search?q=Updated&category_id=&item_type=&is_active=');
        $searchResponse->assertOk();
        $searchResponse->assertJsonPath('success', true);
        $searchIds = collect($searchResponse->json('data'))->pluck('id')->all();
        $this->assertContains($positionId, $searchIds);
        $this->assertNotContains($foreignPosition->id, $searchIds);

        foreach (['getJson', 'putJson', 'deleteJson'] as $method) {
            $foreignResponse = $method === 'putJson'
                ? $this->withHeaders($context->authHeaders())->{$method}("/api/v1/admin/estimate-positions/{$foreignPosition->id}", ['name' => 'Leaked update'])
                : $this->withHeaders($context->authHeaders())->{$method}("/api/v1/admin/estimate-positions/{$foreignPosition->id}");

            $foreignResponse->assertNotFound();
        }

        $deleteResponse = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/estimate-positions/{$positionId}");
        $deleteResponse->assertOk();
        $deleteResponse->assertJsonPath('success', true);
        $this->assertSoftDeleted('estimate_position_catalog', ['id' => $positionId]);
    }

    public function test_store_and_update_reject_foreign_relationships_without_mutation(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createMeasurementUnit($context->organization);
        $position = $this->createPosition($context->organization, $context->user->id, $unit, [
            'name' => 'Original scoped position',
            'code' => 'ORIGINAL-SCOPED',
        ]);

        $foreignOrganization = Organization::factory()->verified()->create();
        $foreignUnit = $this->createMeasurementUnit($foreignOrganization);
        $foreignCategory = $this->createCategory($foreignOrganization);
        $foreignWorkType = $this->createWorkType($foreignOrganization);

        $basePayload = [
            'name' => 'Blocked position',
            'code' => 'BLOCKED-SCOPED',
            'item_type' => EstimatePositionItemType::WORK->value,
            'measurement_unit_id' => $unit->id,
            'unit_price' => 1000,
        ];

        foreach ([
            'category_id' => $foreignCategory->id,
            'measurement_unit_id' => $foreignUnit->id,
            'work_type_id' => $foreignWorkType->id,
        ] as $field => $value) {
            $response = $this->withHeaders($context->authHeaders())
                ->postJson('/api/v1/admin/estimate-positions', array_merge($basePayload, [
                    $field => $value,
                ]));

            $response->assertStatus(422);
            $response->assertJsonValidationErrors([$field]);
        }

        foreach ([
            'category_id' => $foreignCategory->id,
            'measurement_unit_id' => $foreignUnit->id,
            'work_type_id' => $foreignWorkType->id,
        ] as $field => $value) {
            $response = $this->withHeaders($context->authHeaders())
                ->putJson("/api/v1/admin/estimate-positions/{$position->id}", [
                    'name' => 'SHOULD-NOT-CHANGE',
                    $field => $value,
                ]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors([$field]);
        }

        $this->assertDatabaseMissing('estimate_position_catalog', [
            'organization_id' => $context->organization->id,
            'name' => 'Blocked position',
        ]);

        $position->refresh();
        $this->assertSame('Original scoped position', $position->name);
        $this->assertSame('ORIGINAL-SCOPED', $position->code);
    }

    public function test_position_used_in_estimate_cannot_be_deleted(): void
    {
        $context = AdminApiTestContext::create();
        $unit = $this->createMeasurementUnit($context->organization);
        $position = $this->createPosition($context->organization, $context->user->id, $unit);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project);

        EstimateItem::query()->create([
            'estimate_id' => $estimate->id,
            'catalog_item_id' => $position->id,
            'position_number' => '1',
            'name' => 'Used item',
            'measurement_unit_id' => $unit->id,
            'quantity' => 1,
            'unit_price' => 1000,
            'direct_costs' => 1000,
            'total_amount' => 1000,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/estimate-positions/{$position->id}");

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertDatabaseHas('estimate_position_catalog', [
            'id' => $position->id,
            'deleted_at' => null,
        ]);
    }

    private function createPosition(
        Organization $organization,
        int $userId,
        MeasurementUnit $unit,
        array $overrides = []
    ): EstimatePositionCatalog {
        return EstimatePositionCatalog::query()->create(array_merge([
            'organization_id' => $organization->id,
            'category_id' => null,
            'name' => 'Catalog position ' . random_int(1000, 9999),
            'code' => 'EST-' . random_int(10000, 99999),
            'item_type' => EstimatePositionItemType::WORK->value,
            'measurement_unit_id' => $unit->id,
            'work_type_id' => null,
            'unit_price' => 1000,
            'direct_costs' => null,
            'overhead_percent' => null,
            'profit_percent' => null,
            'is_active' => true,
            'created_by_user_id' => $userId,
        ], $overrides));
    }

    private function createCategory(Organization $organization, array $overrides = []): EstimatePositionCatalogCategory
    {
        return EstimatePositionCatalogCategory::query()->create(array_merge([
            'organization_id' => $organization->id,
            'name' => 'Catalog category ' . random_int(1000, 9999),
            'sort_order' => 1,
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

    private function createEstimate(Organization $organization, Project $project, array $overrides = []): Estimate
    {
        return Estimate::query()->create(array_merge([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'number' => 'CAT-EST-' . random_int(10000, 99999),
            'name' => 'Catalog estimate',
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
}
