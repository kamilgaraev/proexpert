<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\EstimatePositionItemType;
use App\BusinessModules\Features\BudgetEstimates\Jobs\CalculateEstimateStatisticsJob;
use App\BusinessModules\Features\BudgetEstimates\Jobs\GenerateEstimateSnapshotJob;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimatePositionCatalog;
use App\Models\EstimateSection;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class EstimateConstructorControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        Bus::fake([CalculateEstimateStatisticsJob::class, GenerateEstimateSnapshotJob::class]);
        \App\Models\Module::query()->create([
            'name' => 'Сметы',
            'slug' => 'budget-estimates',
            'version' => '1.0.0',
            'type' => 'feature',
            'billing_model' => 'free',
            'class_name' => \App\BusinessModules\Features\BudgetEstimates\BudgetEstimatesModule::class,
            'is_active' => true,
            'is_system_module' => true,
            'can_deactivate' => false,
        ]);
    }

    public function test_adding_normative_to_approved_estimate_returns_conflict_without_writing(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')
                ->andReturnUsing(static fn (int $organizationId, string $moduleSlug): bool => $moduleSlug === 'budget-estimates');
        });

        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project, ['status' => 'approved']);
        $datasetId = DB::table('estimate_dataset_versions')->insertGetId([
            'source_type' => 'fsnb',
            'version_key' => 'approved-estimate-guard',
            'bucket' => 'tests',
            'prefix' => 'tests',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $collectionId = DB::table('estimate_norm_collections')->insertGetId([
            'dataset_version_id' => $datasetId,
            'code' => 'GUARD',
            'name' => 'Проверка защиты утверждённой сметы',
            'norm_type' => 'gesn',
            'source_file' => 'guard.json',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $normId = DB::table('estimate_norms')->insertGetId([
            'collection_id' => $collectionId,
            'code' => 'GUARD-01',
            'name' => 'Тестовая расценка',
            'unit' => 'шт.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}/items/from-estimate-norms", [
                'items' => [[
                    'estimate_norm_id' => $normId,
                    'quantity' => 1,
                ]],
            ]);

        $response->assertConflict();
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', trans_message('estimate.structure_locked'));
        $this->assertDatabaseMissing('estimate_items', ['estimate_id' => $estimate->id]);
    }

    public function test_bulk_delete_returns_admin_contract_and_stays_inside_current_organization(): void
    {
        $context = $this->createContext();
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
        $context = $this->createContext();
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

    public function test_add_from_catalog_and_copy_generate_next_position_numbers(): void
    {
        $context = $this->createContext();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project);
        $targetEstimate = $this->createEstimate($context->organization, $project, ['number' => 'TARGET-POSITIONS']);
        $unit = $this->createMeasurementUnit($context->organization);
        $section = $this->createSection($estimate);
        $targetSection = $this->createSection($targetEstimate);
        $catalogItem = $this->createCatalogItem($context->organization, $context->user, $unit);
        $sourceItem = $this->createItem($estimate, $unit, ['position_number' => '10']);

        $this->createItem($estimate, $unit, ['position_number' => '1']);
        $this->createItem($estimate, $unit, [
            'estimate_section_id' => $section->id,
            'position_number' => '1.9',
        ]);
        $this->createItem($targetEstimate, $unit, ['position_number' => '2']);
        $this->createItem($targetEstimate, $unit, [
            'estimate_section_id' => $targetSection->id,
            'position_number' => '1.2',
        ]);

        $addResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/estimates/constructor/{$estimate->id}/add-from-catalog", [
                'items' => [
                    ['catalog_item_id' => $catalogItem->id, 'quantity' => 1],
                    ['catalog_item_id' => $catalogItem->id, 'quantity' => 1, 'section_id' => $section->id],
                ],
            ]);

        $addResponse->assertCreated();
        $addResponse->assertJsonPath('success', true);
        $addResponse->assertJsonPath('data.items.0.position_number', '11');
        $addResponse->assertJsonPath('data.items.1.position_number', '1.10');

        $copyTopResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/estimates/constructor/{$estimate->id}/copy-items", [
                'item_ids' => [$sourceItem->id],
                'target_estimate_id' => $targetEstimate->id,
            ]);

        $copyTopResponse->assertCreated();
        $copyTopResponse->assertJsonPath('data.items.0.position_number', '3');

        $copySectionResponse = $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/estimates/constructor/{$estimate->id}/copy-items", [
                'item_ids' => [$sourceItem->id],
                'target_estimate_id' => $targetEstimate->id,
                'target_section_id' => $targetSection->id,
            ]);

        $copySectionResponse->assertCreated();
        $copySectionResponse->assertJsonPath('data.items.0.position_number', '1.3');
    }

    public function test_bulk_update_recalculates_client_totals_and_rejects_invalid_values(): void
    {
        $context = $this->createContext();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project);
        $item = $this->createItem($estimate, $this->createMeasurementUnit($context->organization));
        $estimate->update(['structure_cache_path' => 'stale-constructor.json']);
        $endpoint = "/api/v1/admin/estimates/constructor/{$estimate->id}/bulk-update";
        $response = $this->withHeaders($context->authHeaders())->postJson($endpoint, ['items' => [[
            'id' => $item->id, 'quantity' => 3, 'unit_price' => 150,
            'direct_costs' => 1, 'overhead_amount' => 30, 'profit_amount' => 15, 'total_amount' => 1,
        ]]]);
        $this->assertSame(200, $response->status(), $response->getContent());
        $this->assertNull($estimate->fresh()->structure_cache_path);
        $this->assertEquals(450, $item->fresh()->direct_costs);
        $this->assertEquals(495, $item->fresh()->total_amount);
        $this->withHeaders($context->authHeaders())->postJson($endpoint, ['items' => [[
            'id' => $item->id, 'quantity' => -1,
        ]]])->assertUnprocessable();
        $this->assertEquals(3, $item->fresh()->quantity);
    }

    public function test_bulk_save_updates_nested_sections_and_estimate_totals_without_an_extra_recalculation(): void
    {
        $context = $this->createContext();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project, [
            'vat_rate' => 0, 'total_direct_costs' => 30300, 'total_amount' => 30300, 'total_amount_with_vat' => 30300,
        ]);
        $root = $this->createSection($estimate, ['section_total_amount' => 30300]);
        $child = $this->createSection($estimate, ['parent_section_id' => $root->id, 'section_total_amount' => 30300]);
        $unit = $this->createMeasurementUnit($context->organization);
        $primer = $this->createItem($estimate, $unit, [
            'estimate_section_id' => $child->id, 'quantity' => 20, 'unit_price' => 180, 'direct_costs' => 3600, 'total_amount' => 3600,
        ]);
        $this->createItem($estimate, $unit, ['estimate_section_id' => $child->id, 'direct_costs' => 16200, 'total_amount' => 16200]);
        $this->createItem($estimate, $unit, ['estimate_section_id' => $root->id, 'direct_costs' => 10500, 'total_amount' => 10500]);
        $this->createItem($estimate, $unit, ['estimate_section_id' => $child->id, 'parent_work_id' => $primer->id, 'total_amount' => 90000]);
        $this->createItem($estimate, $unit, ['estimate_section_id' => $root->id, 'is_not_accounted' => true, 'total_amount' => 80000]);

        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/estimates/constructor/{$estimate->id}/bulk-update", [
                'items' => [['id' => $primer->id, 'quantity' => 21]],
            ])->assertOk();

        $this->assertEquals(3780, $primer->fresh()->total_amount);
        $this->assertEquals(19980, $child->fresh()->section_total_amount);
        $this->assertEquals(30480, $root->fresh()->section_total_amount);
        $this->assertEquals(30480, $estimate->fresh()->total_direct_costs);
        $this->assertEquals(30480, $estimate->fresh()->total_amount);
        $this->assertEquals(30480, $estimate->fresh()->total_amount_with_vat);
        Bus::assertDispatchedTimes(GenerateEstimateSnapshotJob::class, 1);
        Bus::assertDispatchedTimes(CalculateEstimateStatisticsJob::class, 1);
    }

    public function test_move_copy_and_delete_refresh_section_totals_and_keep_vat_and_contingency(): void
    {
        $context = $this->createContext();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project, ['vat_rate' => 20, 'contingency_amount' => 500]);
        $firstSection = $this->createSection($estimate);
        $secondSection = $this->createSection($estimate);
        $unit = $this->createMeasurementUnit($context->organization);
        $item = $this->createItem($estimate, $unit, ['estimate_section_id' => $firstSection->id]);
        $this->createItem($estimate, $unit, ['quantity' => 2, 'unit_price' => 100, 'direct_costs' => 200, 'total_amount' => 200]);
        $endpoint = "/api/v1/admin/estimates/constructor/{$estimate->id}";

        $this->withHeaders($context->authHeaders())->postJson("{$endpoint}/move-to-section", [
            'item_ids' => [$item->id], 'section_id' => $secondSection->id,
        ])->assertOk();
        $this->assertEquals(0, $firstSection->fresh()->section_total_amount);
        $this->assertEquals(1000, $secondSection->fresh()->section_total_amount);
        $this->assertEquals(1700, $estimate->fresh()->total_amount);
        $this->assertEquals(2040, $estimate->fresh()->total_amount_with_vat);

        $this->withHeaders($context->authHeaders())->postJson("{$endpoint}/copy-items", [
            'item_ids' => [$item->id], 'target_estimate_id' => $estimate->id, 'target_section_id' => $secondSection->id,
        ])->assertCreated();
        $this->assertEquals(2000, $secondSection->fresh()->section_total_amount);
        $this->assertEquals(2700, $estimate->fresh()->total_amount);
        $this->assertEquals(3240, $estimate->fresh()->total_amount_with_vat);
        $this->assertEquals(1, $item->fresh()->quantity);
        $this->assertEquals(1000, $item->fresh()->unit_price);

        $this->withHeaders($context->authHeaders())->postJson("{$endpoint}/bulk-delete", [
            'item_ids' => [$item->id],
        ])->assertOk();
        $this->assertEquals(1000, $secondSection->fresh()->section_total_amount);
        $this->assertEquals(1700, $estimate->fresh()->total_amount);
        $this->assertEquals(2040, $estimate->fresh()->total_amount_with_vat);
    }

    public function test_explicit_recalculation_dispatches_snapshot_and_statistics_only_once(): void
    {
        $context = $this->createContext();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project);
        $this->createItem($estimate, $this->createMeasurementUnit($context->organization));
        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/estimates/constructor/{$estimate->id}/recalculate", [])
            ->assertOk();
        Bus::assertDispatchedTimes(GenerateEstimateSnapshotJob::class, 1);
        Bus::assertDispatchedTimes(CalculateEstimateStatisticsJob::class, 1);
    }

    public function test_saving_restored_positions_uses_fresh_structure_and_old_positions_return_conflict(): void
    {
        $context = $this->createContext();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project);
        $oldItem = $this->createItem($estimate, $this->createMeasurementUnit($context->organization));
        $version = app(\App\BusinessModules\Features\BudgetEstimates\Services\EstimateVersioningService::class)
            ->createSnapshot($estimate, $context->user->id);
        $estimate->update(['structure_cache_path' => 'old-positions.json']);
        app(\App\BusinessModules\Features\BudgetEstimates\Services\Versioning\EstimateVersionRestoreService::class)
            ->restore($estimate, $version, $context->user->id);
        $this->assertNull($estimate->fresh()->structure_cache_path);
        $currentItem = EstimateItem::where('estimate_id', $estimate->id)->firstOrFail();
        $this->assertNotSame($oldItem->id, $currentItem->id);
        $endpoint = "/api/v1/admin/estimates/constructor/{$estimate->id}/bulk-update";
        $this->withHeaders($context->authHeaders())->postJson($endpoint, ['items' => [[
            'id' => $oldItem->id, 'quantity' => 3,
        ]]])->assertConflict()->assertJsonPath('message', trans_message('estimate_constructor.positions_changed'));
        $this->withHeaders($context->authHeaders())->postJson($endpoint, ['items' => [[
            'id' => $currentItem->id, 'quantity' => 3,
        ]]])->assertOk();
        $this->assertEquals(3, $currentItem->fresh()->quantity);
    }

    public function test_normative_quantity_change_preserves_indices_and_recalculates_the_total(): void
    {
        $context = $this->createContext();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project);
        $baseId = \Illuminate\Support\Facades\DB::table('normative_base_types')->insertGetId(['code' => 'TEST', 'name' => 'Test']);
        $collectionId = \Illuminate\Support\Facades\DB::table('normative_collections')->insertGetId(['base_type_id' => $baseId, 'code' => 'TEST', 'name' => 'Test']);
        $rateId = \Illuminate\Support\Facades\DB::table('normative_rates')->insertGetId([
            'collection_id' => $collectionId, 'code' => 'TEST-1', 'name' => 'Test',
            'materials_cost' => 100, 'machinery_cost' => 20, 'labor_cost' => 30,
        ]);
        $item = $this->createItem($estimate, $this->createMeasurementUnit($context->organization), [
            'normative_rate_id' => $rateId, 'quantity' => 1, 'unit_price' => 350,
            'materials_index' => 2, 'machinery_index' => 3, 'labor_index' => 3,
        ]);
        $endpoint = "/api/v1/admin/estimates/constructor/{$estimate->id}/bulk-update";
        $this->withHeaders($context->authHeaders())->postJson($endpoint, ['items' => [[
            'id' => $item->id, 'quantity' => 2, 'overhead_amount' => 30, 'profit_amount' => 15, 'total_amount' => 1,
        ]]])->assertOk();
        $updated = $item->fresh();
        $this->assertEquals(400, $updated->materials_cost);
        $this->assertEquals(120, $updated->machinery_cost);
        $this->assertEquals(180, $updated->labor_cost);
        $this->assertEquals(700, $updated->direct_costs);
        $this->assertEquals(350, $updated->unit_price);
        $this->assertEquals(745, $updated->total_amount);
        $this->assertEquals(745, $estimate->fresh()->total_amount);
    }

    public function test_constructor_denies_a_project_without_assignment(): void
    {
        $context = $this->createContext();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project);
        $project->users()->detach($context->user->id);
        $context->organization->users()->updateExistingPivot($context->user->id, ['project_access_mode' => 'assigned_projects']);
        $item = $this->createItem($estimate, $this->createMeasurementUnit($context->organization));
        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/estimates/constructor/{$estimate->id}/bulk-delete", ['item_ids' => [$item->id]])
            ->assertForbidden();
        $this->assertNotSoftDeleted('estimate_items', ['id' => $item->id]);
    }

    public function test_constructor_denies_missing_edit_permission(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project);
        $item = $this->createItem($estimate, $this->createMeasurementUnit($context->organization));
        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/estimates/constructor/{$estimate->id}/bulk-delete", ['item_ids' => [$item->id]])
            ->assertForbidden();
        $this->assertNotSoftDeleted('estimate_items', ['id' => $item->id]);
    }

    public function test_stale_bulk_update_is_rejected_without_mutation(): void
    {
        $context = $this->createContext();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project);
        $item = $this->createItem($estimate, $this->createMeasurementUnit($context->organization));
        $this->withHeaders($context->authHeaders())
            ->postJson("/api/v1/admin/estimates/constructor/{$estimate->id}/bulk-update", ['items' => [[
                'id' => $item->id, 'quantity' => 2, 'expected_updated_at' => '2000-01-01T00:00:00Z',
            ]]])->assertConflict();
        $this->assertEquals(1, $item->fresh()->quantity);
    }

    public function test_approved_estimate_rejects_constructor_and_card_mutations_before_writing(): void
    {
        $context = $this->createContext();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project);
        $item = $this->createItem($estimate, $this->createMeasurementUnit($context->organization));
        DB::table('estimates')->where('id', $estimate->id)->update(['status' => 'approved']);
        $before = $item->fresh()->getAttributes();
        $constructor = "/api/v1/admin/estimates/constructor/{$estimate->id}";
        $card = "/api/v1/admin/projects/{$project->id}/estimates/{$estimate->id}";

        foreach ([
            ['postJson', "{$constructor}/bulk-delete", ['item_ids' => [$item->id]]],
            ['postJson', "{$constructor}/bulk-update", ['items' => [['id' => $item->id, 'quantity' => 2]]]],
            ['postJson', "{$constructor}/recalculate", []],
            ['postJson', "{$card}/recalculate", []],
            ['putJson', $card, ['vat_rate' => 10]],
        ] as [$method, $url, $payload]) {
            $response = $this->withHeaders($context->authHeaders())->{$method}($url, $payload);
            $response->assertConflict();
            $response->assertJsonPath('success', false);
            $response->assertJsonPath('message', trans_message('estimate.structure_locked'));
            $this->assertSame($before, $item->fresh()->getAttributes());
            $this->assertSame('approved', $estimate->fresh()->status);
        }
    }

    public function test_copy_from_approved_estimate_to_draft_preserves_source_and_rejects_approved_target(): void
    {
        $context = $this->createContext();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $source = $this->createEstimate($context->organization, $project);
        $target = $this->createEstimate($context->organization, $project);
        $item = $this->createItem($source, $this->createMeasurementUnit($context->organization));
        DB::table('estimates')->where('id', $source->id)->update(['status' => 'approved']);
        $before = $item->fresh()->getAttributes();
        $url = "/api/v1/admin/estimates/constructor/{$source->id}/copy-items";
        $payload = ['item_ids' => [$item->id], 'target_estimate_id' => $target->id];

        $this->withHeaders($context->authHeaders())->postJson($url, $payload)
            ->assertCreated()->assertJsonPath('data.copied_count', 1);
        $this->assertSame($before, $item->fresh()->getAttributes());
        $this->assertSame(1, $target->items()->count());
        DB::table('estimates')->where('id', $target->id)->update(['status' => 'approved']);
        $this->withHeaders($context->authHeaders())->postJson($url, $payload)
            ->assertConflict()->assertJsonPath('message', trans_message('estimate.structure_locked'));
        $this->assertSame(1, $target->items()->count());
    }

    public function test_stale_draft_model_cannot_update_or_recalculate_an_approved_estimate(): void
    {
        $context = $this->createContext();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $estimate = $this->createEstimate($context->organization, $project);
        $item = $this->createItem($estimate, $this->createMeasurementUnit($context->organization));
        DB::table('estimates')->where('id', $estimate->id)->update(['status' => 'approved']);
        $before = $item->fresh()->getAttributes();

        foreach ([
            fn () => app(\App\BusinessModules\Features\BudgetEstimates\Services\EstimateService::class)->update($estimate, ['vat_rate' => 10]),
            fn () => app(\App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService::class)->recalculateAll($estimate),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Approved estimate must reject a stale draft model');
            } catch (\Illuminate\Validation\ValidationException $exception) {
                $this->assertSame(409, $exception->status);
                $this->assertSame([trans_message('estimate.structure_locked')], $exception->errors()['estimate']);
            }
            $this->assertSame($before, $item->fresh()->getAttributes());
        }
    }

    private function createContext(): AdminApiTestContext
    {
        return AdminApiTestContext::create(roleSlug: 'organization_owner');
    }

    private function createEstimate(Organization $organization, Project $project, array $overrides = []): Estimate
    {
        foreach ($organization->users as $user) {
            $project->users()->syncWithoutDetaching([$user->id => ['role' => 'member', 'is_active' => true]]);
        }
        return Estimate::query()->create(array_merge([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'number' => 'CONSTR-'.random_int(10000, 99999),
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
            'name' => 'Constructor item '.random_int(1000, 9999),
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
            'name' => 'Constructor section '.random_int(1000, 9999),
            'sort_order' => 1,
            'is_summary' => false,
        ], $overrides));
    }

    private function createCatalogItem(
        Organization $organization,
        \App\Models\User $user,
        MeasurementUnit $unit,
        array $overrides = []
    ): EstimatePositionCatalog {
        return EstimatePositionCatalog::query()->create(array_merge([
            'organization_id' => $organization->id,
            'name' => 'Catalog item '.random_int(1000, 9999),
            'code' => 'catalog-'.random_int(1000, 9999),
            'item_type' => EstimatePositionItemType::WORK->value,
            'measurement_unit_id' => $unit->id,
            'unit_price' => 1000,
            'direct_costs' => 1000,
            'is_active' => true,
            'usage_count' => 0,
            'created_by_user_id' => $user->id,
        ], $overrides));
    }

    private function createMeasurementUnit(Organization $organization, array $overrides = []): MeasurementUnit
    {
        return MeasurementUnit::query()->create(array_merge([
            'organization_id' => $organization->id,
            'name' => 'Constructor unit '.random_int(1000, 9999),
            'short_name' => 'cu'.random_int(1000, 9999),
            'type' => 'work',
            'is_default' => false,
            'is_system' => false,
        ], $overrides));
    }
}
