<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationVatProjection;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateCalculationService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateItemService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateSourceVatCalculator;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateVersioningService;
use App\BusinessModules\Features\BudgetEstimates\Services\Export\EstimateExportService;
use App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceQuery;
use App\BusinessModules\Features\BudgetEstimates\Services\SourceMoneyVatBasis;
use App\BusinessModules\Features\BudgetEstimates\Services\Versioning\EstimateVersionRestoreService;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class SourceMoneyVatPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_source_vat_survives_two_standard_recalculations_and_export_including_three_kopecks(): void
    {
        $estimate = $this->estimate();
        $this->line($estimate, 'tiny', '0.03', 'included');
        $this->line($estimate, 'inclusive', '120.00', 'included');
        $this->line($estimate, 'exclusive', '100.00', 'excluded');
        $this->line($estimate, 'untaxed', '100.00', 'not_applicable');
        for ($iteration = 0; $iteration < 2; $iteration++) {
            app(EstimateCalculationService::class)->recalculateAll($estimate->fresh());
            $estimate->refresh();
            self::assertSame('300.03', $estimate->total_amount);
            self::assertSame('340.03', $estimate->total_amount_with_vat);
            $export = (new \ReflectionMethod(EstimateExportService::class, 'prepareTotalsData'))->invoke(app(EstimateExportService::class), $estimate);
            self::assertSame('340.03', (string) $export['total_amount_with_vat']);
            self::assertEquals(40, $export['vat_amount']);
            self::assertSame('0.03', $estimate->items()->where('name', 'tiny')->firstOrFail()->total_amount);
            $targets = app(EstimateFinanceQuery::class)->targets($estimate);
            self::assertSame('340.03', (string) array_reduce($targets, static fn (\Brick\Math\BigDecimal $sum, array $target) => $sum->plus($target['estimate_amount_with_vat']), \Brick\Math\BigDecimal::zero()));
        }
    }

    public function test_item_and_header_edits_cannot_replace_server_owned_money_or_retax_it(): void
    {
        $estimate = $this->estimate();
        $item = $this->line($estimate, 'tiny', '0.03', 'included');
        foreach ([['quantity' => '2'], ['metadata' => []], ['unit_price' => '0.04']] as $updates) {
            try {
                app(EstimateItemService::class)->updateItem($item->fresh(), $updates);
                self::fail('Accepted source money was overwritten.');
            } catch (\Illuminate\Validation\ValidationException $exception) {
                self::assertSame(409, $exception->status);
                self::assertSame('1.00000000', $item->fresh()->quantity);
                self::assertSame('0.0300', $item->fresh()->unit_price);
                self::assertNotEmpty($item->fresh()->metadata['generated_source_money']);
            }
        }
        foreach ([fn () => app(EstimateCalculationService::class)->applyVAT($estimate->fresh(), 10),
            fn () => app(EstimateCalculationService::class)->updateRates($estimate->fresh(), 5, null, null)] as $action) {
            try {
                $action();
                self::fail('Accepted source tax policy was overwritten.');
            } catch (\Illuminate\Validation\ValidationException $exception) {
                self::assertSame(409, $exception->status);
                self::assertSame('20.00', $estimate->fresh()->vat_rate);
                self::assertSame('0.00', $estimate->fresh()->overhead_rate);
            }
        }
        app(EstimateItemService::class)->updateItem($item->fresh(), ['name' => 'Уточнённое название']);
        self::assertSame('Уточнённое название', $item->fresh()->name);
        self::assertSame('0.03', $estimate->fresh()->total_amount_with_vat);
        app(\App\BusinessModules\Features\BudgetEstimates\Services\Calculation\EstimateCalculatorService::class)->recalculate($estimate->fresh());
        self::assertSame('0.03', $estimate->fresh()->total_amount_with_vat);
    }

    public function test_recalculation_rolls_back_when_an_accepted_source_quantity_changes(): void
    {
        $estimate = $this->estimate();
        $first = $this->line($estimate, 'first', '120.00', 'included');
        $second = $this->line($estimate, 'second', '100.00', 'excluded');
        $second->update(['quantity' => '2']);
        $this->expectException(\DomainException::class);
        try {
            app(EstimateCalculationService::class)->recalculateAll($estimate->fresh());
        } finally {
            self::assertSame('0.0000', $first->fresh()->direct_costs);
            self::assertSame('0.00', $estimate->fresh()->total_amount_with_vat);
        }
    }

    public function test_restoring_a_version_with_new_item_ids_preserves_the_accepted_source_money(): void
    {
        $estimate = $this->estimate();
        $item = $this->line($estimate, 'tiny', '0.03', 'included');
        app(EstimateCalculationService::class)->recalculateAll($estimate);
        $actor = User::factory()->create();
        $version = app(EstimateVersioningService::class)->createSnapshot($estimate->fresh(), (int) $actor->id);
        $restored = app(EstimateVersionRestoreService::class)->restoreWorkingCopy($estimate->fresh(), $version->fresh());
        $newItem = $restored->items()->firstOrFail();
        self::assertNotSame($item->id, $newItem->id);
        self::assertSame($item->metadata['generated_source_money'], $newItem->metadata['generated_source_money']);
        app(EstimateCalculationService::class)->recalculateAll($restored);
        self::assertSame('0.03', $restored->fresh()->total_amount_with_vat);
        $invalid = clone $version;
        $snapshot = $invalid->snapshot;
        $snapshot['unsectioned_items'][0]['quantity'] = '2.00000000';
        $invalid->snapshot = $snapshot;
        $this->expectException(\DomainException::class);
        try {
            app(EstimateVersionRestoreService::class)->restoreWorkingCopy($restored, $invalid);
        } finally {
            self::assertSame($newItem->id, $restored->items()->firstOrFail()->id);
            self::assertSame('0.03', $restored->fresh()->total_amount_with_vat);
        }
    }

    private function estimate(): Estimate
    {
        Queue::fake();
        config(['cache.default' => 'array']);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();

        return Estimate::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'number' => 'SOURCE-VAT-'.Str::random(8), 'name' => 'НДС по источникам', 'estimate_date' => '2026-10-12',
            'vat_rate' => '20', 'overhead_rate' => '0', 'profit_rate' => '0', 'calculation_method' => 'analog',
            'metadata' => ['is_ai_generated' => true, 'calculation_version' => 'generated-decimal:v1',
                'source_money_work_keys' => [],
                'generation_session_id' => 123, 'vat_calculation_method' => EstimateSourceVatCalculator::METHOD]]);
    }

    public function test_delete_constructor_edit_and_same_estimate_copy_cannot_change_the_confirmed_work_set(): void
    {
        $estimate = $this->estimate();
        $actor = User::factory()->create(['is_active' => true, 'current_organization_id' => $estimate->organization_id]);
        $actor->organizations()->attach($estimate->organization_id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $authorization = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $authorization->allows('can')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $authorization);
        $this->actingAs($actor);
        request()->setUserResolver(static fn () => $actor);
        self::assertTrue(app(\App\Services\Project\UserProjectAccessService::class)->canAccessProject($actor, $estimate->project, (int) $estimate->organization_id));
        $item = $this->line($estimate, 'tiny', '0.03', 'included');
        $constructor = app(\App\Services\Estimates\EstimateConstructorService::class);
        $organizationId = (int) $estimate->organization_id;
        $estimateId = (int) $estimate->id;
        $actions = [fn () => app(EstimateItemService::class)->deleteItem($item),
            fn () => $constructor->bulkUpdate($organizationId, $estimateId, ['items' => [['id' => $item->id, 'quantity' => '2']]]),
            fn () => $constructor->bulkDelete($organizationId, $estimateId, ['item_ids' => [$item->id]]),
            fn () => $constructor->copyItems($organizationId, $estimateId, ['item_ids' => [$item->id], 'target_estimate_id' => $estimateId])];
        foreach ($actions as $action) {
            try {
                $action();
                self::fail('The confirmed work set was mutated without confirmation.');
            } catch (\Illuminate\Validation\ValidationException $exception) {
                self::assertSame(409, $exception->status);
                self::assertSame(1, $estimate->items()->count());
                self::assertSame('1.00000000', $item->fresh()->quantity);
            }
        }
    }

    public function test_even_an_internal_duplicate_is_rejected_by_the_exact_work_manifest(): void
    {
        $estimate = $this->estimate();
        $item = $this->line($estimate, 'tiny', '0.03', 'included');
        $copy = $item->replicate();
        $copy->save();
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('source_money_work_set_changed');
        app(EstimateCalculationService::class)->recalculateAll($estimate->fresh());
    }

    private function line(Estimate $estimate, string $key, string $quoted, string $mode): EstimateItem
    {
        $metadata = $estimate->metadata;
        $metadata['source_money_work_keys'][] = $key;
        $estimate->update(['metadata' => $metadata]);
        $unit = MeasurementUnit::firstOrCreate(['short_name' => 'шт'], ['name' => 'Штука']);
        $money = (new EvaluationVatProjection)->amount($quoted, ['vat_mode' => $mode, 'vat_rate' => '20']);
        $identity = ['organization_id' => (int) $estimate->organization_id, 'project_id' => (int) $estimate->project_id,
            'session_id' => 123, 'work_item_key' => $key, 'measurement_unit_id' => (int) $unit->id,
            'quantity' => '1', 'stored_unit_price' => $money['net_amount'], 'currency' => 'RUB',
            'source_reference' => 'catalog:'.$key, 'source_hash' => hash('sha256', $key)];

        return EstimateItem::query()->create(['estimate_id' => $estimate->id, 'position_number' => $key, 'name' => $key,
            'item_type' => 'work', 'quantity' => '1', 'quantity_total' => '1', 'unit_price' => $money['net_amount'], 'total_amount' => $money['net_amount'],
            'current_total_amount' => $money['net_amount'], 'measurement_unit_id' => $unit->id, 'is_manual' => true,
            'metadata' => ['source_work_item_key' => $key, 'generated_source_money' => (new SourceMoneyVatBasis)->seal($identity, $money)]]);
    }
}
