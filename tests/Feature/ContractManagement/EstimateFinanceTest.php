<?php

declare(strict_types=1);

namespace Tests\Feature\ContractManagement;

use App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceExport;
use App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceService;
use App\BusinessModules\Features\BudgetEstimates\Services\Finance\FinanceDecimal;
use App\BusinessModules\Features\ContractManagement\Services\ContractEstimateService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\ContractEstimateItem;
use App\Models\Contractor;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateItemResource;
use App\Models\EstimateSection;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

final class EstimateFinanceTest extends TestCase
{
    private User $actor;

    private Estimate $estimate;

    private EstimateItem $item;

    private Contract $customer;

    private Contract $contractor;

    private EstimateFinanceService $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $org = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $org->id, 'is_archived' => false]);
        $this->actor = User::factory()->create(['current_organization_id' => $org->id]);
        $this->actor->organizations()->attach($org->id, ['is_active' => true, 'is_owner' => true, 'project_access_mode' => 'all_projects']);
        $this->actingAs($this->actor);
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturn(true);
        $this->estimate = Estimate::query()->create(['organization_id' => $org->id, 'project_id' => $project->id,
            'number' => 'FIN-1', 'name' => 'Бетонирование', 'estimate_date' => '2026-09-09']);
        $this->item = EstimateItem::query()->create(['estimate_id' => $this->estimate->id, 'position_number' => '1',
            'name' => 'Бетон', 'item_type' => 'work', 'quantity' => '100', 'quantity_total' => '100',
            'unit_price' => '10000', 'total_amount' => '1000000', 'is_manual' => true]);
        $party = Contractor::query()->create(['organization_id' => $org->id, 'name' => 'Исполнитель']);
        $base = ['organization_id' => $org->id, 'project_id' => $project->id, 'contractor_id' => $party->id,
            'date' => '2026-09-09', 'subject' => 'Работы', 'total_amount' => '1000000', 'currency' => 'RUB', 'status' => 'active',
            'requires_contract_side_review' => false];
        $this->customer = Contract::query()->create($base + ['number' => 'C-1', 'contract_side_type' => 'customer_to_general_contractor']);
        $this->contractor = Contract::query()->create($base + ['number' => 'S-1', 'contract_side_type' => 'general_contractor_to_contractor']);
        $this->finance = app(EstimateFinanceService::class);
    }

    public function test_exact_distribution_has_deterministic_rounding_and_rejects_zero_base(): void
    {
        self::assertSame(['a' => '0.34', 'b' => '0.33', 'c' => '0.33'], FinanceDecimal::allocate('1.00', ['c' => '1', 'b' => '1', 'a' => '1']));
        self::assertSame('200000.00', FinanceDecimal::subtract('1000000.00', '800000.00'));
        $this->expectException(ValidationException::class);
        FinanceDecimal::allocate('1.00', ['a' => '0']);
    }

    public function test_customer_and_split_cost_volumes_are_independent_and_replay_is_idempotent(): void
    {
        $command = $this->command([$this->line($this->customer, '100', '1000000'),
            $this->line($this->contractor, '60', '480000'), $this->line($this->contractor, '40', '320000')]);
        $saved = $this->save($command);
        self::assertFalse($saved['replayed']);
        self::assertTrue($this->save($command)['replayed']);
        $report = $this->report();
        self::assertSame('200000.00', $report['rows'][0]['margin']);
        self::assertSame('20.00', $report['rows'][0]['margin_percent']);
        self::assertSame('200000.00', $report['totals'][0]['complete_margin']);
        self::assertSame(2, ContractEstimateItem::query()->where('estimate_id', $this->estimate->id)->count());
        $command['mutation_id'] = (string) Str::uuid();
        $this->expectException(ConflictHttpException::class);
        $this->save($command);
    }

    public function test_overallocation_is_rejected_without_partial_writes(): void
    {
        $command = $this->command([$this->line($this->customer, '100', '1000000'),
            $this->line($this->contractor, '60', '480000'), $this->line($this->contractor, '41', '320000')]);
        try {
            $this->save($command);
            self::fail('Over-allocation was accepted');
        } catch (ValidationException) {
            self::assertDatabaseCount('estimate_finance_allocations', 0);
        }
    }

    public function test_resync_preserves_link_identifiers_and_agreed_prices_after_estimate_change(): void
    {
        $this->save($this->command([$this->line($this->customer, '100', '1000000'), $this->line($this->contractor, '100', '800000')]));
        $link = ContractEstimateItem::query()->where('contract_id', $this->contractor->id)->firstOrFail();
        $this->item->update(['unit_price' => '15000', 'total_amount' => '1500000']);
        app(ContractEstimateService::class)->syncItems($this->contractor, $this->estimate, [$this->item->id]);
        self::assertSame($link->id, $link->fresh()->id);
        self::assertSame('800000.00', $link->fresh()->amount);
        self::assertContains('estimate_changed', $this->report()['rows'][0]['warnings']);
        self::assertNull($this->report()['rows'][0]['margin']);
    }

    public function test_unknown_tax_and_missing_cost_never_produce_full_margin(): void
    {
        $income = $this->line($this->customer, '100', '1000000');
        $income['vat_rate'] = null;
        $this->save($this->command([$income]));
        self::assertNull($this->report()['rows'][0]['margin']);
        self::assertSame(1, $this->report()['totals'][0]['incomplete_count']);
        $net = $this->finance->report($this->actor, $this->estimate->project_id, $this->estimate->id, 'without_vat');
        self::assertContains('unknown_price_or_tax', $net['rows'][0]['warnings']);
    }

    public function test_mixed_currencies_keep_known_totals_separate(): void
    {
        $income = $this->line($this->customer, '100', '1000000');
        $own = $this->line($this->contractor, '100', '1000');
        $own['source'] = 'own';
        $own['contract_id'] = null;
        $own['currency'] = 'USD';
        $this->save($this->command([$income, $own]));
        $report = $this->report();
        self::assertNull($report['rows'][0]['margin']);
        $totals = array_column($report['totals'], null, 'currency');
        self::assertSame('1000000.00', $totals['RUB']['revenue']);
        self::assertSame('1000.00', $totals['USD']['cost']);
    }

    public function test_preview_changes_only_selected_contract_lines_and_checks_manual_total(): void
    {
        $income = $this->line($this->customer, '100', '1000000');
        $cost = $this->line($this->contractor, '100', '800000');
        $command = $this->command([$income, $cost]) + ['expected_total' => '750000.00', 'total_line_keys' => [$cost['key']]];
        $preview = $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $command);
        self::assertSame('1000000', $preview['lines'][0]['amount']);
        self::assertSame('750000.00', $preview['lines'][1]['amount']);
        $preview['lines'][1]['amount'] = '749999.99';
        $this->expectException(ValidationException::class);
        $this->save($preview);
    }

    public function test_materials_included_and_mixed_procurement_roll_up_once(): void
    {
        $resource = EstimateItemResource::query()->create(['estimate_item_id' => $this->item->id, 'resource_type' => 'material',
            'name' => 'Арматура', 'quantity_per_unit' => '1', 'total_quantity' => '100', 'unit_price' => '1000', 'total_amount' => '100000']);
        $income = $this->line($this->customer, '100', '1000000');
        $cost = $this->line($this->contractor, '100', '800000');
        $this->save($this->command([$income, $cost]));
        self::assertSame('800000.00', $this->report()['rows'][0]['cost']);
        self::assertSame(0, FinanceDecimal::compare('100', $this->report()['rows'][1]['included_quantity']));
        $purchase = $this->line($this->contractor, '40', '40000');
        $purchase['target_key'] = 'r:'.$resource->id;
        $purchase['source'] = 'own';
        $purchase['contract_id'] = null;
        $command = $this->command([$income, $cost, $purchase]);
        $command['target_keys'][] = $purchase['target_key'];
        try {
            $this->save($command);
            self::fail('Unconfirmed resource composition was accepted');
        } catch (ValidationException) {
            self::assertSame('800000.00', $this->report()['rows'][0]['cost']);
        }
        $command['confirm_resource_changes'] = true;
        $this->save($command);
        $report = $this->report();
        self::assertSame('840000.00', $report['rows'][0]['cost']);
        self::assertSame('160000.00', $report['rows'][0]['margin']);
        self::assertSame('60.00000000', $report['rows'][1]['included_quantity']);
        self::assertSame('840000.00', $report['totals'][0]['cost']);
        self::assertSame('40000.00', $report['totals'][0]['own_cost']);
        $command = $this->command([$income, $cost, $purchase]) + ['confirm_resource_changes' => false];
        $command['target_keys'][] = $purchase['target_key'];
        $this->save($command);
    }

    public function test_legacy_amounts_are_preserved_without_inventing_tax_basis(): void
    {
        $link = ContractEstimateItem::query()->create(['contract_id' => $this->contractor->id, 'estimate_id' => $this->estimate->id,
            'estimate_item_id' => $this->item->id, 'quantity' => '100', 'amount' => '960000', 'amount_without_vat' => '800000']);
        $line = $this->line($this->contractor, '100', '960000');
        $line['legacy_link_id'] = $link->id;
        $line['price_basis'] = 'unknown';
        $line['vat_rate'] = null;
        $line['composition_confirmed'] = false;
        $this->save($this->command([$line]));
        self::assertSame('960000.00', $link->fresh()->amount);
        self::assertSame('800000.00', $link->fresh()->amount_without_vat);
        $allocation = $this->report()['rows'][0]['allocations'][0];
        self::assertSame('960000.00', $allocation['legacy_amount']);
        self::assertNull($allocation['amount_with_vat']);
        self::assertSame('unknown', $allocation['price_basis']);
    }

    public function test_financial_access_is_separate_and_rejects_other_organizations(): void
    {
        $translations = \App\Helpers\PermissionTranslator::translateModulePermissions(['budget-estimates' => ['budget-estimates.finance.view', 'budget-estimates.finance.edit']]);
        self::assertSame('Просмотр договорных цен и плановой маржи сметы', $translations['budget-estimates']['budget-estimates.finance.view']);
        self::assertSame('Изменение финансовых распределений сметы', $translations['budget-estimates']['budget-estimates.finance.edit']);
        $this->mock(AuthorizationService::class)->shouldReceive('can')->withArgs(fn ($actor, $permission) => $permission === 'budget-estimates.finance.view')->andReturn(false);
        $this->app->forgetInstance(EstimateFinanceService::class);
        try {
            app(EstimateFinanceService::class)->report($this->actor, $this->estimate->project_id, $this->estimate->id);
            self::fail('Read permission was not checked');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            self::assertTrue(true);
        }
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturn(true);
        $other = User::factory()->create(['current_organization_id' => Organization::factory()->create()->id]);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(EstimateFinanceService::class)->report($other, $this->estimate->project_id, $this->estimate->id);
    }

    public function test_section_project_and_excel_use_identical_server_totals_and_safe_text(): void
    {
        $section = EstimateSection::query()->create(['estimate_id' => $this->estimate->id, 'name' => '=1+1', 'section_number' => '1']);
        $this->item->update(['estimate_section_id' => $section->id]);
        $this->save($this->command([$this->line($this->customer, '100', '1000000'), $this->line($this->contractor, '100', '800000')]));
        $report = $this->report();
        self::assertSame($report['totals'], $report['sections'][0]['totals']);
        $project = $this->finance->projectReport($this->actor, $this->estimate->project_id, 'with_vat');
        self::assertSame($report['totals'], $project['totals']);
        $book = app(EstimateFinanceExport::class)->workbook([$report], 'with_vat', $project['totals']);
        self::assertEquals(200000, $book->getSheet(0)->getCell('G2')->getValue());
        self::assertEquals(200000, $book->getSheet(1)->getCell('I2')->getValue());
        self::assertEquals(200000, $book->getSheet(3)->getCell('F2')->getValue());
        self::assertEquals(200000, $book->getSheet(0)->getCell('G3')->getValue());
        self::assertSame('Итого по проекту', $book->getSheet(0)->getCell('A3')->getValue());
        self::assertSame('s', $book->getSheet(3)->getCell('B2')->getDataType());
        self::assertSame('=1+1', $book->getSheet(3)->getCell('B2')->getValue());
        $book->disconnectWorksheets();
    }

    public function test_repricing_requires_explicit_preview_and_does_not_modify_estimate(): void
    {
        $lines = [$this->line($this->customer, '100', '1000000'), $this->line($this->contractor, '100', '800000')];
        $this->save($this->command($lines));
        $this->item->update(['total_amount' => '900000']);
        $lines[1]['adopt_estimate_price'] = true;
        $command = $this->command($lines) + ['preview_operation' => 'estimate_prices'];
        $preview = $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $command);
        self::assertSame('9000.00000000', $preview['lines'][1]['unit_price']);
        self::assertSame('800000.00', $this->report()['rows'][0]['cost']);
        $this->save($preview);
        self::assertSame('900000.00', $this->report()['rows'][0]['cost']);
        self::assertSame('900000.00', $this->item->fresh()->total_amount);
        self::assertContains('estimate_changed', $this->report()['rows'][0]['warnings']);
    }

    public function test_linked_sources_cannot_be_deleted_and_explicit_release_preserves_link_id(): void
    {
        $this->save($this->command([$this->line($this->contractor, '100', '800000')]));
        $link = ContractEstimateItem::query()->where('contract_id', $this->contractor->id)->firstOrFail();
        try {
            \Illuminate\Support\Facades\DB::transaction(fn () => $this->item->delete());
            self::fail('Linked item was deleted');
        } catch (\Illuminate\Database\QueryException $error) {
            self::assertSame('23503', $error->errorInfo[0]);
        }
        $this->save($this->command([]));
        self::assertSame($link->id, $link->fresh()->id);
        self::assertSame('0.00', $link->fresh()->amount);
        app(ContractEstimateService::class)->detachItems($this->contractor, [$this->item->id]);
        self::assertTrue($this->item->fresh()->delete());
    }

    public function test_different_vat_rates_and_own_brigade_are_compared_on_selected_basis(): void
    {
        $income = $this->line($this->customer, '100', '1200000');
        $income['vat_rate'] = '20';
        $cost = $this->line($this->contractor, '100', '880000');
        $cost['vat_rate'] = '10';
        $cost['source'] = 'own';
        $cost['contract_id'] = null;
        $this->save($this->command([$income, $cost]));
        self::assertSame('320000.00', $this->report()['rows'][0]['margin']);
        $net = $this->finance->report($this->actor, $this->estimate->project_id, $this->estimate->id, 'without_vat');
        self::assertSame('200000.00', $net['rows'][0]['margin']);
        self::assertSame('800000.00', $net['totals'][0]['own_cost']);
        self::assertSame(1, ContractEstimateItem::query()->where('estimate_id', $this->estimate->id)->count());
    }

    public function test_financial_edit_does_not_modify_recorded_work_or_act_links(): void
    {
        $work = \App\Models\CompletedWork::query()->create(['organization_id' => $this->estimate->organization_id,
            'project_id' => $this->estimate->project_id, 'estimate_item_id' => $this->item->id, 'contract_id' => $this->contractor->id,
            'user_id' => $this->actor->id, 'quantity' => '15', 'completed_quantity' => '15', 'price' => '8000',
            'total_amount' => '120000', 'completion_date' => '2026-09-09', 'status' => 'confirmed', 'description' => 'Бетонирование']);
        $act = \App\Models\ContractPerformanceAct::query()->create(['contract_id' => $this->contractor->id,
            'project_id' => $this->estimate->project_id, 'act_document_number' => 'А-1', 'act_date' => '2026-09-09',
            'amount' => '120000', 'currency' => 'RUB', 'status' => 'draft', 'created_by_user_id' => $this->actor->id]);
        $act->completedWorks()->attach($work->id, ['included_quantity' => '15', 'included_amount' => '120000', 'currency' => 'RUB']);
        $before = $work->fresh()->getAttributes();
        $beforeAct = $act->fresh()->getAttributes();
        $beforePivot = $act->completedWorks()->firstOrFail()->pivot->getAttributes();
        $this->save($this->command([$this->line($this->customer, '100', '1000000'),
            $this->line($this->contractor, '60', '480000'), $this->line($this->contractor, '40', '320000')]));
        self::assertSame($before, $work->fresh()->getAttributes());
        self::assertSame($beforePivot, $act->completedWorks()->firstOrFail()->pivot->getAttributes());
        self::assertSame($beforeAct, $act->fresh()->getAttributes());
    }

    public function test_parallel_saves_cannot_spend_the_same_volume_twice(): void
    {
        $first = $this->command([$this->line($this->contractor, '60', '480000')]);
        $second = $this->command([$this->line($this->contractor, '60', '480000')]);
        \Illuminate\Support\Facades\DB::commit();
        $worker = new \Symfony\Component\Process\Process([PHP_BINARY, base_path('tests/Support/EstimateFinanceSaveWorker.php')], base_path());
        $worker->setTimeout(40);
        $worker->setInput(json_encode(['actor' => $this->actor->id, 'project' => $this->estimate->project_id,
            'estimate' => $this->estimate->id, 'command' => $second], JSON_THROW_ON_ERROR));
        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($worker, $first): void {
                Estimate::query()->whereKey($this->estimate->id)->lockForUpdate()->firstOrFail();
                $worker->start();
                self::assertTrue($worker->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'READY')));
                $this->save($first);
            });
            $worker->wait();
            self::assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            self::assertStringContainsString('"status":409', $worker->getOutput());
            self::assertSame('60.00000000', $this->report()['rows'][0]['cost_quantity']);
        } finally {
            if ($worker->isRunning()) {
                $worker->stop();
            }
            \App\Models\EstimateFinanceAllocation::query()->where('estimate_id', $this->estimate->id)->delete();
            ContractEstimateItem::query()->where('estimate_id', $this->estimate->id)->delete();
            \Illuminate\Support\Facades\DB::table('estimate_finance_mutations')->where('estimate_id', $this->estimate->id)->delete();
            \Illuminate\Support\Facades\DB::beginTransaction();
        }
    }

    public function test_mirrored_resource_must_be_resolved_before_procurement(): void
    {
        $child = EstimateItem::query()->create(['estimate_id' => $this->estimate->id, 'parent_work_id' => $this->item->id,
            'position_number' => '1.1', 'name' => 'Арматура', 'item_type' => 'material', 'quantity' => '100', 'quantity_total' => '100', 'total_amount' => '100000']);
        $resource = EstimateItemResource::query()->create(['estimate_item_id' => $this->item->id, 'resource_type' => 'material',
            'name' => 'Арматура', 'total_quantity' => '100', 'quantity_per_unit' => '1', 'total_amount' => '100000']);
        $lines = [$this->line($this->customer, '100', '1000000'), $this->line($this->contractor, '100', '800000')];
        $this->save($this->command($lines));
        self::assertNull($this->report()['rows'][0]['margin']);
        $command = $this->command($lines) + ['resource_mappings' => [['resource_id' => $resource->id, 'item_id' => $child->id]], 'confirm_resource_changes' => true];
        $command['target_keys'][] = 'r:'.$resource->id;
        $this->save($command);
        self::assertSame('200000.00', $this->report()['rows'][0]['margin']);
        $purchase = $this->line($this->contractor, '100', '100000');
        $purchase['target_key'] = 'r:'.$resource->id;
        $invalid = $this->command([...$lines, $purchase]);
        $invalid['target_keys'][] = $purchase['target_key'];
        $this->expectException(ValidationException::class);
        $this->save($invalid);
    }

    public function test_legacy_internal_resources_are_prepared_explicitly_and_source_changes_are_visible(): void
    {
        $definition = ['name' => 'Арматура', 'type' => 'material', 'unit' => 'т', 'total_consumption' => '100',
            'consumption_per_unit' => '1', 'unit_price' => '1000', 'total_cost' => '100000'];
        $this->item->update(['resource_calculation' => [$definition]]);
        self::assertCount(1, $this->report()['rows'][0]['pending_resources']);
        $command = $this->command([]) + ['prepare_resource_items' => [$this->item->id]];
        $this->save($command);
        $resource = EstimateItemResource::query()->where('estimate_item_id', $this->item->id)->firstOrFail();
        self::assertSame('100.0000', $resource->total_quantity);
        self::assertSame('т', $this->report()['rows'][1]['unit']);
        self::assertTrue($this->save($command)['replayed']);
        self::assertSame(1, EstimateItemResource::query()->where('estimate_item_id', $this->item->id)->count());
        $definition['total_consumption'] = '120';
        $this->item->update(['resource_calculation' => [$definition]]);
        self::assertContains('estimate_changed', $this->report()['rows'][1]['warnings']);
    }

    public function test_view_permission_does_not_allow_financial_changes(): void
    {
        $this->mock(AuthorizationService::class)->shouldReceive('can')
            ->andReturnUsing(static fn ($actor, $permission): bool => $permission === 'budget-estimates.finance.view');
        $finance = app(EstimateFinanceService::class);
        self::assertFalse($finance->report($this->actor, $this->estimate->project_id, $this->estimate->id)['can_edit']);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $finance->save($this->actor, $this->estimate->project_id, $this->estimate->id, $this->command([]));
    }

    public function test_resource_currency_is_not_combined_with_an_invented_parent_currency(): void
    {
        $resource = EstimateItemResource::query()->create(['estimate_item_id' => $this->item->id, 'resource_type' => 'material',
            'name' => 'Материал', 'total_quantity' => '100', 'quantity_per_unit' => '1', 'total_amount' => '100000']);
        $cost = $this->line($this->contractor, '100', '500');
        $cost['source'] = 'own';
        $cost['contract_id'] = null;
        $cost['currency'] = 'USD';
        $cost['target_key'] = 'r:'.$resource->id;
        $command = $this->command([$cost]) + ['confirm_resource_changes' => true];
        $command['target_keys'][] = $cost['target_key'];
        $this->save($command);
        $report = $this->report();
        self::assertSame('USD', $report['rows'][0]['currency']);
        self::assertSame('500.00', $report['rows'][0]['cost']);
        self::assertNotContains('mixed_currency', $report['rows'][0]['warnings']);
        self::assertNull($report['rows'][0]['margin']);
        self::assertNull($report['rows'][1]['margin']);
        self::assertSame(['USD'], array_column($report['totals'], 'currency'));
    }

    public function test_unknown_amount_remains_unknown_on_subsequent_saves(): void
    {
        $line = $this->line($this->contractor, '100', '0');
        $line['amount'] = null;
        $line['price_basis'] = 'unknown';
        $line['vat_rate'] = null;
        $line['composition_confirmed'] = false;
        $this->save($this->command([$line]));
        $this->save($this->command([$line]));
        $allocation = $this->report()['rows'][0]['allocations'][0];
        self::assertNull($allocation['legacy_amount']);
        self::assertNull($allocation['amount_with_vat']);
        self::assertNull($allocation['amount_without_vat']);
        self::assertNull($this->report()['rows'][0]['margin']);
    }

    private function line(Contract $contract, string $quantity, string $amount): array
    {
        return ['key' => (string) Str::uuid(), 'target_key' => 'i:'.$this->item->id, 'source' => 'contract',
            'contract_id' => $contract->id, 'currency' => 'RUB', 'quantity' => $quantity, 'unit_price' => null,
            'amount' => $amount, 'vat_rate' => '0', 'price_basis' => 'with_vat', 'method' => 'total',
            'composition_confirmed' => true, 'notes' => null];
    }

    private function command(array $lines): array
    {
        return ['mutation_id' => (string) Str::uuid(), 'revision' => (int) $this->estimate->fresh()->finance_revision,
            'target_keys' => ['i:'.$this->item->id], 'lines' => $lines];
    }

    private function save(array $command): array
    {
        return $this->finance->save($this->actor, $this->estimate->project_id, $this->estimate->id, $command);
    }

    private function report(): array
    {
        return $this->finance->report($this->actor, $this->estimate->project_id, $this->estimate->id);
    }
}
