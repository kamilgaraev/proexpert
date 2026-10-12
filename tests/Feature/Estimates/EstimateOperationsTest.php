<?php

declare(strict_types=1);

namespace Tests\Feature\Estimates;

use App\BusinessModules\Features\BudgetEstimates\Services\Operations\EstimateOperationService;
use App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceExecutionQuantity;
use App\BusinessModules\Features\BudgetEstimates\Services\Operations\EstimatePriceListImporter;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Jobs\ProcessEstimateOperation;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\ContractPerformanceAct;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateOperation;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\PerformanceActLine;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class EstimateOperationsTest extends TestCase
{
    private User $actor;
    private Project $project;
    private MeasurementUnit $unit;
    private EstimateOperationService $operations;
    private bool $allowEstimates = true;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $organization = Organization::factory()->create();
        $this->project = Project::factory()->create(['organization_id' => $organization->id, 'is_archived' => false]);
        $this->actor = User::factory()->create(['current_organization_id' => $organization->id]);
        $this->actor->organizations()->attach($organization->id, ['is_active' => true, 'is_owner' => true, 'project_access_mode' => 'all_projects']);
        $this->actingAs($this->actor);
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnUsing(fn (): bool => $this->allowEstimates);
        $this->unit = MeasurementUnit::query()->firstOrCreate(['organization_id' => $organization->id, 'short_name' => 'м'], ['name' => 'Метр', 'type' => 'length']);
        $this->operations = app(EstimateOperationService::class);
    }

    public function test_adjustment_creates_only_a_new_draft_and_is_idempotent(): void
    {
        [$base, $baseItem] = $this->source('100');
        [$add] = $this->source('20');
        [$subtract] = $this->source('30');
        $before = $baseItem->fresh()->attributesToArray();
        $operation = $this->adjust($base, $add, $subtract);
        $this->preview($operation);
        self::assertSame('ready', $operation->fresh()->status);
        self::assertSame('450.00', $operation->fresh()->summary['total_amount']);
        $this->operations->apply($this->actor, $this->project->id, $operation->id, 'apply-1');
        $this->operations->process($operation->id, 1, true);
        $result = Estimate::query()->findOrFail($operation->fresh()->result_ids[0]);
        self::assertSame('draft', $result->status);
        self::assertNull($result->contract_id);
        self::assertSame('90.00000000', $result->items()->firstOrFail()->quantity_total);
        self::assertSame('450.00', $result->total_amount);
        self::assertSame($before, $baseItem->fresh()->attributesToArray());
        $this->operations->apply($this->actor, $this->project->id, $operation->id, 'apply-1');
        $this->operations->process($operation->id, 1, true);
        self::assertSame(4, Estimate::query()->count());
        Queue::assertPushed(ProcessEstimateOperation::class, 2);
        Queue::assertPushedOn('estimate-revisions', ProcessEstimateOperation::class);
    }

    public function test_additions_do_not_increase_the_excludable_original_remainder(): void
    {
        [$base, $item] = $this->source('100');
        [$add] = $this->source('20');
        [$subtract] = $this->source('30');
        $this->act($item, '80');
        $operation = $this->adjust($base, $add, $subtract);
        $this->preview($operation);
        self::assertSame('needs_resolution', $operation->fresh()->status);
        self::assertContains('execution_exceeded', $this->conflictCodes($operation));
        $this->expectException(ValidationException::class);
        $this->operations->apply($this->actor, $this->project->id, $operation->id, 'blocked');
    }

    public function test_excluding_exact_remainder_is_allowed_and_annulled_acts_are_ignored(): void
    {
        [$base, $item] = $this->source('100');
        [$add] = $this->source('20');
        [$subtract] = $this->source('20');
        $this->act($item, '80');
        $this->act($item, '50')->update(['annulled_at' => now()]);
        $operation = $this->adjust($base, $add, $subtract);
        $this->preview($operation);
        self::assertSame('ready', $operation->fresh()->status);
        self::assertSame('80.00000000', \App\Models\EstimateOperationRow::query()->where('operation_id', $operation->id)->orderBy('position')->firstOrFail()->payload['executed_quantity']);
    }

    public function test_source_change_prevents_application_and_refresh_discards_resolutions(): void
    {
        [$base, $item] = $this->source('100');
        [$add] = $this->source('20');
        [$subtract] = $this->source('30');
        $operation = $this->adjust($base, $add, $subtract);
        $this->preview($operation);
        $item->update(['quantity' => '110']);
        $this->operations->apply($this->actor, $this->project->id, $operation->id, 'stale');
        $this->operations->process($operation->id, 1, true);
        self::assertSame('stale', $operation->fresh()->status);
        self::assertSame(3, Estimate::query()->count());
        $operation = $this->operations->resolve($this->actor, $this->project->id, $operation->id, [], true);
        $this->operations->process($operation->id, 2, false);
        self::assertSame('ready', $operation->fresh()->status);
        self::assertSame([], $operation->fresh()->resolutions);
    }

    public function test_new_act_between_preview_and_apply_invalidates_preview(): void
    {
        [$base, $item] = $this->source('100');
        [$add] = $this->source('20');
        [$subtract] = $this->source('30');
        $operation = $this->adjust($base, $add, $subtract);
        $this->preview($operation);
        $this->act($item, '80');
        $this->operations->apply($this->actor, $this->project->id, $operation->id, 'act-change');
        $this->operations->process($operation->id, 1, true);
        self::assertSame('stale', $operation->fresh()->status);
        self::assertSame(3, Estimate::query()->count());
    }

    public function test_repricing_requires_confirmed_prices_and_consolidates_equal_positions(): void
    {
        [$first, $firstItem] = $this->source('100');
        [$second, $secondItem] = $this->source('20', '9');
        $operation = $this->operations->create($this->actor, $this->project->id, ['mode' => 'consolidate', 'source_ids' => [$first->id, $second->id], 'target_date' => '2026-10-12', 'region_code' => '77', 'currency' => 'RUB']);
        $this->preview($operation);
        self::assertSame('needs_resolution', $operation->fresh()->status);
        $resolutions = [];
        foreach ([$firstItem, $secondItem] as $item) {
            $resolutions['price:'.$item->estimate_id.':'.$item->id] = ['unit_price' => '10', 'source' => 'Подтверждённый прайс 2026'];
        }
        $operation = $this->operations->resolve($this->actor, $this->project->id, $operation->id, $resolutions);
        $this->operations->process($operation->id, 2, false);
        self::assertSame('ready', $operation->fresh()->status);
        self::assertSame(1, $operation->fresh()->summary['result_row_count']);
        self::assertSame('1200.00', $operation->fresh()->summary['total_amount']);
    }

    public function test_separate_repricing_produces_one_result_per_source(): void
    {
        [$first, $firstItem] = $this->source('10');
        [$second, $secondItem] = $this->source('20');
        $operation = $this->operations->create($this->actor, $this->project->id, ['mode' => 'reprice', 'source_ids' => [$first->id, $second->id], 'target_date' => '2026-10-12', 'region_code' => '77']);
        $this->preview($operation);
        $resolutions = [];
        foreach ([$firstItem, $secondItem] as $item) {
            $resolutions['price:'.$item->estimate_id.':'.$item->id] = ['unit_price' => '10', 'source' => 'Проверенная цена'];
        }
        $operation = $this->operations->resolve($this->actor, $this->project->id, $operation->id, $resolutions);
        $this->operations->process($operation->id, 2, false);
        $this->operations->apply($this->actor, $this->project->id, $operation->id, 'separate');
        $this->operations->process($operation->id, 2, true);
        self::assertCount(2, $operation->fresh()->result_ids);
    }

    public function test_equal_work_with_different_preserved_prices_stays_separate(): void
    {
        [$base] = $this->source('100');
        [$add] = $this->source('20', '9');
        [$subtract] = $this->source('10');
        $operation = $this->adjust($base, $add, $subtract);
        $this->preview($operation);
        self::assertSame('ready', $operation->fresh()->status);
        self::assertSame(2, $operation->fresh()->summary['result_row_count']);
        self::assertSame('630.00', $operation->fresh()->summary['total_amount']);
    }

    public function test_another_actor_cannot_read_the_operation(): void
    {
        [$base] = $this->source('100');
        [$add] = $this->source('20');
        [$subtract] = $this->source('30');
        $operation = $this->adjust($base, $add, $subtract);
        $other = User::factory()->create(['current_organization_id' => $this->actor->current_organization_id]);
        $other->organizations()->attach($this->actor->current_organization_id, ['is_active' => true, 'is_owner' => true, 'project_access_mode' => 'all_projects']);
        $this->expectException(AuthorizationException::class);
        $this->operations->find($other, $this->project->id, $operation->id);
    }

    public function test_foreign_project_source_is_rejected(): void
    {
        [$source] = $this->source('100');
        $source->update(['project_id' => Project::factory()->create(['organization_id' => $this->actor->current_organization_id])->id]);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->operations->create($this->actor, $this->project->id, ['mode' => 'reprice', 'source_ids' => [$source->id]]);
    }

    public function test_price_file_mapping_accepts_decimal_comma_and_rejects_formula(): void
    {
        $importer = app(EstimatePriceListImporter::class);
        $columns = ['code' => 0, 'name' => 1, 'item_type' => 2, 'unit' => 3, 'unit_price' => 4];
        $rows = [['Код', 'Название', 'Тип', 'Единица', 'Цена'], ['WORK-001', 'Труба', 'работа', 'м', '12,50']];
        self::assertSame('12.50', $importer->entries($rows, $columns, 1)[0]['unit_price']);
        $rows[1][4] = '=1+2';
        $this->expectException(ValidationException::class);
        $importer->entries($rows, $columns, 1);
    }

    public function test_normative_prices_use_base_once_with_coefficients_and_vat(): void
    {
        [$base, $item] = $this->source('2', '999');
        $type = \App\Models\NormativeBaseType::query()->create(['code' => 'OP-BASE', 'name' => 'База']);
        $collection = \App\Models\NormativeCollection::query()->create(['base_type_id' => $type->id, 'code' => 'OP-COL', 'name' => 'Сборник', 'is_active' => true]);
        $rate = \App\Models\NormativeRate::query()->create(['collection_id' => $collection->id, 'code' => 'WORK-001', 'name' => 'Труба', 'measurement_unit' => 'м', 'materials_cost' => '60', 'machinery_cost' => '10', 'labor_cost' => '30', 'base_price' => '100', 'base_price_year' => 2000]);
        $item->update(['normative_rate_id' => $rate->id, 'is_manual' => false, 'base_unit_price' => '100', 'price_index' => '9', 'coefficient_total' => '1.5']);
        $index = \App\Models\PriceIndex::query()->create(['index_type' => 'construction_general', 'region_code' => '77', 'region_name' => 'Москва', 'year' => 2026, 'month' => 10, 'index_value' => '2', 'source' => 'Индекс']);
        $operation = $this->operations->create($this->actor, $this->project->id, ['mode' => 'reprice', 'source_ids' => [$base->id], 'target_date' => '2026-10-12', 'region_code' => '77', 'normative_collection_id' => $collection->id, 'price_index_id' => $index->id, 'overhead_rate' => '15', 'profit_rate' => '12', 'vat_rate' => '20']);
        $this->preview($operation);
        self::assertSame('ready', $operation->fresh()->status);
        self::assertSame('762.00', $operation->fresh()->summary['total_amount']);
        self::assertSame('914.40', $operation->fresh()->summary['total_amount_with_vat']);
        $this->operations->resolve($this->actor, $this->project->id, $operation->id, []);
        $this->operations->process($operation->id, 2, false);
        self::assertSame('762.00', $operation->fresh()->summary['total_amount']);
        $index->update(['index_value' => '3']);
        $this->operations->apply($this->actor, $this->project->id, $operation->id, 'price-change');
        $this->operations->process($operation->id, 2, true);
        self::assertSame('stale', $operation->fresh()->status);
        $rate->update(['base_price' => '0', 'materials_cost' => '0', 'machinery_cost' => '0', 'labor_cost' => '0']);
        $this->operations->resolve($this->actor, $this->project->id, $operation->id, [], true);
        $this->operations->process($operation->id, 3, false);
        self::assertSame('needs_resolution', $operation->fresh()->status);
        self::assertContains('basis_missing', $this->conflictCodes($operation));
    }

    public function test_resource_children_are_scaled_without_double_counting(): void
    {
        [$base, $item] = $this->source('100');
        [$add] = $this->source('20', '7');
        [$subtract] = $this->source('30');
        EstimateItem::query()->create(['estimate_id' => $base->id, 'parent_work_id' => $item->id, 'position_number' => '1.1', 'name' => 'Материал', 'item_type' => 'material', 'quantity' => '200', 'quantity_total' => '200', 'quantity_coefficient' => '1', 'unit_price' => '2.5', 'total_amount' => '500']);
        $operation = $this->adjust($base, $add, $subtract);
        $this->preview($operation);
        $rows = \App\Models\EstimateOperationRow::query()->where('operation_id', $operation->id)->get();
        $resolutions = [];
        foreach ($rows as $row) {
            foreach ($row->payload['conflicts'] as $conflict) {
                if ($row->payload['role'] === 'add') {
                    $resolutions[$conflict['key']] = ['action' => 'separate'];
                } elseif ($row->payload['role'] === 'subtract') {
                    $resolutions[$conflict['key']] = ['action' => 'match', 'target_key' => $base->id.':'.$item->id];
                }
            }
        }
        $this->operations->resolve($this->actor, $this->project->id, $operation->id, $resolutions);
        $this->operations->process($operation->id, 2, false);
        self::assertSame('ready', $operation->fresh()->status);
        self::assertSame('490.00', $operation->fresh()->summary['total_amount']);
        $this->operations->apply($this->actor, $this->project->id, $operation->id, 'resources');
        $this->operations->process($operation->id, 2, true);
        $result = Estimate::query()->findOrFail($operation->fresh()->result_ids[0]);
        self::assertSame('140.00000000', $result->items()->whereNotNull('parent_work_id')->firstOrFail()->quantity);
        self::assertSame('490.00', $result->total_amount);
    }

    public function test_csv_price_import_keeps_private_file_and_normalizes_vat(): void
    {
        $files = \Mockery::mock(\App\Services\Storage\FileService::class);
        $files->shouldReceive('putPrivate')->once()->andReturnUsing(fn ($path, $stream, $mime, $checksum) => new \App\Services\Storage\DTO\CurrentStoredFile($path, 'test-etag', 100, $checksum, $mime));
        $this->app->instance(\App\Services\Storage\FileService::class, $files);
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('prices.csv', "Код;Название;Тип;Единица;Цена\nWORK-001;Труба;работа;м;12,00\n");
        $importer = app(EstimatePriceListImporter::class);
        self::assertSame(1, $importer->inspect($this->actor, $this->project->id, $file)['row_count']);
        $list = $importer->import($this->actor, $this->project->id, $file, ['name' => 'Прайс 2026', 'currency' => 'RUB', 'vat_rate' => '20', 'includes_vat' => true, 'effective_date' => '2026-10-12', 'columns' => ['code' => 0, 'name' => 1, 'item_type' => 2, 'unit' => 3, 'unit_price' => 4]]);
        self::assertStringStartsWith('org-'.$this->actor->current_organization_id.'/', $list->file_path);
        [$base] = $this->source('20');
        $operation = $this->operations->create($this->actor, $this->project->id, ['mode' => 'reprice', 'source_ids' => [$base->id], 'target_date' => '2026-10-12', 'region_code' => '77', 'currency' => 'RUB', 'vat_rate' => '20', 'price_list_ids' => [$list->id]]);
        $this->preview($operation);
        self::assertSame('ready', $operation->fresh()->status);
        self::assertSame('200.00', $operation->fresh()->summary['total_amount']);
        self::assertSame('240.00', $operation->fresh()->summary['total_amount_with_vat']);
    }

    public function test_background_failure_has_no_partial_result_and_obsolete_job_is_ignored(): void
    {
        [$base] = $this->source('100');
        [$add] = $this->source('20');
        [$subtract] = $this->source('30');
        $operation = $this->adjust($base, $add, $subtract);
        $this->preview($operation);
        $this->operations->resolve($this->actor, $this->project->id, $operation->id, [], true);
        $this->operations->process($operation->id, 1, false);
        self::assertSame('queued', $operation->fresh()->status);
        $this->operations->fail($operation->id, 1, new \RuntimeException());
        self::assertSame('queued', $operation->fresh()->status);
        $this->operations->fail($operation->id, 2, new \RuntimeException());
        self::assertSame('failed', $operation->fresh()->status);
        self::assertSame(3, Estimate::query()->count());
    }

    public function test_manual_match_uses_one_confirmed_price_instead_of_blending_prices(): void
    {
        [$first, $firstItem] = $this->source('10');
        [$second, $secondItem] = $this->source('20');
        $operation = $this->operations->create($this->actor, $this->project->id, ['mode' => 'consolidate', 'source_ids' => [$first->id, $second->id], 'target_date' => '2026-10-13', 'region_code' => '77']);
        $this->preview($operation);
        $this->operations->resolve($this->actor, $this->project->id, $operation->id, [
            'price:'.$first->id.':'.$firstItem->id => ['unit_price' => '10', 'source' => 'Прайс один'],
            'price:'.$second->id.':'.$secondItem->id => ['unit_price' => '20', 'source' => 'Прайс два'],
        ]);
        $this->operations->process($operation->id, 2, false);
        self::assertSame('needs_resolution', $operation->fresh()->status);
        $this->operations->resolve($this->actor, $this->project->id, $operation->id, ['match:'.$second->id.':'.$secondItem->id => ['action' => 'match', 'target_key' => $first->id.':'.$firstItem->id]]);
        $this->operations->process($operation->id, 3, false);
        self::assertSame('ready', $operation->fresh()->status);
        self::assertSame('300.00', $operation->fresh()->summary['total_amount']);
        self::assertSame(1, $operation->fresh()->summary['result_row_count']);
    }

    public function test_manual_unit_conversion_preserves_original_remainder_checks(): void
    {
        [$base, $item] = $this->source('100');
        [$add] = $this->source('20');
        [$subtract, $subItem] = $this->source('3');
        $unit = MeasurementUnit::query()->firstOrCreate(['organization_id' => $this->actor->current_organization_id, 'short_name' => '10м'], ['name' => 'Десять метров', 'type' => 'length']);
        $subItem->update(['measurement_unit_id' => $unit->id]);
        $operation = $this->adjust($base, $add, $subtract);
        $this->preview($operation);
        self::assertSame('needs_resolution', $operation->fresh()->status);
        $this->operations->resolve($this->actor, $this->project->id, $operation->id, ['match:'.$subtract->id.':'.$subItem->id => ['action' => 'match', 'target_key' => $base->id.':'.$item->id, 'unit_factor' => '10']]);
        $this->operations->process($operation->id, 2, false);
        self::assertSame('ready', $operation->fresh()->status);
        self::assertSame('450.00', $operation->fresh()->summary['total_amount']);
    }

    public function test_fractional_quantities_and_unit_prices_are_preserved_in_result(): void
    {
        [$source, $item] = $this->source('1.12345678');
        $operation = $this->operations->create($this->actor, $this->project->id, ['mode' => 'reprice', 'source_ids' => [$source->id], 'target_date' => '2026-10-13', 'region_code' => '77']);
        $this->preview($operation);
        $this->operations->resolve($this->actor, $this->project->id, $operation->id, ['price:'.$source->id.':'.$item->id => ['unit_price' => '10.1234', 'source' => 'Точная цена']]);
        $this->operations->process($operation->id, 2, false);
        $this->operations->apply($this->actor, $this->project->id, $operation->id, 'precision');
        $this->operations->process($operation->id, 2, true);
        $result = Estimate::query()->findOrFail($operation->fresh()->result_ids[0])->items()->firstOrFail();
        self::assertSame('1.12345678', $result->quantity_total);
        self::assertSame('10.1234', $result->current_unit_price);
    }

    public function test_native_and_legacy_act_representations_are_not_counted_twice(): void
    {
        [$source, $item] = $this->source('100');
        $act = $this->act($item, '60');
        $work = \App\Models\CompletedWork::query()->create(['organization_id' => $this->actor->current_organization_id, 'project_id' => $this->project->id,
            'contract_id' => $act->contract_id, 'estimate_item_id' => $item->id, 'user_id' => $this->actor->id, 'quantity' => '60', 'completed_quantity' => '60', 'price' => '1', 'total_amount' => '60', 'completion_date' => '2026-10-13', 'status' => 'confirmed', 'description' => 'То же выполнение']);
        $act->completedWorks()->attach($work->id, ['included_quantity' => '60', 'included_amount' => '60', 'currency' => 'RUB']);
        $quantity = app(EstimateFinanceExecutionQuantity::class)->snapshot($source);
        self::assertSame('60.00000000', $quantity[$item->id]['quantity']);
    }

    public function test_equipment_quote_is_counted_once_in_cost_breakdown(): void
    {
        [$source, $item] = $this->source('2');
        $item->update(['item_type' => 'equipment']);
        $operation = $this->operations->create($this->actor, $this->project->id, ['mode' => 'reprice', 'source_ids' => [$source->id], 'target_date' => '2026-10-13', 'region_code' => '77']);
        $this->preview($operation);
        $this->operations->resolve($this->actor, $this->project->id, $operation->id, ['price:'.$source->id.':'.$item->id => ['unit_price' => '1000', 'source' => 'Стоимость оборудования']]);
        $this->operations->process($operation->id, 2, false);
        $row = \App\Models\EstimateOperationRow::query()->where('operation_id', $operation->id)->firstOrFail()->payload;
        self::assertSame('0', $row['node']['attributes']['direct_costs']);
        self::assertSame('2000.00', $row['node']['attributes']['equipment_cost']);
        self::assertSame('2000.00', $row['total_amount']);
    }

    public function test_imported_fee_conditions_survive_repricing_without_global_overrides(): void
    {
        [$source, $item] = $this->source('10', '100');
        $item->update(['labor_cost' => '300', 'materials_cost' => '700', 'overhead_amount' => '90', 'profit_amount' => '60', 'total_amount' => '1150']);
        $source->update(['total_base_direct_costs' => '99999']);
        $operation = $this->operations->create($this->actor, $this->project->id, ['mode' => 'reprice', 'source_ids' => [$source->id], 'target_date' => '2026-10-13', 'region_code' => '77']);
        $this->preview($operation);
        $this->operations->resolve($this->actor, $this->project->id, $operation->id, ['price:'.$source->id.':'.$item->id => ['unit_price' => '200', 'source' => 'Прямые затраты по прайсу']]);
        $this->operations->process($operation->id, 2, false);
        self::assertSame('2300.00', $operation->fresh()->summary['total_amount']);
        $this->operations->apply($this->actor, $this->project->id, $operation->id, 'imported-fees');
        $this->operations->process($operation->id, 2, true);
        $result = Estimate::query()->findOrFail($operation->fresh()->result_ids[0]);
        self::assertSame('180.00', $result->total_overhead_costs);
        self::assertSame('120.00', $result->total_estimated_profit);
        self::assertNull($result->total_base_direct_costs);
        self::assertNull($result->items()->firstOrFail()->price_index);
    }

    public function test_failure_during_second_draft_rolls_back_every_created_draft(): void
    {
        [$first, $firstItem] = $this->source('10');
        [$second, $secondItem] = $this->source('20');
        $operation = $this->operations->create($this->actor, $this->project->id, ['mode' => 'reprice', 'source_ids' => [$first->id, $second->id], 'target_date' => '2026-10-13', 'region_code' => '77']);
        $this->preview($operation);
        $this->operations->resolve($this->actor, $this->project->id, $operation->id, [
            'price:'.$first->id.':'.$firstItem->id => ['unit_price' => '10', 'source' => 'Прайс'],
            'price:'.$second->id.':'.$secondItem->id => ['unit_price' => '10', 'source' => 'Прайс'],
        ]);
        $this->operations->process($operation->id, 2, false);
        $created = 0;
        EstimateItem::creating(function ($item) use (&$created, $first, $second): void {
            if (! in_array($item->estimate_id, [$first->id, $second->id], true) && ++$created === 2) {
                throw new \RuntimeException('Simulated second draft failure');
            }
        });
        $this->operations->apply($this->actor, $this->project->id, $operation->id, 'atomic');
        try {
            $this->operations->process($operation->id, 2, true);
            self::fail('Expected an atomic write failure');
        } catch (\RuntimeException $exception) {
            $this->operations->fail($operation->id, 2, $exception);
        }
        self::assertSame(2, Estimate::query()->count());
        self::assertSame('failed', $operation->fresh()->status);
        self::assertEmpty($operation->fresh()->result_ids);
    }

    public function test_xlsx_inspection_respects_mapping_and_rejects_formula_prices(): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([['Код', 'Тип', 'Название', 'Единица', 'Цена'], ['X-1', 'material', 'Материал', 'м', '=1+2']]);
        $path = tempnam(sys_get_temp_dir(), 'estimate-prices-');
        try {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);
            $file = new \Illuminate\Http\UploadedFile($path, 'prices.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
            $importer = app(EstimatePriceListImporter::class);
            self::assertSame(1, $importer->inspect($this->actor, $this->project->id, $file)['row_count']);
            $this->expectException(ValidationException::class);
            $importer->import($this->actor, $this->project->id, $file, ['name' => 'Прайс', 'currency' => 'RUB', 'vat_rate' => '0', 'includes_vat' => false, 'effective_date' => '2026-10-13', 'columns' => ['code' => 0, 'item_type' => 1, 'name' => 2, 'unit' => 3, 'unit_price' => 4]]);
        } finally {
            $spreadsheet->disconnectWorksheets();
            @unlink($path);
        }
    }

    public function test_fixed_reserves_and_other_costs_are_preserved_without_double_counting(): void
    {
        [$base, $item] = $this->source('100');
        [$add, $addItem] = $this->source('20');
        [$subtract] = $this->source('30');
        $base->update(['contingency_amount' => '15']);
        $add->update(['contingency_amount' => '10']);
        $subtract->update(['contingency_amount' => '999']);
        $item->update(['other_cost' => '50']);
        $addItem->update(['other_cost' => '10']);
        $operation = $this->adjust($base, $add, $subtract);
        $this->preview($operation);
        self::assertSame('ready', $operation->fresh()->status);
        self::assertSame('475.00', $operation->fresh()->summary['total_amount']);
        self::assertSame('25.00', $operation->fresh()->summary['contingency_amount']);
        $this->operations->apply($this->actor, $this->project->id, $operation->id, 'reserves');
        $this->operations->process($operation->id, 1, true);
        $result = Estimate::query()->findOrFail($operation->fresh()->result_ids[0]);
        self::assertSame('475.00', $result->total_amount);
        self::assertSame('45.00', $result->total_other_costs);
        self::assertSame('25.00', $result->contingency_amount);
    }

    public function test_parent_coefficient_is_applied_once_to_repriced_resource_children(): void
    {
        [$source, $item] = $this->source('10', '2');
        $item->update(['is_manual' => false, 'coefficient_total' => '1.5', 'direct_costs' => '30', 'total_amount' => '30']);
        $child = EstimateItem::query()->create(['estimate_id' => $source->id, 'parent_work_id' => $item->id, 'position_number' => '1.1', 'name' => 'Материал', 'item_type' => 'material', 'measurement_unit_id' => $this->unit->id, 'quantity' => '20', 'quantity_total' => '20', 'quantity_coefficient' => '1', 'unit_price' => '1', 'direct_costs' => '20', 'total_amount' => '20', 'is_manual' => true]);
        $operation = $this->operations->create($this->actor, $this->project->id, ['mode' => 'reprice', 'source_ids' => [$source->id], 'target_date' => '2026-10-13', 'region_code' => '77', 'overhead_rate' => '10']);
        $this->preview($operation);
        $this->operations->resolve($this->actor, $this->project->id, $operation->id, ['price:'.$source->id.':'.$child->id => ['unit_price' => '10', 'source' => 'Цена материала']]);
        $this->operations->process($operation->id, 2, false);
        self::assertSame('ready', $operation->fresh()->status);
        self::assertSame('330.00', $operation->fresh()->summary['total_amount']);
        $this->operations->apply($this->actor, $this->project->id, $operation->id, 'nested-coefficient');
        $this->operations->process($operation->id, 2, true);
        $result = Estimate::query()->findOrFail($operation->fresh()->result_ids[0]);
        self::assertSame('330.00', $result->total_amount);
        self::assertSame('300.00', $result->total_direct_costs);
        self::assertSame('200.00', $result->items()->whereNotNull('parent_work_id')->firstOrFail()->total_amount);
    }

    public function test_queued_apply_rechecks_revoked_permissions_and_creates_no_draft(): void
    {
        [$base] = $this->source('100');
        [$add] = $this->source('20');
        [$subtract] = $this->source('30');
        $operation = $this->adjust($base, $add, $subtract);
        $this->preview($operation);
        $this->operations->apply($this->actor, $this->project->id, $operation->id, 'revoked');
        $this->allowEstimates = false;
        try {
            $this->operations->process($operation->id, 1, true);
            self::fail('Revoked access must stop the queued apply');
        } catch (AuthorizationException $exception) {
            $this->operations->fail($operation->id, 1, $exception);
        }
        self::assertSame('failed', $operation->fresh()->status);
        self::assertSame(3, Estimate::query()->count());
    }

    public function test_preserved_price_indices_require_review_even_if_current_prices_match(): void
    {
        [$base, $baseItem] = $this->source('100');
        [$add, $addItem] = $this->source('20');
        $baseItem->update(['base_unit_price' => '1', 'price_index' => '5']);
        $addItem->update(['base_unit_price' => '2.5', 'price_index' => '2']);
        $operation = $this->operations->create($this->actor, $this->project->id, ['mode' => 'adjust', 'base_id' => $base->id, 'addition_ids' => [$add->id], 'reprice' => false]);
        $this->preview($operation);
        self::assertSame('needs_resolution', $operation->fresh()->status);
        self::assertContains('match_required', $this->conflictCodes($operation));
        $this->operations->resolve($this->actor, $this->project->id, $operation->id, ['match:'.$add->id.':'.$addItem->id => ['action' => 'separate']]);
        $this->operations->process($operation->id, 2, false);
        $this->operations->apply($this->actor, $this->project->id, $operation->id, 'preserved-indices');
        $this->operations->process($operation->id, 2, true);
        $result = Estimate::query()->findOrFail($operation->fresh()->result_ids[0]);
        self::assertSame(2, $result->items()->count());
        self::assertSame('150.00', $result->total_base_direct_costs);
        self::assertSame('600.00', $result->total_amount);
    }

    public function test_unit_prefix_does_not_multiply_the_execution_remainder(): void
    {
        [$base, $item] = $this->source('5');
        [$add, $addItem] = $this->source('1');
        [$subtract, $subItem] = $this->source('2');
        $unit = MeasurementUnit::query()->firstOrCreate(['organization_id' => $this->actor->current_organization_id, 'short_name' => '100 м3'], ['name' => 'Сто кубометров', 'type' => 'volume']);
        foreach ([$item, $addItem, $subItem] as $sourceItem) {
            $sourceItem->update(['quantity_total' => null, 'quantity_coefficient' => '100', 'measurement_unit_id' => $unit->id]);
        }
        $this->act($item, '4');
        $operation = $this->adjust($base, $add, $subtract);
        $this->preview($operation);
        self::assertSame('needs_resolution', $operation->fresh()->status);
        self::assertContains('execution_exceeded', $this->conflictCodes($operation));
        $subItem->update(['quantity' => '1', 'direct_costs' => '5', 'total_amount' => '5']);
        $this->operations->resolve($this->actor, $this->project->id, $operation->id, [], true);
        $this->operations->process($operation->id, 2, false);
        self::assertSame('ready', $operation->fresh()->status);
        self::assertSame('25.00', $operation->fresh()->summary['total_amount']);
        $this->operations->apply($this->actor, $this->project->id, $operation->id, 'unit-prefix');
        $this->operations->process($operation->id, 2, true);
        $result = Estimate::query()->findOrFail($operation->fresh()->result_ids[0])->items()->firstOrFail();
        self::assertSame('5.00000000', $result->quantity);
        self::assertSame('5.00000000', $result->quantity_total);
        self::assertSame('100.00000000', $result->quantity_coefficient);
        self::assertNull($item->fresh()->quantity_total);
    }

    public function test_stored_total_quantity_preserves_its_relationship_with_raw_quantity(): void
    {
        [$base, $item] = $this->source('100');
        [$add, $addItem] = $this->source('10');
        [$subtract, $subItem] = $this->source('5');
        foreach ([[$item, '200', '1000'], [$addItem, '20', '100'], [$subItem, '10', '50']] as [$sourceItem, $quantity, $amount]) {
            $sourceItem->update(['quantity_total' => $quantity, 'quantity_coefficient' => '2', 'direct_costs' => $amount, 'total_amount' => $amount]);
        }
        $this->act($item, '190');
        $operation = $this->adjust($base, $add, $subtract);
        $this->preview($operation);
        self::assertSame('ready', $operation->fresh()->status);
        self::assertSame('1050.00', $operation->fresh()->summary['total_amount']);
        $this->operations->apply($this->actor, $this->project->id, $operation->id, 'stored-total');
        $this->operations->process($operation->id, 1, true);
        $result = Estimate::query()->findOrFail($operation->fresh()->result_ids[0])->items()->firstOrFail();
        self::assertSame('105.00000000', $result->quantity);
        self::assertSame('210.00000000', $result->quantity_total);
    }

    public function test_repriced_equipment_in_manual_work_is_separate_from_direct_costs_and_fees(): void
    {
        [$source, $item] = $this->source('10', '90');
        $item->update(['direct_costs' => '1000', 'equipment_cost' => '100', 'labor_cost' => '300', 'materials_cost' => '500', 'machinery_cost' => '100', 'overhead_amount' => '90', 'profit_amount' => '60', 'total_amount' => '1150']);
        $child = EstimateItem::query()->create(['estimate_id' => $source->id, 'parent_work_id' => $item->id, 'position_number' => '1.1', 'name' => 'Оборудование', 'item_type' => 'equipment', 'measurement_unit_id' => $this->unit->id, 'quantity' => '1', 'quantity_total' => '1', 'unit_price' => '100', 'total_amount' => '100', 'is_manual' => true]);
        $before = $item->fresh()->attributesToArray();
        $operation = $this->operations->create($this->actor, $this->project->id, ['mode' => 'reprice', 'source_ids' => [$source->id], 'target_date' => '2026-10-13', 'region_code' => '77', 'vat_rate' => '20']);
        $this->preview($operation);
        $this->operations->resolve($this->actor, $this->project->id, $operation->id, [
            'price:'.$source->id.':'.$item->id => ['unit_price' => '90', 'source' => 'Прямые затраты работы'],
            'price:'.$source->id.':'.$child->id => ['unit_price' => '200', 'source' => 'Прайс оборудования'],
        ]);
        $this->operations->process($operation->id, 2, false);
        self::assertSame('ready', $operation->fresh()->status);
        self::assertSame('1250.00', $operation->fresh()->summary['total_amount']);
        self::assertSame('1500.00', $operation->fresh()->summary['total_amount_with_vat']);
        $this->operations->apply($this->actor, $this->project->id, $operation->id, 'equipment-in-work');
        $this->operations->process($operation->id, 2, true);
        $result = Estimate::query()->findOrFail($operation->fresh()->result_ids[0]);
        self::assertSame('900.00', $result->total_direct_costs);
        self::assertSame('200.00', $result->total_equipment_costs);
        self::assertSame('90.00', $result->total_overhead_costs);
        self::assertSame('60.00', $result->total_estimated_profit);
        self::assertSame($before, $item->fresh()->attributesToArray());
    }

    public function test_partial_resource_breakdown_cannot_replace_the_full_work_price(): void
    {
        [$source, $item] = $this->source('10');
        $item->update(['is_manual' => false]);
        $child = EstimateItem::query()->create(['estimate_id' => $source->id, 'parent_work_id' => $item->id, 'position_number' => '1.1', 'name' => 'Материал', 'item_type' => 'material', 'measurement_unit_id' => $this->unit->id, 'quantity' => '20', 'quantity_total' => '20', 'unit_price' => '1', 'direct_costs' => '20', 'total_amount' => '20', 'is_manual' => true]);
        $operation = $this->operations->create($this->actor, $this->project->id, ['mode' => 'reprice', 'source_ids' => [$source->id], 'target_date' => '2026-10-13', 'region_code' => '77']);
        $this->preview($operation);
        $this->operations->resolve($this->actor, $this->project->id, $operation->id, ['price:'.$source->id.':'.$child->id => ['unit_price' => '10', 'source' => 'Цена материала']]);
        $this->operations->process($operation->id, 2, false);
        self::assertSame('needs_resolution', $operation->fresh()->status);
        self::assertContains('basis_missing', $this->conflictCodes($operation));
        $this->operations->resolve($this->actor, $this->project->id, $operation->id, ['price:'.$source->id.':'.$item->id => ['unit_price' => '30', 'source' => 'Подтверждённая полная цена работы']]);
        $this->operations->process($operation->id, 3, false);
        self::assertSame('ready', $operation->fresh()->status);
        self::assertSame('300.00', $operation->fresh()->summary['total_amount']);
        $this->operations->apply($this->actor, $this->project->id, $operation->id, 'partial-resources');
        $this->operations->process($operation->id, 3, true);
        $result = Estimate::query()->findOrFail($operation->fresh()->result_ids[0]);
        self::assertSame('300.00', $result->total_amount);
        self::assertSame(2, $result->items()->count());
    }

    public function test_equipment_without_breakdown_requires_its_own_confirmed_price(): void
    {
        [$source, $item] = $this->source('10', '90');
        $item->update(['equipment_cost' => '100', 'total_amount' => '1000']);
        $operation = $this->operations->create($this->actor, $this->project->id, ['mode' => 'reprice', 'source_ids' => [$source->id], 'target_date' => '2026-10-13', 'region_code' => '77']);
        $this->preview($operation);
        $this->operations->resolve($this->actor, $this->project->id, $operation->id, ['price:'.$source->id.':'.$item->id => ['unit_price' => '100', 'source' => 'Прямые затраты работы']]);
        $this->operations->process($operation->id, 2, false);
        self::assertSame('needs_resolution', $operation->fresh()->status);
        self::assertContains('price_missing', $this->conflictCodes($operation));
        $this->operations->resolve($this->actor, $this->project->id, $operation->id, ['price:'.$source->id.':'.$item->id.':equipment' => ['unit_price' => '20', 'source' => 'Оборудование на единицу работы']]);
        $this->operations->process($operation->id, 3, false);
        self::assertSame('ready', $operation->fresh()->status);
        self::assertSame('1200.00', $operation->fresh()->summary['total_amount']);
    }

    private function source(string $quantity, string $price = '5'): array
    {
        $estimate = Estimate::query()->create(['organization_id' => $this->actor->current_organization_id, 'project_id' => $this->project->id,
            'number' => 'OP-'.uniqid(), 'name' => 'Смета', 'estimate_date' => '2024-01-01', 'vat_rate' => '0', 'overhead_rate' => '0', 'profit_rate' => '0']);
        $item = EstimateItem::query()->create(['estimate_id' => $estimate->id, 'position_number' => '1', 'name' => 'Труба', 'item_type' => 'work',
            'normative_rate_code' => 'WORK-001', 'measurement_unit_id' => $this->unit->id, 'quantity' => $quantity, 'quantity_total' => $quantity,
            'quantity_coefficient' => '1', 'price_coefficient' => '1', 'coefficient_total' => '1', 'unit_price' => $price, 'current_unit_price' => $price,
            'direct_costs' => (string) ((float) $quantity * (float) $price), 'total_amount' => (string) ((float) $quantity * (float) $price), 'is_manual' => true]);

        return [$estimate, $item];
    }

    private function adjust(Estimate $base, Estimate $add, Estimate $subtract): EstimateOperation
    {
        return $this->operations->create($this->actor, $this->project->id, ['mode' => 'adjust', 'base_id' => $base->id, 'addition_ids' => [$add->id], 'subtraction_ids' => [$subtract->id], 'reprice' => false]);
    }

    private function preview(EstimateOperation $operation): void
    {
        $this->operations->process($operation->id, $operation->generation, false);
    }

    private function conflictCodes(EstimateOperation $operation): array
    {
        return \App\Models\EstimateOperationRow::query()->where('operation_id', $operation->id)->get()->flatMap(fn ($row) => array_column($row->payload['conflicts'], 'code'))->all();
    }

    private function act(EstimateItem $item, string $quantity): ContractPerformanceAct
    {
        $party = Contractor::query()->create(['organization_id' => $this->actor->current_organization_id, 'name' => 'Исполнитель']);
        $contract = Contract::query()->create(['organization_id' => $this->actor->current_organization_id, 'project_id' => $this->project->id,
            'contractor_id' => $party->id, 'date' => '2026-10-12', 'number' => uniqid(), 'subject' => 'Работы', 'total_amount' => '100000', 'currency' => 'RUB', 'status' => 'active', 'contract_side_type' => 'general_contract', 'requires_contract_side_review' => false]);
        $act = ContractPerformanceAct::query()->create(['contract_id' => $contract->id, 'project_id' => $this->project->id,
            'act_document_number' => uniqid(), 'act_date' => '2026-10-12', 'amount' => $quantity, 'status' => 'draft', 'currency' => 'RUB']);
        PerformanceActLine::query()->create(['performance_act_id' => $act->id, 'estimate_item_id' => $item->id, 'line_type' => 'manual',
            'manual_reason' => 'Основание', 'title' => 'Работа', 'quantity' => $quantity, 'unit_price' => '1', 'amount' => $quantity, 'currency' => 'RUB']);
        $act->update(['status' => 'approved', 'is_approved' => true]);

        return $act;
    }
}
