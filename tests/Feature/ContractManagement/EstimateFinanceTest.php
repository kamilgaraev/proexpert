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
use App\Models\EstimateFinanceAllocation;
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

    public function test_cash_distribution_saves_once_and_preserves_refund_limits_and_versions(): void
    {
        $this->save($this->command([$this->line($this->customer, '100', '1000000')]));
        $allocation = EstimateFinanceAllocation::query()->where('estimate_id', $this->estimate->id)->firstOrFail();
        $document = $this->cashDocument($this->customer, 'incoming');
        $payment = $this->cashTransaction($document->id, '400');
        $refund = $this->cashTransaction($document->id, '-100', ['reverses_transaction_id' => $payment->id]);
        $command = $this->cashCommand($allocation, $payment->id, '300');
        $preview = $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $command);
        self::assertSame('100.00', $preview['remaining_amount']);
        $command['source_hash'] = $preview['source_hash'];
        $saved = $this->finance->save($this->actor, $this->estimate->project_id, $this->estimate->id, $command);
        self::assertFalse($saved['replayed']);
        self::assertTrue($this->finance->save($this->actor, $this->estimate->project_id, $this->estimate->id, $command)['replayed']);
        $cash = \Illuminate\Support\Facades\DB::table('estimate_finance_cash_allocations')->where('payment_transaction_id', $payment->id)->first();
        self::assertSame('300.00', $cash->amount);
        self::assertSame(1, \Illuminate\Support\Facades\DB::table('estimate_finance_cash_versions')->count());
        $refundCommand = $this->cashCommand($allocation, $refund->id, '-100');
        $refundPreview = $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $refundCommand);
        self::assertSame('0.00', $refundPreview['remaining_amount']);
        $this->finance->save($this->actor, $this->estimate->project_id, $this->estimate->id, $refundCommand + ['source_hash' => $refundPreview['source_hash']]);
        foreach (['50', '401'] as $invalidAmount) {
            try {
                $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $this->cashCommand($allocation, $payment->id, $invalidAmount, 1));
                self::fail('Cash limit not enforced');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('lines', $exception->errors());
            }
        }
        $update = $this->cashCommand($allocation, $payment->id, '200', 1);
        $updatePreview = $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $update);
        $this->finance->save($this->actor, $this->estimate->project_id, $this->estimate->id, $update + ['source_hash' => $updatePreview['source_hash']]);
        $updated = \Illuminate\Support\Facades\DB::table('estimate_finance_cash_allocations')->where('id', $cash->id)->first();
        self::assertSame($cash->key, $updated->key);
        self::assertSame(2, $updated->version);
        self::assertSame('200.00', $updated->amount);
        self::assertSame(3, \Illuminate\Support\Facades\DB::table('estimate_finance_cash_versions')->count());
        self::assertSame('400.00', $payment->fresh()->amount);
        self::assertSame('-100.00', $refund->fresh()->amount);
        $fullCashReport = $this->finance->report($this->actor, $this->estimate->project_id, $this->estimate->id, 'with_vat', 'cash');
        $cashReport = $fullCashReport['cash'];
        self::assertSame('300.00', $cashReport['summary']['totals']['RUB']['difference']);
        self::assertSame('100.00', $cashReport['distribution']['totals']['RUB']['difference']);
        self::assertSame('100.00', $cashReport['distribution']['positions'][0]['totals']['RUB']['difference']);
        $states = array_column($cashReport['distribution']['sources'], null, 'transaction_id');
        self::assertSame('200.00', $states[$payment->id]['remaining_amount']);
        self::assertSame('0.00', $states[$refund->id]['remaining_amount']);
        self::assertSame($updatePreview['source_hash'], $states[$payment->id]['source_hash']);
        $fullCashReport['rows'][0]['name'] = '=POSITION()';
        $cashBook = app(EstimateFinanceExport::class)->workbook([$fullCashReport], 'with_vat', [], 'cash');
        self::assertEquals(300, $cashBook->getSheet(0)->getCell('E2')->getValue());
        self::assertEquals(100, $cashBook->getSheet(3)->getCell('E2')->getValue());
        self::assertEquals(200, $cashBook->getSheet(2)->getCell('L2')->getValue());
        self::assertEquals(200, $cashBook->getSheet(2)->getCell('M2')->getValue());
        self::assertSame('=POSITION()', $cashBook->getSheet(4)->getCell('B2')->getValue());
        self::assertSame('s', $cashBook->getSheet(4)->getCell('B2')->getDataType());
        self::assertEquals(200, $cashBook->getSheet(4)->getCell('G2')->getValue());
        self::assertEquals(-100, $cashBook->getSheet(4)->getCell('G3')->getValue());
        self::assertSame($allocation->key, $cashBook->getSheet(4)->getCell('I2')->getValue());
        self::assertEquals(2, $cashBook->getSheet(4)->getCell('J2')->getValue());
        $cashBook->disconnectWorksheets();
        $probe = $this->cashCommand($allocation, $payment->id, '0');
        $probe['lines'] = [];
        self::assertSame('200.00', $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $probe)['remaining_amount']);
        try {
            $this->finance->save($this->actor, $this->estimate->project_id, $this->estimate->id, $this->command([]));
            self::fail('Cash-linked conditions were deleted');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('история распределения оплат', json_encode($exception->errors(), JSON_UNESCAPED_UNICODE));
        }
        self::assertNotNull($allocation->fresh());
    }

    public function test_cash_distribution_caps_shared_payment_across_estimates_and_rejects_atomic_invalid_line(): void
    {
        $this->save($this->command([$this->line($this->customer, '100', '1000000')]));
        $allocation = EstimateFinanceAllocation::query()->where('estimate_id', $this->estimate->id)->firstOrFail();
        $document = $this->cashDocument($this->customer, 'incoming');
        $payment = $this->cashTransaction($document->id, '400');
        $command = $this->cashCommand($allocation, $payment->id, '300');
        $preview = $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $command);
        $bad = $command + ['source_hash' => $preview['source_hash']];
        $bad['lines'][] = array_replace($bad['lines'][0], ['allocation_key' => (string) Str::uuid()]);
        try {
            $this->finance->save($this->actor, $this->estimate->project_id, $this->estimate->id, $bad);
            self::fail('Invalid line was accepted');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('lines', $exception->errors());
        }
        self::assertSame(0, \Illuminate\Support\Facades\DB::table('estimate_finance_cash_allocations')->count());
        $this->finance->save($this->actor, $this->estimate->project_id, $this->estimate->id, $command + ['source_hash' => $preview['source_hash']]);
        $other = $this->estimate->replicate();
        $other->number = 'CASH-SHARED';
        $other->save();
        $item = $this->item->replicate();
        $item->estimate_id = $other->id;
        $item->save();
        $otherAllocation = $allocation->replicate();
        $otherAllocation->key = (string) Str::uuid();
        $otherAllocation->estimate_id = $other->id;
        $otherAllocation->estimate_item_id = $item->id;
        $otherAllocation->contract_estimate_item_id = null;
        $otherAllocation->save();
        $otherCommand = $this->cashCommand($otherAllocation, $payment->id, '200');
        try {
            $this->finance->preview($this->actor, $other->project_id, $other->id, $otherCommand);
            self::fail('Shared payment was overallocated');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('lines', $exception->errors());
        }
        $otherCommand['lines'][0]['amount'] = '100';
        $otherPreview = $this->finance->preview($this->actor, $other->project_id, $other->id, $otherCommand);
        $this->finance->save($this->actor, $other->project_id, $other->id, $otherCommand + ['source_hash' => $otherPreview['source_hash']]);
        $secondReport = $this->finance->report($this->actor, $other->project_id, $other->id, 'with_vat', 'cash')['cash']['distribution'];
        self::assertSame('0.00', $secondReport['sources'][0]['remaining_amount']);
        self::assertSame('400.00', $secondReport['sources'][0]['allocated_amount']);
        self::assertSame('100.00', $secondReport['sources'][0]['estimate_allocated_amount']);
        self::assertCount(1, $secondReport['allocations']);
        $projectReport = $this->finance->projectReport($this->actor, $other->project_id, 'with_vat', true, 'cash');
        $project = $projectReport['cash']['distribution'];
        self::assertSame('400.00', $project['totals']['RUB']['difference']);
        self::assertCount(2, $project['allocations']);
        self::assertCount(1, $project['sources']);
        self::assertCount(2, $project['positions']);
        $book = app(EstimateFinanceExport::class)->workbook($projectReport['estimates'], 'with_vat', [], 'cash', null, $projectReport['cash']);
        self::assertSame(2, $book->getSheet(3)->getHighestRow());
        self::assertEquals(400, $book->getSheet(3)->getCell('E2')->getValue());
        self::assertSame(3, $book->getSheet(4)->getHighestRow());
        self::assertEquals(400, $book->getSheet(2)->getCell('L2')->getValue());
        self::assertEquals(0, $book->getSheet(2)->getCell('M2')->getValue());
        $book->disconnectWorksheets();
    }

    public function test_cash_distribution_rejects_changed_source_and_requires_contract_permission(): void
    {
        $this->save($this->command([$this->line($this->customer, '100', '1000000')]));
        $allocation = EstimateFinanceAllocation::query()->where('estimate_id', $this->estimate->id)->firstOrFail();
        $payment = $this->cashTransaction($this->cashDocument($this->customer, 'incoming')->id, '400');
        $command = $this->cashCommand($allocation, $payment->id, '300');
        try {
            $this->finance->save($this->actor, $this->estimate->project_id, $this->estimate->id, $command + ['source_hash' => str_repeat('0', 64)]);
            self::fail('Changed source was accepted');
        } catch (ConflictHttpException $exception) {
            self::assertSame(409, $exception->getStatusCode());
        }
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnUsing(fn ($actor, $permission) => $permission !== 'contracts.edit');
        try {
            app(EstimateFinanceService::class)->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $command);
            self::fail('Contract edit permission was not enforced');
        } catch (\Illuminate\Auth\Access\AuthorizationException $exception) {
            self::assertSame(0, \Illuminate\Support\Facades\DB::table('estimate_finance_cash_allocations')->count());
        }
    }

    public function test_cash_ledger_marks_changed_sources_and_hides_inconsistent_organization_links(): void
    {
        $this->save($this->command([$this->line($this->customer, '100', '1000000')]));
        $allocation = EstimateFinanceAllocation::query()->where('estimate_id', $this->estimate->id)->firstOrFail();
        $payment = $this->cashTransaction($this->cashDocument($this->customer, 'incoming')->id, '400');
        $command = $this->cashCommand($allocation, $payment->id, '300');
        $preview = $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $command);
        $this->finance->save($this->actor, $this->estimate->project_id, $this->estimate->id, $command + ['source_hash' => $preview['source_hash']]);
        \Illuminate\Support\Facades\DB::table('estimate_finance_cash_allocations')->where('allocation_id', $allocation->id)->update(['amount' => '500']);
        $overallocated = $this->finance->report($this->actor, $this->estimate->project_id, $this->estimate->id, 'with_vat', 'cash')['cash'];
        self::assertSame('400.00', $overallocated['summary']['totals']['RUB']['difference']);
        self::assertNull($overallocated['distribution']['sources'][0]['remaining_amount']);
        self::assertNull($overallocated['distribution']['totals']['RUB']['difference']);
        self::assertNull($overallocated['distribution']['positions'][0]['totals']['RUB']['difference']);
        $book = app(EstimateFinanceExport::class)->workbook([['name' => 'Cash', 'contracts' => [], 'cash' => $overallocated]], 'with_vat', [], 'cash');
        self::assertSame('', $book->getSheet(3)->getCell('E2')->getValue());
        self::assertSame('', $book->getSheet(4)->getCell('G2')->getValue());
        self::assertEquals(500, $book->getSheet(4)->getCell('H2')->getValue());
        $book->disconnectWorksheets();
        $project = $this->finance->projectReport($this->actor, $this->estimate->project_id, 'with_vat', false, 'cash')['cash'];
        self::assertNull($project['distribution']['totals']['RUB']['difference']);
        \Illuminate\Support\Facades\DB::table('estimate_finance_cash_allocations')->where('allocation_id', $allocation->id)->update(['amount' => '300']);
        \App\BusinessModules\Core\Payments\Models\PaymentDocument::query()->findOrFail($payment->payment_document_id)->update(['direction' => 'outgoing']);
        $cash = $this->finance->report($this->actor, $this->estimate->project_id, $this->estimate->id, 'with_vat', 'cash')['cash']['distribution'];
        self::assertTrue($cash['allocations'][0]['source_changed']);
        self::assertNull($cash['sources'][0]['remaining_amount']);
        self::assertNull($cash['totals']['RUB']['difference']);
        $foreign = Organization::factory()->create();
        \Illuminate\Support\Facades\DB::table('estimate_finance_cash_allocations')->where('allocation_id', $allocation->id)->update(['organization_id' => $foreign->id]);
        $cash = $this->finance->report($this->actor, $this->estimate->project_id, $this->estimate->id, 'with_vat', 'cash')['cash']['distribution'];
        self::assertSame([], $cash['allocations']);
        self::assertTrue($cash['sources'][0]['requires_review']);
        self::assertNull($cash['sources'][0]['allocated_amount']);
    }

    private function cashCommand(EstimateFinanceAllocation $allocation, int $transactionId, string $amount, int $version = 0): array
    {
        return ['operation' => 'cash_distribution', 'revision' => (int) Estimate::query()->findOrFail($allocation->estimate_id)->finance_revision,
            'mutation_id' => (string) Str::uuid(), 'transaction_id' => $transactionId,
            'lines' => [['allocation_key' => $allocation->key, 'condition_version' => (int) $allocation->condition_version, 'version' => $version, 'amount' => $amount]]];
    }

    public function test_own_cost_options_paginate_active_organization_categories_and_search_literal_text(): void
    {
        $rows = [];
        for ($index = 1; $index <= 52; $index++) {
            $rows[] = ['organization_id' => $this->estimate->organization_id, 'name' => 'Категория '.$index,
                'code' => 'OWN-CAT-'.$index, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()];
        }
        \Illuminate\Support\Facades\DB::table('cost_categories')->insert($rows);
        $foreign = Organization::factory()->create();
        \App\Models\CostCategory::query()->create(['organization_id' => $foreign->id, 'name' => 'Чужая', 'code' => 'FOREIGN', 'is_active' => true]);
        \App\Models\CostCategory::query()->create(['organization_id' => $this->estimate->organization_id, 'name' => 'Неактивная', 'code' => 'INACTIVE', 'is_active' => false]);
        $command = ['preview_operation' => 'own_cost_options', 'kind' => 'categories'];
        $first = $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $command);
        self::assertCount(50, $first['data']);
        self::assertNotNull($first['next_cursor']);
        $second = $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $command + ['after' => $first['next_cursor']]);
        self::assertCount(2, $second['data']);
        self::assertNull($second['next_cursor']);
        self::assertSame([], array_values(array_intersect(array_column($first['data'], 'id'), array_column($second['data'], 'id'))));
        $special = \App\Models\CostCategory::query()->create(['organization_id' => $this->estimate->organization_id, 'name' => 'Скидка 5%', 'code' => 'PERCENT', 'is_active' => true]);
        $search = $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $command + ['query' => '%']);
        self::assertSame([$special->id], array_column($search['data'], 'id'));
    }

    public function test_legacy_finance_read_does_not_infer_terms_from_foreign_contract(): void
    {
        $foreign = $this->contractor->replicate();
        $foreign->organization_id = \App\Models\Organization::factory()->create()->id;
        $foreign->currency = 'EUR';
        $foreign->save();
        $link = ContractEstimateItem::query()->create(['contract_id' => $foreign->id, 'estimate_id' => $this->estimate->id,
            'estimate_item_id' => $this->item->id, 'quantity' => '1', 'amount' => '123.45', 'finance_managed' => false]);
        $query = app(\App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceQuery::class);
        $rows = $query->allocations($this->estimate);
        self::assertCount(1, $rows);
        self::assertSame('legacy:'.$link->id, $rows[0]['key']);
        self::assertSame('unknown', $rows[0]['side']);
        self::assertSame('', $rows[0]['currency']);
        self::assertSame('123.45', $rows[0]['legacy_amount']);
        self::assertFalse($rows[0]['composition_confirmed']);
        $foreign->organization_id = $this->estimate->organization_id;
        $foreign->project_id = null;
        $foreign->save();
        self::assertSame('unknown', $query->allocations($this->estimate)[0]['side']);
        self::assertSame('', $query->allocations($this->estimate)[0]['currency']);
        $foreign->project_id = $this->estimate->project_id;
        $foreign->save();
        self::assertSame('EUR', $query->allocations($this->estimate)[0]['currency']);
        self::assertSame('cost', $query->allocations($this->estimate)[0]['side']);
    }

    public function test_migration_plan_pages_preserved_links_without_inventing_tax_or_writing_data(): void
    {
        $link = ContractEstimateItem::query()->create(['contract_id' => $this->contractor->id, 'estimate_id' => $this->estimate->id,
            'estimate_item_id' => $this->item->id, 'quantity' => '1', 'amount' => '120', 'amount_without_vat' => '100']);
        $item = $this->item->replicate();
        $item->save();
        $next = ContractEstimateItem::query()->create(['contract_id' => $this->contractor->id, 'estimate_id' => $this->estimate->id,
            'estimate_item_id' => $item->id, 'quantity' => '2', 'amount' => '50']);
        $planner = app(\App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceMigrationPlan::class);
        $before = $link->fresh()->getAttributes();
        $page = $planner->report($this->actor, $this->estimate->project_id, $this->estimate->id, 0, 1);
        self::assertTrue($page['read_only']);
        self::assertSame($link->id, $page['next_cursor']);
        self::assertCount(1, $page['rows']);
        self::assertSame('requires_review', $page['rows'][0]['status']);
        self::assertFalse($page['rows'][0]['confirmed_margin_eligible']);
        self::assertContains('tax_terms_not_recorded', $page['rows'][0]['source']['reasons']);
        self::assertSame('120.00', $page['rows'][0]['source']['amount']);
        self::assertSame('100.00', $page['rows'][0]['source']['amount_without_vat']);
        self::assertSame($page, $planner->report($this->actor, $this->estimate->project_id, $this->estimate->id, 0, 1));
        self::assertSame($page, $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id,
            ['preview_operation' => 'migration_plan', 'after' => 0, 'limit' => 1]));
        foreach ([-1, 0, 501] as $invalidLimit) {
            try {
                $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id,
                    ['preview_operation' => 'migration_plan', 'limit' => $invalidLimit]);
                self::fail('Invalid migration page size accepted');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        self::assertSame($before, $link->fresh()->getAttributes());
        self::assertSame(0, EstimateFinanceAllocation::query()->count());
        $second = $planner->report($this->actor, $this->estimate->project_id, $this->estimate->id, $page['next_cursor'], 1);
        self::assertSame($next->id, $second['rows'][0]['legacy_link_id']);
        self::assertNull($second['next_cursor']);
        $link->update(['amount' => '130']);
        self::assertNotSame($page['rows'][0]['source_hash'], $planner->report($this->actor, $this->estimate->project_id, $this->estimate->id, 0, 1)['rows'][0]['source_hash']);
        $freshPlan = $planner->report($this->actor, $this->estimate->project_id, $this->estimate->id);
        $command = ['operation' => 'migration_apply', 'mutation_id' => (string) Str::uuid(), 'revision' => $freshPlan['revision'],
            'links' => array_map(fn ($row) => ['legacy_link_id' => $row['legacy_link_id'], 'source_hash' => $row['source_hash']], $freshPlan['rows'])];
        $invalid = $command;
        $invalid['links'][1]['source_hash'] = str_repeat('0', 64);
        try {
            $this->save($invalid);
            self::fail('Stale migration source was accepted');
        } catch (ConflictHttpException) {
            self::assertSame(0, EstimateFinanceAllocation::query()->count());
        }
        $beforeApply = $link->fresh()->getAttributes();
        $saved = $this->save($command);
        self::assertFalse($saved['replayed']);
        self::assertTrue($this->save($command)['replayed']);
        self::assertSame(2, EstimateFinanceAllocation::query()->count());
        $afterApply = $link->fresh()->getAttributes();
        unset($beforeApply['finance_managed'], $afterApply['finance_managed']);
        self::assertSame($beforeApply, $afterApply);
        $allocation = EstimateFinanceAllocation::query()->where('contract_estimate_item_id', $link->id)->firstOrFail();
        self::assertSame('130.00', $allocation->legacy_amount);
        self::assertSame('100.00', $allocation->amount_without_vat);
        self::assertNull($allocation->amount_with_vat);
        self::assertFalse($allocation->composition_confirmed);
        self::assertSame('unknown', $allocation->price_basis);
        self::assertSame([], $planner->report($this->actor, $this->estimate->project_id, $this->estimate->id)['rows']);
        self::assertSame(2, \Illuminate\Support\Facades\DB::table('estimate_finance_condition_versions')->count());
        $audit = $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id,
            ['preview_operation' => 'migration_plan', 'include_managed' => true]);
        self::assertCount(2, $audit['rows']);
        self::assertSame('already_managed', $audit['rows'][0]['status']);
        self::assertFalse($audit['rows'][0]['can_preserve_as_unreviewed']);
        self::assertNull($audit['rows'][0]['confirmed_margin_eligible']);
        self::assertStringContainsString('повторный перенос не требуется', $audit['rows'][0]['reason_messages'][0]);
    }

    public function test_own_cost_distribution_caps_all_estimates_and_keeps_exact_net_and_history(): void
    {
        $parent = EstimateSection::query()->create(['estimate_id' => $this->estimate->id, 'name' => 'Общий раздел', 'section_number' => '1']);
        $child = EstimateSection::query()->create(['estimate_id' => $this->estimate->id, 'name' => 'Вложенный раздел', 'section_number' => '1', 'parent_section_id' => $parent->id]);
        $this->item->update(['estimate_section_id' => $child->id]);
        $db = \Illuminate\Support\Facades\DB::class;
        $a = array_replace($this->line($this->contractor, '50', '100'), ['source' => 'own', 'contract_id' => null]);
        $b = array_replace($a, ['key' => (string) Str::uuid()]);
        $this->save($this->command([$a, $b]));
        $second = $this->estimate->replicate();
        $second->number = 'OWN-2';
        $second->save();
        $secondItem = $this->item->replicate();
        $secondItem->estimate_id = $second->id;
        $secondItem->estimate_section_id = null;
        $secondItem->save();
        $c = array_replace($a, ['key' => (string) Str::uuid(), 'quantity' => '100', 'target_key' => 'i:'.$secondItem->id]);
        $this->finance->save($this->actor, $second->project_id, $second->id, ['mutation_id' => (string) Str::uuid(),
            'revision' => (int) $second->fresh()->finance_revision, 'target_keys' => [$c['target_key']], 'lines' => [$c]]);
        $category = \App\Models\CostCategory::query()->create(['organization_id' => $this->estimate->organization_id,
            'name' => 'Собственные расходы', 'code' => 'OWN-SPLIT', 'is_active' => true]);
        $registration = ['operation' => 'own_cost', 'revision' => (int) $this->estimate->fresh()->finance_revision,
            'mutation_id' => (string) Str::uuid(), 'cost_key' => (string) Str::uuid(), 'confirmed' => true,
            'source_type' => 'manual', 'cost_category_id' => $category->id, 'expense_date' => '2026-09-13',
            'basis' => 'Проверка округления', 'currency' => 'RUB', 'amount' => '0.02',
            'vat_mode' => 'exclusive', 'price_basis' => 'without_vat', 'vat_rate' => '50'];
        $registration['source_hash'] = $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $registration)['source_hash'];
        $this->save($registration);
        $cost = $db::table('estimate_finance_own_costs')->where('key', $registration['cost_key'])->first();
        $command = fn (Estimate $estimate, array $line, string $amount, int $version = 0): array => [
            'operation' => 'own_cost_distribution', 'revision' => (int) $estimate->fresh()->finance_revision,
            'mutation_id' => (string) Str::uuid(), 'cost_key' => $cost->key, 'source_version' => 1, 'source_hash' => $cost->source_hash,
            'lines' => [['allocation_key' => $line['key'], 'condition_version' => 1, 'version' => $version, 'amount' => $amount]],
        ];
        $firstCommand = $command($this->estimate, $a, '0.01');
        $preview = $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $firstCommand);
        self::assertSame('0.02', $preview['remaining_amount']);
        self::assertSame('0.01', $preview['lines'][0]['amount_without_vat']);
        $this->save($firstCommand);
        self::assertTrue($this->save($firstCommand)['replayed']);
        $firstRow = $db::table('estimate_finance_own_cost_allocations')->where('own_cost_id', $cost->id)->first();
        $this->finance->save($this->actor, $second->project_id, $second->id, $command($second, $c, '0.01'));
        $last = $command($this->estimate, $b, '0.01');
        $lastPreview = $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $last);
        self::assertSame('0.00', $lastPreview['remaining_amount']);
        self::assertSame('0.00', $lastPreview['lines'][0]['amount_without_vat']);
        $this->save($last);
        $rows = $db::table('estimate_finance_own_cost_allocations')->where('own_cost_id', $cost->id)->get();
        $gross = $net = '0.00';
        foreach ($rows as $row) {
            $gross = FinanceDecimal::add($gross, $row->amount);
            $net = FinanceDecimal::add($net, $row->amount_without_vat);
        }
        self::assertSame('0.03', $gross);
        self::assertSame('0.02', $net);
        $costReport = $this->finance->report($this->actor, $this->estimate->project_id, $this->estimate->id, 'without_vat', 'execution')['own_costs'];
        self::assertSame('0.02', $costReport['source_totals']['RUB']['amount']);
        self::assertSame('0.01', $costReport['allocated_totals']['RUB']['amount']);
        self::assertCount(2, $costReport['rows']);
        $sections = array_column($costReport['sections'], null, 'section_id');
        self::assertCount(2, $sections);
        self::assertSame('0.01', $sections[$child->id]['totals']['RUB']['amount']);
        self::assertSame('0.01', $sections[$parent->id]['totals']['RUB']['amount']);
        self::assertFalse($sections[$child->id]['hierarchy_requires_review']);
        self::assertSame('0.00', $costReport['sources'][0]['remaining_amount']);
        $projectCostReport = $this->finance->projectReport($this->actor, $this->estimate->project_id, 'without_vat', false, 'execution')['own_costs'];
        self::assertSame('0.02', $projectCostReport['source_totals']['RUB']['amount']);
        self::assertSame('0.02', $projectCostReport['allocated_totals']['RUB']['amount']);
        self::assertCount(1, $projectCostReport['sources']);
        self::assertCount(3, $projectCostReport['rows']);
        $exportReport = $this->finance->projectReport($this->actor, $this->estimate->project_id, 'without_vat', true, 'execution');
        $book = app(EstimateFinanceExport::class)->workbook($exportReport['estimates'], 'without_vat', [], 'execution', $exportReport['execution'], null, $exportReport['own_costs']);
        try {
            $summarySheet = $book->getSheetByName('Собственные расходы');
            $sourceSheet = $book->getSheetByName('Основания расходов');
            $lineSheet = $book->getSheetByName('Расходы позиций');
            self::assertSame(2, $sourceSheet->getHighestRow());
            self::assertSame(4, $lineSheet->getHighestRow());
            self::assertEquals('0.02', $summarySheet->getCell('D2')->getValue());
            self::assertEquals('0.02', $sourceSheet->getCell('H2')->getValue());
            self::assertEquals('0.03', $sourceSheet->getCell('I2')->getValue());
            self::assertEquals('0.00', $sourceSheet->getCell('L2')->getValue());
            self::assertEquals(1, $lineSheet->getCell('J2')->getValue());
            $sectionSheet = $book->getSheetByName('Расходы разделов');
            self::assertSame(4, $sectionSheet->getHighestRow());
            self::assertSame('Вложенный раздел', $sectionSheet->getCell('B2')->getValue());
            self::assertEquals('0.01', $sectionSheet->getCell('D2')->getValue());
            self::assertEquals('0.01', $sectionSheet->getCell('D3')->getValue());
        } finally {
            $book->disconnectWorksheets();
        }
        self::assertSame(3, $db::table('estimate_finance_own_cost_allocation_versions')->count());
        self::assertEquals($firstRow, $db::table('estimate_finance_own_cost_allocations')->where('id', $firstRow->id)->first());
        try {
            $this->save($command($this->estimate, $a, '0.02', 1));
            self::fail('Shared expense was overallocated');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
        try {
            $this->save($command($this->estimate, $a, '0.01', 0));
            self::fail('Stale allocation version was accepted');
        } catch (ConflictHttpException) {
            self::assertTrue(true);
        }
        try {
            $this->save($this->command([]));
            self::fail('Own cost history was disconnected');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
        $this->save($command($this->estimate, $a, '0.00', 1));
        self::assertSame($firstRow->key, $db::table('estimate_finance_own_cost_allocations')->where('id', $firstRow->id)->value('key'));
        self::assertSame(2, $db::table('estimate_finance_own_cost_allocations')->where('id', $firstRow->id)->value('version'));
        self::assertSame(4, $db::table('estimate_finance_own_cost_allocation_versions')->count());
        $remainingReport = $this->finance->report($this->actor, $this->estimate->project_id, $this->estimate->id, 'without_vat', 'execution')['own_costs'];
        self::assertSame('0.01', $remainingReport['sources'][0]['remaining_amount']);
        self::assertSame('0.01', $remainingReport['sources'][0]['remaining_without_vat']);
    }

    public function test_migration_preserves_signed_act_lines_and_payment_history(): void
    {
        $link = ContractEstimateItem::query()->create(['contract_id' => $this->contractor->id, 'estimate_id' => $this->estimate->id,
            'estimate_item_id' => $this->item->id, 'quantity' => '1', 'amount' => '120', 'amount_without_vat' => '100']);
        $act = \App\Models\ContractPerformanceAct::query()->create(['contract_id' => $this->contractor->id,
            'project_id' => $this->estimate->project_id, 'act_document_number' => 'MIGRATED-FACT', 'act_date' => '2026-09-12',
            'amount' => '150', 'status' => 'signed', 'is_approved' => true, 'currency' => 'RUB']);
        $line = \App\Models\PerformanceActLine::query()->create(['performance_act_id' => $act->id,
            'line_type' => 'manual', 'manual_reason' => 'Старая привязка', 'title' => 'Работа', 'unit' => 'шт',
            'quantity' => '1', 'unit_price' => '120', 'amount' => '120', 'currency' => 'RUB',
            'estimate_item_id' => $this->item->id, 'basis_snapshot' => ['legacy_link_id' => $link->id]]);
        $work = \App\Models\CompletedWork::query()->create(['organization_id' => $this->estimate->organization_id,
            'project_id' => $this->estimate->project_id, 'contract_id' => $this->contractor->id,
            'estimate_item_id' => $this->item->id, 'user_id' => $this->actor->id, 'quantity' => '1',
            'completed_quantity' => '1', 'price' => '120', 'total_amount' => '120',
            'completion_date' => '2026-09-12', 'status' => 'confirmed', 'description' => 'Старое выполнение']);
        $legacyAct = \App\Models\ContractPerformanceAct::query()->create(['contract_id' => $this->contractor->id,
            'project_id' => $this->estimate->project_id, 'act_document_number' => 'MIGRATION-LEGACY-WORK',
            'act_date' => '2026-09-12', 'amount' => '60', 'status' => 'draft', 'currency' => 'RUB']);
        $legacyAct->completedWorks()->attach($work->id, ['included_quantity' => '0.5', 'included_amount' => '60', 'currency' => 'RUB']);
        $legacyAct->update(['status' => 'approved', 'is_approved' => true]);
        $legacyBefore = [$work->fresh()->getAttributes(), $legacyAct->fresh()->getAttributes(),
            $legacyAct->completedWorks()->firstOrFail()->pivot->getAttributes()];
        $document = $this->cashDocument($this->contractor, 'outgoing');
        $transaction = $this->cashTransaction($document->id, '50');
        $before = [$act->fresh()->getAttributes(), $line->fresh()->getAttributes(), $document->fresh()->getAttributes(), $transaction->fresh()->getAttributes()];
        $coverageService = app(\App\BusinessModules\Features\BudgetEstimates\Services\Integration\EstimateCoverageService::class);
        $coverageBefore = $coverageService->getCoverageForEstimate($this->estimate);
        $execution = $this->finance->report($this->actor, $this->estimate->project_id, $this->estimate->id, 'with_vat', 'execution')['execution'];
        $plan = $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, ['preview_operation' => 'migration_plan']);
        $command = ['operation' => 'migration_apply', 'mutation_id' => (string) Str::uuid(), 'revision' => $plan['revision'],
            'links' => [['legacy_link_id' => $link->id, 'source_hash' => $plan['rows'][0]['source_hash']]]];
        $this->save($command);
        self::assertSame($before, [$act->fresh()->getAttributes(), $line->fresh()->getAttributes(), $document->fresh()->getAttributes(), $transaction->fresh()->getAttributes()]);
        $after = $this->finance->report($this->actor, $this->estimate->project_id, $this->estimate->id, 'with_vat', 'execution')['execution'];
        self::assertSame($legacyBefore, [$work->fresh()->getAttributes(), $legacyAct->fresh()->getAttributes(),
            $legacyAct->completedWorks()->firstOrFail()->pivot->getAttributes()]);
        self::assertContains('act_work', array_column($after['rows'], 'source_type'));
        self::assertSame($execution['rows'], $after['rows']);
        self::assertSame($execution['documents'], $after['documents']);
        self::assertSame($execution['summary']['totals'], $after['summary']['totals']);
        $coverageAfter = $coverageService->getCoverageForEstimate($this->estimate);
        self::assertSame($coverageBefore['contracts'][0]['linked_items_count'], $coverageAfter['contracts'][0]['linked_items_count']);
        self::assertNull($coverageAfter['contracts'][0]['linked_amount']);
        $preserved = EstimateFinanceAllocation::query()->where('contract_estimate_item_id', $link->id)->firstOrFail();
        self::assertEquals($coverageBefore['contracts'][0]['linked_amount'], $preserved->legacy_amount);
        self::assertSame('120.00', $link->fresh()->amount);
        self::assertTrue($this->save($command)['replayed']);
        self::assertSame(1, EstimateFinanceAllocation::query()->where('contract_estimate_item_id', $link->id)->count());
    }

    public function test_migration_requires_contract_edit_and_does_not_resurrect_retired_conditions(): void
    {
        $link = ContractEstimateItem::query()->create(['contract_id' => $this->contractor->id, 'estimate_id' => $this->estimate->id,
            'estimate_item_id' => $this->item->id, 'quantity' => '1', 'amount' => '120']);
        $plan = $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, ['preview_operation' => 'migration_plan']);
        $command = ['operation' => 'migration_apply', 'mutation_id' => (string) Str::uuid(), 'revision' => $plan['revision'],
            'links' => [['legacy_link_id' => $link->id, 'source_hash' => $plan['rows'][0]['source_hash']]]];
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnUsing(fn ($actor, $permission) => $permission !== 'contracts.edit');
        try {
            app(EstimateFinanceService::class)->save($this->actor, $this->estimate->project_id, $this->estimate->id, $command);
            self::fail('Migration bypassed contract edit permission');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            self::assertSame(0, EstimateFinanceAllocation::query()->count());
            self::assertFalse((bool) $link->fresh()->finance_managed);
        }
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnTrue();
        $key = \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL, 'most:estimate-finance:legacy:'.$link->id)->toString();
        \Illuminate\Support\Facades\DB::table('estimate_finance_condition_versions')->insert([
            'organization_id' => $this->estimate->organization_id, 'estimate_id' => $this->estimate->id, 'allocation_key' => $key,
            'condition_version' => 2, 'finance_revision' => $plan['revision'], 'mutation_id' => (string) Str::uuid(),
            'action' => 'deleted', 'before' => json_encode(['key' => $key]), 'after' => null, 'actor_id' => $this->actor->id, 'created_at' => now(),
        ]);
        try {
            app(EstimateFinanceService::class)->save($this->actor, $this->estimate->project_id, $this->estimate->id, $command);
            self::fail('Migration resurrected retired conditions');
        } catch (ConflictHttpException) {
            self::assertSame(0, EstimateFinanceAllocation::query()->count());
            self::assertFalse((bool) $link->fresh()->finance_managed);
            self::assertSame(0, \Illuminate\Support\Facades\DB::table('estimate_finance_mutations')->count());
        }
    }

    public function test_own_cost_registration_previews_tax_and_replays_without_duplicate_expense(): void
    {
        $category = \App\Models\CostCategory::query()->create(['organization_id' => $this->estimate->organization_id,
            'name' => 'Собственные расходы', 'code' => 'OWN-REGISTER', 'is_active' => true]);
        $command = ['operation' => 'own_cost', 'revision' => (int) $this->estimate->fresh()->finance_revision, 'mutation_id' => (string) Str::uuid(), 'cost_key' => (string) Str::uuid(),
            'confirmed' => true, 'source_type' => 'manual', 'cost_category_id' => $category->id, 'expense_date' => '2026-09-13',
            'basis' => 'Подтверждённая покупка', 'currency' => 'RUB', 'amount' => '100',
            'vat_mode' => 'exclusive', 'price_basis' => 'without_vat', 'vat_rate' => '20'];
        $preview = $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $command);
        self::assertSame('120.00', $preview['cost']['amount']);
        self::assertSame('100.00', $preview['cost']['amount_without_vat']);
        self::assertSame(0, \Illuminate\Support\Facades\DB::table('estimate_finance_own_costs')->count());
        $command['source_hash'] = $preview['source_hash'];
        $result = $this->save($command);
        self::assertSame($command['revision'] + 1, $result['revision']);
        self::assertFalse($result['replayed']);
        self::assertTrue($this->save($command)['replayed']);
        self::assertSame(1, \Illuminate\Support\Facades\DB::table('estimate_finance_own_costs')->count());
        self::assertSame(1, \Illuminate\Support\Facades\DB::table('estimate_finance_own_cost_versions')->count());
        self::assertSame(0, \Illuminate\Support\Facades\DB::table('estimate_finance_cash_allocations')->count());
        try {
            $this->save(array_replace($command, ['amount' => '200']));
            self::fail('Changed replay payload was accepted');
        } catch (ConflictHttpException) {
            self::assertTrue(true);
        }
        $unknown = array_replace($command, ['revision' => $result['revision'], 'mutation_id' => (string) Str::uuid(), 'cost_key' => (string) Str::uuid(),
            'vat_mode' => 'unknown', 'price_basis' => 'unknown', 'vat_rate' => null]);
        unset($unknown['source_hash']);
        $unknownPreview = $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $unknown);
        self::assertNull($unknownPreview['cost']['amount_without_vat']);
        foreach ([['confirmed' => false], ['currency' => ''], ['cost_category_id' => 0], ['amount' => '0'], ['basis' => '   ']] as $invalid) {
            try {
                $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, array_replace($unknown, $invalid));
                self::fail('Invalid expense was accepted');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        $unknown['basis'] = '=SUM(1,2)';
        $unknown['source_hash'] = $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $unknown)['source_hash'];
        $this->save($unknown);
        $report = $this->finance->report($this->actor, $this->estimate->project_id, $this->estimate->id, 'without_vat', 'execution');
        $book = app(EstimateFinanceExport::class)->workbook([$report], 'without_vat', [], 'execution');
        try {
            $sheet = $book->getSheetByName('Основания расходов');
            self::assertSame('=SUM(1,2)', $sheet->getCell('C3')->getValue());
            self::assertSame(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING, $sheet->getCell('C3')->getDataType());
            self::assertSame('', $sheet->getCell('H3')->getValue());
            self::assertSame('', $sheet->getCell('J3')->getValue());
            self::assertEquals('100.00', $sheet->getCell('I3')->getValue());
            $summary = $book->getSheetByName('Собственные расходы');
            self::assertSame('', $summary->getCell('D2')->getValue());
            self::assertEquals('100.00', $summary->getCell('E2')->getValue());
            self::assertEquals(1, $summary->getCell('F2')->getValue());
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function test_own_cost_document_registration_rejects_changed_pending_and_duplicate_source(): void
    {
        $category = \App\Models\CostCategory::query()->create(['organization_id' => $this->estimate->organization_id,
            'name' => 'Собственные расходы', 'code' => 'OWN-DOC', 'is_active' => true]);
        $source = \App\Models\AdvanceAccountTransaction::query()->create(['organization_id' => $this->estimate->organization_id,
            'project_id' => $this->estimate->project_id, 'user_id' => $this->actor->id, 'type' => 'expense',
            'amount' => '120', 'balance_after' => '0', 'reporting_status' => 'pending',
            'created_by_user_id' => $this->actor->id]);
        $command = ['operation' => 'own_cost', 'revision' => (int) $this->estimate->fresh()->finance_revision, 'mutation_id' => (string) Str::uuid(), 'cost_key' => (string) Str::uuid(),
            'confirmed' => true, 'source_type' => 'advance_expense', 'advance_transaction_id' => $source->id,
            'cost_category_id' => $category->id, 'expense_date' => '2026-09-13', 'basis' => 'Расход по документу',
            'currency' => 'RUB', 'amount' => '120', 'vat_mode' => 'none', 'price_basis' => 'without_vat'];
        try {
            $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $command);
            self::fail('Pending source was accepted');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
        $source->update(['reporting_status' => 'approved', 'approved_at' => now(), 'approved_by_user_id' => $this->actor->id, 'cost_category_id' => $category->id]);
        $foreignOrg = Organization::factory()->create();
        $otherProject = Project::factory()->create(['organization_id' => $this->estimate->organization_id]);
        foreach ([['organization_id' => $foreignOrg->id], ['project_id' => $otherProject->id], ['type' => 'issue']] as $invalidSource) {
            $other = $source->replicate();
            $other->fill($invalidSource)->save();
            try {
                $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id,
                    array_replace($command, ['advance_transaction_id' => $other->id]));
                self::fail('Foreign or non-expense source was accepted');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        $preview = $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $command);
        $command['source_hash'] = $preview['source_hash'];
        $source->update(['document_number' => 'Изменён после предпросмотра']);
        try {
            $this->save($command);
            self::fail('Stale source snapshot was accepted');
        } catch (ConflictHttpException) {
            self::assertTrue(true);
        }
        unset($command['source_hash']);
        $command['source_hash'] = $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $command)['source_hash'];
        $optionsCommand = ['preview_operation' => 'own_cost_options', 'kind' => 'documents'];
        $category->update(['is_active' => false]);
        $options = $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $optionsCommand);
        self::assertSame([$source->id], array_column($options['data'], 'id'));
        self::assertNull($options['data'][0]['currency']);
        self::assertSame($category->name, $options['data'][0]['category_name']);
        self::assertFalse($options['data'][0]['category_active']);
        $this->save($command);
        self::assertSame([], $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $optionsCommand)['data']);
        $second = array_replace($command, ['revision' => (int) $this->estimate->fresh()->finance_revision, 'mutation_id' => (string) Str::uuid(), 'cost_key' => (string) Str::uuid()]);
        try {
            $this->save($second);
            self::fail('Source document was counted twice');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('cost', $exception->errors());
        }
        self::assertSame(1, \Illuminate\Support\Facades\DB::table('estimate_finance_own_costs')->count());
        self::assertSame('120.00', $source->fresh()->amount);
        $ownLine = array_replace($this->line($this->contractor, '100', '120'), ['source' => 'own', 'contract_id' => null]);
        $this->save($this->command([$ownLine]));
        $savedCost = \Illuminate\Support\Facades\DB::table('estimate_finance_own_costs')->where('key', $command['cost_key'])->first();
        $this->save(['operation' => 'own_cost_distribution', 'revision' => (int) $this->estimate->fresh()->finance_revision,
            'mutation_id' => (string) Str::uuid(), 'cost_key' => $savedCost->key, 'source_version' => 1, 'source_hash' => $savedCost->source_hash,
            'lines' => [['allocation_key' => $ownLine['key'], 'condition_version' => 1, 'version' => 0, 'amount' => '100']]]);
        $costReport = $this->finance->report($this->actor, $this->estimate->project_id, $this->estimate->id, 'without_vat', 'execution')['own_costs'];
        self::assertSame('120.00', $costReport['source_totals']['RUB']['amount']);
        self::assertSame('100.00', $costReport['allocated_totals']['RUB']['amount']);
        $source->update(['description' => 'Новое описание после регистрации']);
        $changedReport = $this->finance->report($this->actor, $this->estimate->project_id, $this->estimate->id, 'without_vat', 'execution')['own_costs'];
        self::assertTrue($changedReport['sources'][0]['requires_review']);
        self::assertNull($changedReport['source_totals']['RUB']['amount']);
        self::assertNull($changedReport['allocated_totals']['RUB']['amount']);
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnUsing(
            fn ($actor, $permission, $context) => $permission !== 'advance_transactions.view',
        );
        \Illuminate\Support\Facades\DB::enableQueryLog();
        try {
            app(EstimateFinanceService::class)->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $second);
            self::fail('Source document was accessible without permission');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            self::assertTrue(true);
        }
        $deniedReport = app(EstimateFinanceService::class)->report($this->actor, $this->estimate->project_id, $this->estimate->id, 'without_vat', 'execution')['own_costs'];
        self::assertFalse($deniedReport['sources'][0]['available']);
        self::assertArrayNotHasKey('basis', $deniedReport['sources'][0]);
        self::assertNull($deniedReport['source_totals']['']['amount']);
        self::assertNull($deniedReport['allocated_totals']['']['amount']);
        $deniedOptions = app(EstimateFinanceService::class)->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $optionsCommand);
        self::assertFalse($deniedOptions['available']);
        self::assertSame([], $deniedOptions['data']);
        $queries = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();
        self::assertSame([], array_values(array_filter($queries, fn ($query) => str_contains($query['query'], 'advance_account_transactions'))));
        $book = app(EstimateFinanceExport::class)->workbook([['name' => 'Смета', 'own_costs' => $deniedReport]], 'without_vat', [], 'execution');
        try {
            $sheet = $book->getSheetByName('Основания расходов');
            self::assertSame('Данные расходов недоступны', $sheet->getCell('N2')->getValue());
            foreach (['B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M'] as $column) {
                self::assertSame('', $sheet->getCell($column.'2')->getValue());
            }
            self::assertSame(1, $book->getSheetByName('Расходы позиций')->getHighestRow());
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function test_own_cost_storage_preserves_sources_history_and_unknown_tax(): void
    {
        $db = \Illuminate\Support\Facades\DB::class;
        $line = array_replace($this->line($this->contractor, '100', '1000'), ['source' => 'own', 'contract_id' => null]);
        $this->save($this->command([$line]));
        $allocation = EstimateFinanceAllocation::query()->where('key', $line['key'])->firstOrFail();
        $category = \App\Models\CostCategory::query()->create(['organization_id' => $this->estimate->organization_id,
            'name' => 'Собственные расходы', 'code' => 'OWN-FIN', 'is_active' => true]);
        $row = ['key' => (string) Str::uuid(), 'organization_id' => $this->estimate->organization_id,
            'project_id' => $this->estimate->project_id, 'source_type' => 'manual', 'advance_transaction_id' => null,
            'cost_category_id' => $category->id, 'expense_date' => '2026-09-13', 'basis' => 'Подтверждённый расход',
            'currency' => 'RUB', 'amount' => '120.00', 'amount_without_vat' => null, 'vat_mode' => 'unknown', 'vat_rate' => null,
            'status' => 'confirmed', 'version' => 1, 'source_hash' => hash('sha256', 'manual-confirmation'),
            'source_snapshot' => json_encode(['basis' => 'Подтверждённый расход']), 'confirmed_by' => $this->actor->id,
            'confirmed_at' => now(), 'updated_by' => $this->actor->id, 'created_at' => now(), 'updated_at' => now()];
        $id = $db::table('estimate_finance_own_costs')->insertGetId($row);
        $db::table('estimate_finance_own_cost_versions')->insert(['own_cost_id' => $id, 'version' => 1,
            'mutation_id' => (string) Str::uuid(), 'before' => null, 'after' => json_encode($row),
            'actor_id' => $this->actor->id, 'created_at' => now()]);
        $distribution = ['key' => (string) Str::uuid(), 'own_cost_id' => $id, 'estimate_id' => $this->estimate->id,
            'allocation_id' => $allocation->id, 'amount' => '60.00', 'amount_without_vat' => null,
            'version' => 1, 'source_version' => 1, 'condition_version' => 1, 'updated_by' => $this->actor->id,
            'created_at' => now(), 'updated_at' => now()];
        $distributionId = $db::table('estimate_finance_own_cost_allocations')->insertGetId($distribution);
        $db::table('estimate_finance_own_cost_allocation_versions')->insert(['own_cost_allocation_id' => $distributionId,
            'version' => 1, 'mutation_id' => (string) Str::uuid(), 'finance_revision' => 1,
            'before' => null, 'after' => json_encode($distribution), 'actor_id' => $this->actor->id, 'created_at' => now()]);
        self::assertNull($db::table('estimate_finance_own_costs')->where('id', $id)->value('amount_without_vat'));
        self::assertSame('60.00', $db::table('estimate_finance_own_cost_allocations')->where('id', $distributionId)->value('amount'));
        foreach ([
            fn () => $db::table('estimate_finance_own_costs')->where('id', $id)->update(['currency' => 'rub']),
            fn () => $db::table('estimate_finance_own_costs')->where('id', $id)->update(['amount' => '-1']),
            fn () => $db::table('estimate_finance_own_costs')->where('id', $id)->update(['vat_mode' => 'none']),
            fn () => $db::table('estimate_finance_own_costs')->where('id', $id)->update(['source_type' => 'advance_expense']),
            fn () => $db::table('estimate_finance_own_costs')->where('id', $id)->delete(),
            fn () => $db::table('estimate_finance_allocations')->where('id', $allocation->id)->delete(),
            fn () => $db::table('estimate_finance_own_cost_allocations')->where('id', $distributionId)->delete(),
            fn () => $db::table('estimate_finance_own_cost_allocations')->insert(array_replace($distribution, ['key' => (string) Str::uuid()])),
        ] as $invalidWrite) {
            try {
                $db::transaction($invalidWrite);
                self::fail('Own cost source, tax or history constraint was not enforced');
            } catch (\Illuminate\Database\QueryException $exception) {
                self::assertContains($exception->getCode(), ['23514', '23503', '23505']);
            }
        }
        $source = \App\Models\AdvanceAccountTransaction::query()->create(['organization_id' => $this->estimate->organization_id,
            'project_id' => $this->estimate->project_id, 'user_id' => $this->actor->id, 'type' => 'expense',
            'amount' => '120', 'balance_after' => '0', 'reporting_status' => 'approved', 'approved_at' => now(),
            'created_by_user_id' => $this->actor->id, 'approved_by_user_id' => $this->actor->id]);
        $linked = array_replace($row, ['key' => (string) Str::uuid(), 'source_type' => 'advance_expense', 'advance_transaction_id' => $source->id]);
        $db::table('estimate_finance_own_costs')->insert($linked);
        try {
            $db::transaction(fn () => $db::table('estimate_finance_own_costs')->insert(array_replace($linked, ['key' => (string) Str::uuid()])));
            self::fail('Duplicate expense document was allowed');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertSame('23505', $exception->getCode());
        }
        self::assertSame($row['key'], $db::table('estimate_finance_own_costs')->where('id', $id)->value('key'));
    }

    public function test_manual_quantity_counts_native_lines_once_when_act_also_has_legacy_works(): void
    {
        $this->save($this->command([$this->line($this->customer, '100', '1000000')]));
        $allocation = EstimateFinanceAllocation::query()->where('estimate_id', $this->estimate->id)->firstOrFail();
        $native = \App\Models\ContractPerformanceAct::query()->create(['contract_id' => $this->customer->id,
            'project_id' => $this->estimate->project_id, 'act_document_number' => 'NATIVE-MIXED',
            'act_date' => '2026-09-13', 'amount' => '60', 'status' => 'draft', 'currency' => 'RUB']);
        \App\Models\PerformanceActLine::query()->create(['performance_act_id' => $native->id,
            'estimate_item_id' => $this->item->id, 'line_type' => 'manual', 'manual_reason' => 'Основание', 'title' => 'Работа',
            'quantity' => '60', 'unit_price' => '1', 'amount' => '60', 'currency' => 'RUB',
            'basis_snapshot' => ['basis_type' => 'contract_conditions', 'allocation_key' => $allocation->key,
                'contract_id' => $this->customer->id, 'estimate_id' => $this->estimate->id, 'estimate_item_id' => $this->item->id]]);
        $work = \App\Models\CompletedWork::query()->create(['organization_id' => $this->estimate->organization_id,
            'project_id' => $this->estimate->project_id, 'contract_id' => $this->customer->id,
            'estimate_item_id' => $this->item->id, 'user_id' => $this->actor->id, 'quantity' => '60',
            'completed_quantity' => '60', 'price' => '1', 'total_amount' => '60',
            'completion_date' => '2026-09-13', 'status' => 'confirmed', 'description' => 'То же выполнение']);
        $native->completedWorks()->attach($work->id, ['included_quantity' => '60', 'included_amount' => '60', 'currency' => 'RUB']);
        $native->update(['status' => 'approved', 'is_approved' => true]);
        $act = \App\Models\ContractPerformanceAct::query()->create(['contract_id' => $this->customer->id,
            'project_id' => $this->estimate->project_id, 'act_document_number' => 'MANUAL-MIXED',
            'act_date' => '2026-09-13', 'amount' => '100', 'status' => 'approved', 'is_approved' => true, 'currency' => 'RUB']);
        $command = ['operation' => 'execution_distribution', 'revision' => (int) $this->estimate->fresh()->finance_revision,
            'mutation_id' => (string) Str::uuid(), 'act_id' => $act->id,
            'lines' => [['allocation_key' => $allocation->key, 'condition_version' => 1, 'version' => 0, 'amount' => '40', 'quantity' => '41']]];
        try {
            $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $command);
            self::fail('Native accepted quantity was ignored');
        } catch (ValidationException) {
            self::assertSame(0, \Illuminate\Support\Facades\DB::table('estimate_finance_execution_allocations')->count());
        }
        $command['lines'][0]['quantity'] = '40';
        $preview = $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $command);
        self::assertSame('40.00000000', $preview['lines'][0]['quantity']);
        $command['source_hash'] = $preview['source_hash'];
        $this->save($command);
        self::assertSame('40.00000000', \Illuminate\Support\Facades\DB::table('estimate_finance_execution_allocations')->value('quantity'));
    }

    public function test_manual_execution_quantity_counts_other_acts_and_can_clear_distribution(): void
    {
        $this->save($this->command([$this->line($this->customer, '100', '1000000')]));
        $allocation = EstimateFinanceAllocation::query()->where('estimate_id', $this->estimate->id)->firstOrFail();
        $acts = [];
        foreach (['ONE', 'TWO'] as $number) {
            $acts[] = \App\Models\ContractPerformanceAct::query()->create(['contract_id' => $this->customer->id,
                'project_id' => $this->estimate->project_id, 'act_document_number' => 'QUANTITY-'.$number,
                'act_date' => '2026-09-13', 'amount' => '120', 'status' => 'approved', 'is_approved' => true, 'currency' => 'RUB']);
        }
        $make = fn (int $actId, string $quantity): array => ['operation' => 'execution_distribution',
            'revision' => (int) $this->estimate->fresh()->finance_revision, 'mutation_id' => (string) Str::uuid(), 'act_id' => $actId,
            'lines' => [['allocation_key' => $allocation->key, 'condition_version' => 1, 'version' => 0, 'amount' => '60', 'quantity' => $quantity]]];
        $first = $make($acts[0]->id, '60.12345678');
        $first['source_hash'] = $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $first)['source_hash'];
        $this->save($first);
        $second = $make($acts[1]->id, '40');
        try {
            $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $second);
            self::fail('Other act quantity was ignored');
        } catch (ValidationException) {
            self::assertSame(1, \Illuminate\Support\Facades\DB::table('estimate_finance_execution_allocations')->count());
        }
        $second['lines'][0]['quantity'] = '39.87654322';
        $second['source_hash'] = $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $second)['source_hash'];
        $this->save($second);
        $db = \Illuminate\Support\Facades\DB::class;
        $rows = $db::table('estimate_finance_execution_allocations')->orderBy('id')->get();
        self::assertSame('100.00000000', FinanceDecimal::value(FinanceDecimal::add($rows[0]->quantity, $rows[1]->quantity), 8));
        $second['revision'] = (int) $this->estimate->fresh()->finance_revision;
        $second['mutation_id'] = (string) Str::uuid();
        $second['lines'][0]['version'] = 1;
        $second['lines'][0]['amount'] = '0';
        unset($second['lines'][0]['quantity']);
        $this->save($second);
        self::assertSame('0.00000000', $db::table('estimate_finance_execution_allocations')->where('id', $rows[1]->id)->value('quantity'));
        self::assertSame($rows[1]->key, $db::table('estimate_finance_execution_allocations')->where('id', $rows[1]->id)->value('key'));
        self::assertSame(3, $db::table('estimate_finance_execution_versions')->count());
    }

    public function test_manual_execution_protects_conditions_until_fact_is_annulled(): void
    {
        $this->save($this->command([$this->line($this->customer, '100', '1000000')]));
        $allocation = EstimateFinanceAllocation::query()->where('estimate_id', $this->estimate->id)->firstOrFail();
        $act = \App\Models\ContractPerformanceAct::query()->create(['contract_id' => $this->customer->id,
            'project_id' => $this->estimate->project_id, 'act_document_number' => 'PROTECTED-CONDITIONS',
            'act_date' => '2026-09-13', 'amount' => '120', 'status' => 'approved', 'is_approved' => true, 'currency' => 'RUB']);
        $command = ['operation' => 'execution_distribution', 'revision' => (int) $this->estimate->fresh()->finance_revision,
            'mutation_id' => (string) Str::uuid(), 'act_id' => $act->id,
            'lines' => [['allocation_key' => $allocation->key, 'condition_version' => 1, 'version' => 0, 'amount' => '60']]];
        $command['source_hash'] = $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $command)['source_hash'];
        $this->save($command);
        $guard = app(\App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceAcceptedVolume::class);
        $row = $allocation->attributesToArray();
        $target = ['i:'.$this->item->id];
        foreach ([[], [array_replace($row, ['quantity' => '99'])], [array_replace($row, ['currency' => 'USD'])]] as $invalid) {
            try {
                $guard->assertRetained($this->estimate, $target, $invalid);
                self::fail('Manually allocated fact lost its conditions');
            } catch (ValidationException) {
                self::assertSame(1, \Illuminate\Support\Facades\DB::table('estimate_finance_execution_allocations')->count());
            }
        }
        $guard->assertRetained($this->estimate, $target, [$row]);
        \Illuminate\Support\Facades\DB::table('estimate_finance_execution_allocations')->update(['quantity' => '10']);
        $guard->assertRetained($this->estimate, $target, [array_replace($row, ['quantity' => '10'])]);
        try {
            $guard->assertRetained($this->estimate, $target, [array_replace($row, ['quantity' => '9'])]);
            self::fail('Accepted manual quantity was reduced');
        } catch (ValidationException) {
            self::assertSame('10.00000000', \Illuminate\Support\Facades\DB::table('estimate_finance_execution_allocations')->value('quantity'));
        }
        $act->update(['annulled_at' => now()]);
        $guard->assertRetained($this->estimate, $target, [array_replace($row, ['quantity' => '0'])]);
        self::assertSame(1, \Illuminate\Support\Facades\DB::table('estimate_finance_execution_versions')->count());
        $this->expectException(ValidationException::class);
        $guard->assertRetained($this->estimate, $target, []);
    }

    public function test_execution_distribution_shares_act_capacity_between_estimates_and_checks_access(): void
    {
        $firstLine = $this->line($this->customer, '100', '1000000');
        $this->save($this->command([$firstLine]));
        $second = $this->estimate->replicate();
        $second->number = 'EXECUTION-2';
        $second->save();
        $item = $this->item->replicate();
        $item->estimate_id = $second->id;
        $item->estimate_section_id = null;
        $item->save();
        $secondLine = array_replace($firstLine, ['key' => (string) Str::uuid(), 'target_key' => 'i:'.$item->id]);
        $this->finance->save($this->actor, $second->project_id, $second->id, ['mutation_id' => (string) Str::uuid(),
            'revision' => (int) $second->fresh()->finance_revision, 'target_keys' => [$secondLine['target_key']], 'lines' => [$secondLine]]);
        $act = \App\Models\ContractPerformanceAct::query()->create(['contract_id' => $this->customer->id,
            'project_id' => $this->estimate->project_id, 'act_document_number' => 'SHARED-CAPACITY',
            'act_date' => '2026-09-13', 'amount' => '0.03', 'amount_without_vat' => '0.02',
            'status' => 'approved', 'is_approved' => true, 'currency' => 'RUB']);
        $command = fn (Estimate $estimate, array $line, string $amount): array => ['operation' => 'execution_distribution',
            'revision' => (int) $estimate->fresh()->finance_revision, 'mutation_id' => (string) Str::uuid(), 'act_id' => $act->id,
            'lines' => [['allocation_key' => $line['key'], 'version' => 0, 'condition_version' => 1, 'amount' => $amount]]];
        $first = $command($this->estimate, $firstLine, '0.02');
        $first['source_hash'] = $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $first)['source_hash'];
        $this->save($first);
        $next = $command($second, $secondLine, '0.02');
        try {
            $this->finance->preview($this->actor, $second->project_id, $second->id, $next);
            self::fail('The same act remainder was used twice');
        } catch (ValidationException) {
            self::assertSame(1, \Illuminate\Support\Facades\DB::table('estimate_finance_execution_allocations')->count());
        }
        $next['lines'][0]['amount'] = '0.01';
        $preview = $this->finance->preview($this->actor, $second->project_id, $second->id, $next);
        self::assertSame('0.00', $preview['remaining_amount']);
        $next['source_hash'] = $preview['source_hash'];
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnUsing(fn ($actor, $permission) => $permission !== 'contracts.edit');
        try {
            app(EstimateFinanceService::class)->save($this->actor, $second->project_id, $second->id, $next);
            self::fail('Contract edit permission was bypassed');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            self::assertSame(1, \Illuminate\Support\Facades\DB::table('estimate_finance_execution_allocations')->count());
        }
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnTrue();
        app(EstimateFinanceService::class)->save($this->actor, $second->project_id, $second->id, $next);
        $rows = \Illuminate\Support\Facades\DB::table('estimate_finance_execution_allocations')->orderBy('id')->get();
        self::assertSame('0.03', FinanceDecimal::add($rows[0]->amount_with_vat, $rows[1]->amount_with_vat));
        self::assertSame('0.02', FinanceDecimal::add($rows[0]->amount_without_vat, $rows[1]->amount_without_vat));
        self::assertSame(2, \Illuminate\Support\Facades\DB::table('estimate_finance_execution_versions')->count());
    }

    public function test_execution_distribution_saves_exact_net_replays_and_limits_native_remainder(): void
    {
        $this->save($this->command([$this->line($this->customer, '100', '1000000')]));
        $allocation = EstimateFinanceAllocation::query()->where('estimate_id', $this->estimate->id)->firstOrFail();
        $act = \App\Models\ContractPerformanceAct::query()->create(['contract_id' => $this->customer->id,
            'project_id' => $this->estimate->project_id, 'act_document_number' => 'DISTRIBUTE-EXECUTION',
            'act_date' => '2026-09-13', 'amount' => '120', 'amount_without_vat' => '100',
            'status' => 'approved', 'is_approved' => true, 'currency' => 'RUB']);
        $before = $act->fresh()->getAttributes();
        $command = ['operation' => 'execution_distribution', 'revision' => (int) $this->estimate->fresh()->finance_revision,
            'mutation_id' => (string) Str::uuid(), 'act_id' => $act->id,
            'lines' => [['allocation_key' => $allocation->key, 'condition_version' => 1, 'version' => 0, 'amount' => '60']]];
        $preview = $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id, $command);
        self::assertSame('60.00', $preview['remaining_amount']);
        self::assertSame('50.00', $preview['lines'][0]['amount_without_vat']);
        $command['source_hash'] = $preview['source_hash'];
        $saved = $this->save($command);
        self::assertTrue($this->save($command)['replayed']);
        $db = \Illuminate\Support\Facades\DB::class;
        $stored = $db::table('estimate_finance_execution_allocations')->first();
        self::assertSame('60.00', $stored->amount_with_vat);
        self::assertSame('50.00', $stored->amount_without_vat);
        self::assertNull($stored->quantity);
        self::assertSame(1, $db::table('estimate_finance_execution_versions')->count());
        self::assertSame($before, $act->fresh()->getAttributes());
        $execution = $this->finance->report($this->actor, $this->estimate->project_id, $this->estimate->id, 'with_vat', 'execution')['execution'];
        self::assertCount(1, $execution['rows']);
        self::assertSame('act_distribution', $execution['rows'][0]['source_type']);
        self::assertSame('60.00', $execution['rows'][0]['amount_with_vat']);
        self::assertSame('60.00', $execution['documents'][0]['unallocated_amount_with_vat']);
        self::assertSame('60.00', $execution['summary']['totals']['RUB']['revenue']);
        self::assertNull($execution['summary']['contract_quantities'][0]['accepted_quantity']);
        $command['mutation_id'] = (string) Str::uuid();
        $command['revision'] = $saved['revision'];
        $command['lines'][0]['version'] = 1;
        $command['lines'][0]['amount'] = '120.01';
        try {
            $this->save($command);
            self::fail('Execution amount exceeded the act');
        } catch (ValidationException) {
            self::assertSame(1, $db::table('estimate_finance_execution_versions')->count());
            self::assertSame('60.00', $db::table('estimate_finance_execution_allocations')->value('amount_with_vat'));
        }
        $command['lines'][0]['amount'] = '120';
        $this->save($command);
        self::assertSame($stored->key, $db::table('estimate_finance_execution_allocations')->value('key'));
        self::assertSame('100.00', $db::table('estimate_finance_execution_allocations')->value('amount_without_vat'));
        self::assertSame(2, $db::table('estimate_finance_execution_versions')->count());
        $execution = $this->finance->report($this->actor, $this->estimate->project_id, $this->estimate->id, 'with_vat', 'execution')['execution'];
        self::assertSame('0.00', $execution['documents'][0]['unallocated_amount_with_vat']);
        self::assertSame('120.00', $execution['summary']['totals']['RUB']['revenue']);
        $act->update(['status' => 'draft', 'is_approved' => false]);
        $act->update(['amount' => '150', 'status' => 'approved', 'is_approved' => true]);
        $changed = $this->finance->report($this->actor, $this->estimate->project_id, $this->estimate->id, 'with_vat', 'execution')['execution'];
        self::assertTrue($changed['rows'][0]['requires_review']);
        self::assertNull($changed['rows'][0]['amount_with_vat']);
        self::assertSame('120.00', $changed['rows'][0]['saved_amount_with_vat']);
        self::assertNull($changed['summary']['totals']['RUB']['revenue']);
        $act->update(['annulled_at' => now()]);
        self::assertSame([], $this->finance->report($this->actor, $this->estimate->project_id, $this->estimate->id, 'with_vat', 'execution')['execution']['rows']);
        self::assertSame(2, $db::table('estimate_finance_execution_versions')->count());
    }

    public function test_execution_distribution_source_tracks_native_remainder_and_rejects_unapproved_act(): void
    {
        $this->save($this->command([$this->line($this->customer, '100', '1000000')]));
        $act = \App\Models\ContractPerformanceAct::query()->create(['contract_id' => $this->customer->id,
            'project_id' => $this->estimate->project_id, 'act_document_number' => 'SOURCE-REMAINDER',
            'act_date' => '2026-09-13', 'amount' => '120', 'amount_without_vat' => '100',
            'status' => 'approved', 'is_approved' => true, 'currency' => 'RUB']);
        $service = app(\App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceExecutionSource::class);
        $source = $service->read($this->estimate, $act);
        self::assertSame('120.00', $source['amount_with_vat']);
        self::assertSame('100.00', $source['amount_without_vat']);
        self::assertSame($source['source_hash'], $service->read($this->estimate, $act)['source_hash']);
        $line = \App\Models\PerformanceActLine::query()->create(['performance_act_id' => $act->id,
            'estimate_item_id' => $this->item->id, 'line_type' => 'manual', 'manual_reason' => 'Основание', 'title' => 'Работа',
            'quantity' => '1', 'unit_price' => '60', 'amount' => '60', 'currency' => 'RUB']);
        $next = $service->read($this->estimate, $act);
        self::assertSame('60.00', $next['amount_with_vat']);
        self::assertNull($next['amount_without_vat']);
        self::assertNotSame($source['source_hash'], $next['source_hash']);
        $act->update(['status' => 'draft', 'is_approved' => false]);
        $line->update(['title' => 'Уточнённая работа']);
        $act->update(['status' => 'approved', 'is_approved' => true]);
        self::assertNotSame($next['source_hash'], $service->read($this->estimate, $act)['source_hash']);
        $act->update(['status' => 'draft', 'is_approved' => false]);
        $this->expectException(ValidationException::class);
        $service->read($this->estimate, $act);
    }

    public function test_execution_distribution_storage_preserves_unknown_values_and_fact_references(): void
    {
        $this->save($this->command([$this->line($this->customer, '100', '1000000')]));
        $allocation = EstimateFinanceAllocation::query()->where('estimate_id', $this->estimate->id)->firstOrFail();
        $act = \App\Models\ContractPerformanceAct::query()->create(['contract_id' => $this->customer->id,
            'project_id' => $this->estimate->project_id, 'act_document_number' => 'UNDISTRIBUTED',
            'act_date' => '2026-09-13', 'amount' => '100', 'status' => 'approved', 'is_approved' => true, 'currency' => 'RUB']);
        $db = \Illuminate\Support\Facades\DB::class;
        $row = ['key' => (string) Str::uuid(), 'organization_id' => $this->estimate->organization_id,
            'project_id' => $this->estimate->project_id, 'estimate_id' => $this->estimate->id, 'allocation_id' => $allocation->id,
            'performance_act_id' => $act->id, 'currency' => 'RUB', 'quantity' => null, 'amount_with_vat' => '60.00',
            'amount_without_vat' => null, 'version' => 1, 'condition_version' => 1,
            'source_hash' => hash('sha256', 'act'), 'source_snapshot' => json_encode(['amount' => '100']),
            'condition_snapshot' => json_encode(['allocation_key' => $allocation->key]),
            'updated_by' => $this->actor->id, 'created_at' => now(), 'updated_at' => now()];
        $id = $db::table('estimate_finance_execution_allocations')->insertGetId($row);
        $db::table('estimate_finance_execution_versions')->insert(['execution_allocation_id' => $id, 'version' => 1,
            'mutation_id' => (string) Str::uuid(), 'finance_revision' => 1, 'before' => null, 'after' => json_encode($row),
            'actor_id' => $this->actor->id, 'created_at' => now()]);
        $stored = $db::table('estimate_finance_execution_allocations')->where('id', $id)->first();
        self::assertNull($stored->quantity);
        self::assertNull($stored->amount_without_vat);
        foreach ([
            fn () => $db::table('estimate_finance_execution_allocations')->insert(array_replace($row, ['key' => (string) Str::uuid()])),
            fn () => $db::table('contract_performance_acts')->where('id', $act->id)->delete(),
            fn () => $db::table('estimate_finance_allocations')->where('id', $allocation->id)->delete(),
            fn () => $db::table('estimate_finance_execution_allocations')->where('id', $id)->delete(),
            fn () => $db::table('estimate_finance_execution_allocations')->where('id', $id)->update(['amount_without_vat' => '61']),
            fn () => $db::table('estimate_finance_execution_allocations')->where('id', $id)->update(['quantity' => '-1']),
        ] as $invalidWrite) {
            try {
                $db::transaction($invalidWrite);
                self::fail('Execution source, history or amount constraint was bypassed');
            } catch (\Illuminate\Database\QueryException $exception) {
                self::assertContains($exception->getCode(), ['23505', '23503', '23514']);
            }
        }
        self::assertSame('100.00', $act->fresh()->amount);
        self::assertSame('60.00', $db::table('estimate_finance_execution_allocations')->where('id', $id)->value('amount_with_vat'));
    }

    public function test_cash_allocation_storage_preserves_signed_amount_and_prevents_duplicate_transaction_target(): void
    {
        $this->save($this->command([$this->line($this->customer, '100', '1000000')]));
        $document = $this->cashDocument($this->customer, 'incoming');
        $transaction = $this->cashTransaction($document->id, '-100');
        $allocation = EstimateFinanceAllocation::query()->where('estimate_id', $this->estimate->id)->firstOrFail();
        $row = ['key' => (string) Str::uuid(), 'organization_id' => $this->estimate->organization_id,
            'project_id' => $this->estimate->project_id, 'estimate_id' => $this->estimate->id, 'allocation_id' => $allocation->id,
            'payment_transaction_id' => $transaction->id, 'currency' => 'RUB', 'amount' => '-100.00', 'version' => 1,
            'source_hash' => hash('sha256', 'snapshot'), 'source_snapshot' => json_encode(['amount' => '-100.00']),
            'updated_by' => $this->actor->id, 'created_at' => now(), 'updated_at' => now()];
        $id = \Illuminate\Support\Facades\DB::table('estimate_finance_cash_allocations')->insertGetId($row);
        \Illuminate\Support\Facades\DB::table('estimate_finance_cash_versions')->insert([
            'cash_allocation_id' => $id, 'version' => 1, 'mutation_id' => (string) Str::uuid(), 'finance_revision' => 1,
            'before' => null, 'after' => json_encode($row), 'actor_id' => $this->actor->id, 'created_at' => now(),
        ]);
        self::assertSame('-100.00', \Illuminate\Support\Facades\DB::table('estimate_finance_cash_allocations')->where('id', $id)->value('amount'));
        self::assertSame(1, \Illuminate\Support\Facades\DB::table('estimate_finance_cash_versions')->where('cash_allocation_id', $id)->count());
        foreach ([
            fn () => \Illuminate\Support\Facades\DB::table('estimate_finance_cash_allocations')->insert(array_replace($row, ['key' => (string) Str::uuid()])),
            fn () => \Illuminate\Support\Facades\DB::table('payment_transactions')->where('id', $transaction->id)->delete(),
            fn () => \Illuminate\Support\Facades\DB::table('estimate_finance_allocations')->where('id', $allocation->id)->delete(),
            fn () => \Illuminate\Support\Facades\DB::table('estimate_finance_cash_allocations')->where('id', $id)->delete(),
        ] as $invalidWrite) {
            try {
                \Illuminate\Support\Facades\DB::transaction($invalidWrite);
                self::fail('Financial source or history constraint was not enforced');
            } catch (\Illuminate\Database\QueryException $exception) {
                self::assertContains($exception->getCode(), ['23505', '23503']);
            }
        }
        self::assertSame($row['key'], \Illuminate\Support\Facades\DB::table('estimate_finance_cash_allocations')->where('id', $id)->value('key'));
    }

    public function test_cash_sources_keep_partial_payments_refunds_and_currency_separate(): void
    {
        $this->save($this->command([$this->line($this->customer, '100', '1000000')]));
        $document = $this->cashDocument($this->customer, 'incoming');
        $payment = $this->cashTransaction($document->id, '400');
        $this->cashTransaction($document->id, '200');
        $refund = $this->cashTransaction($document->id, '-100', ['reverses_transaction_id' => $payment->id]);
        $this->cashTransaction($document->id, '999', ['status' => 'pending']);
        $this->cashTransaction($document->id, '10', ['currency' => 'USD']);
        $foreignOrg = Organization::factory()->create();
        $this->cashTransaction($document->id, '999', ['organization_id' => $foreignOrg->id]);
        $otherProject = Project::factory()->create(['organization_id' => $this->estimate->organization_id]);
        $this->cashTransaction($document->id, '999', ['project_id' => $otherProject->id]);
        $result = $this->finance->report($this->actor, $this->estimate->project_id, $this->estimate->id, 'with_vat', 'cash')['cash'];
        self::assertSame('linked_contracts', $result['scope']);
        self::assertCount(4, $result['sources']);
        self::assertCount(1, $result['documents']);
        self::assertSame('500.00', $result['documents'][0]['confirmed_amounts']['RUB']);
        self::assertSame('10.00', $result['documents'][0]['confirmed_amounts']['USD']);
        self::assertSame('600.00', $result['documents'][0]['recorded_paid_amount']);
        self::assertSame('600.00', $result['summary']['totals']['RUB']['receipts']);
        self::assertSame('100.00', $result['summary']['totals']['RUB']['payments']);
        self::assertSame('500.00', $result['summary']['totals']['RUB']['difference']);
        self::assertSame('100.00', $result['summary']['totals']['RUB']['customer_refunds']);
        self::assertSame('10.00', $result['summary']['totals']['USD']['difference']);
        self::assertSame($result['summary']['totals'], $result['summary']['contracts'][$this->customer->id]);
        self::assertSame('-100.00', array_values(array_filter($result['sources'], fn ($source) => $source['transaction_id'] === $refund->id))[0]['amount']);
        self::assertSame('revenue', $result['sources'][0]['side']);
        self::assertSame('advance', $result['sources'][0]['invoice_type']);
        self::assertArrayNotHasKey('estimate_amount', $result['documents'][0]);
    }

    public function test_project_cash_counts_shared_contract_payments_once(): void
    {
        $this->save($this->command([$this->line($this->customer, '100', '1000000')]));
        $second = $this->estimate->replicate();
        $second->number = 'FIN-CASH-2';
        $second->save();
        $secondItem = $this->item->replicate();
        $secondItem->estimate_id = $second->id;
        $secondItem->save();
        $projection = \App\Models\ContractEstimateItem::query()->where('estimate_id', $this->estimate->id)->firstOrFail()->replicate();
        $projection->estimate_id = $second->id;
        $projection->estimate_item_id = $secondItem->id;
        $projection->save();
        $document = $this->cashDocument($this->customer, 'incoming');
        $payment = $this->cashTransaction($document->id, '400');
        $this->cashTransaction($document->id, '-100', ['reverses_transaction_id' => $payment->id]);
        $result = $this->finance->projectReport($this->actor, $this->estimate->project_id, 'without_vat', false, 'cash');
        self::assertCount(2, $result['estimates']);
        self::assertCount(2, $result['cash']['sources']);
        self::assertCount(1, $result['cash']['documents']);
        self::assertSame('300.00', $result['cash']['summary']['totals']['RUB']['difference']);
        self::assertSame([$this->estimate->id, $second->id], $result['cash']['documents'][0]['linked_estimate_ids']);
        self::assertArrayNotHasKey('rows', $result['estimates'][0]);
        foreach ($result['estimates'] as $report) {
            self::assertSame('300.00', $report['cash']['summary']['totals']['RUB']['difference']);
        }
        $result['estimates'][0]['contracts'][0]['number'] = '=NOT_A_FORMULA()';
        $book = app(\App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceExport::class)
            ->workbook($result['estimates'], 'without_vat', [], 'cash', null, $result['cash']);
        self::assertSame(5, $book->getSheetCount());
        self::assertSame(2, $book->getSheet(0)->getHighestRow());
        self::assertEquals(300, $book->getSheet(0)->getCell('E2')->getValue());
        self::assertSame('Поступления минус выплаты', $book->getSheet(0)->getCell('E1')->getValue());
        self::assertSame(2, $book->getSheet(1)->getHighestRow());
        self::assertSame(3, $book->getSheet(2)->getHighestRow());
        self::assertEquals(-100, $book->getSheet(2)->getCell('G3')->getValue());
        self::assertSame('s', $book->getSheet(2)->getCell('D2')->getDataType());
        self::assertSame((string) $payment->id, $book->getSheet(2)->getCell('I3')->getValue());
        $book->disconnectWorksheets();
    }

    public function test_cash_sources_resolve_act_contract_and_flag_missing_history_and_direction(): void
    {
        $this->save($this->command([$this->line($this->contractor, '100', '1000000')]));
        $act = \App\Models\ContractPerformanceAct::query()->create(['contract_id' => $this->contractor->id, 'project_id' => $this->estimate->project_id,
            'act_document_number' => 'CASH-ACT', 'act_date' => '2026-09-12', 'amount' => '1000', 'currency' => 'RUB', 'status' => 'approved']);
        $document = $this->cashDocument($this->contractor, 'incoming');
        $document->update(['invoiceable_type' => \App\Models\ContractPerformanceAct::class, 'invoiceable_id' => $act->id]);
        $this->cashTransaction($document->id, '100');
        $missing = $this->cashDocument($this->contractor, 'outgoing');
        $result = $this->finance->report($this->actor, $this->estimate->project_id, $this->estimate->id, 'with_vat', 'cash')['cash'];
        self::assertCount(2, $result['documents']);
        self::assertSame($act->id, $result['sources'][0]['act_id']);
        self::assertSame('unknown', $result['sources'][0]['side']);
        self::assertTrue($result['sources'][0]['direction_requires_review']);
        self::assertNull($result['summary']['totals']['RUB']['difference']);
        self::assertSame(1, $result['summary']['totals']['RUB']['unclassified_count']);
        $book = app(\App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceExport::class)
            ->workbook([['name' => 'Cash', 'contracts' => [], 'cash' => $result]], 'with_vat', [], 'cash');
        self::assertSame('', $book->getSheet(0)->getCell('E2')->getValue());
        self::assertSame('Требует проверки или распределения', $book->getSheet(0)->getCell('L2')->getValue());
        $book->disconnectWorksheets();
        self::assertTrue(array_values(array_filter($result['documents'], fn ($entry) => $entry['id'] === $missing->id))[0]['payment_history_missing']);
    }

    public function test_cash_report_does_not_query_payments_without_both_permissions(): void
    {
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnUsing(fn ($actor, $permission) => $permission !== 'payments.transaction.view');
        $queries = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries): void { $queries[] = $query->sql; });
        $result = app(EstimateFinanceService::class)->report($this->actor, $this->estimate->project_id, $this->estimate->id, 'with_vat', 'cash')['cash'];
        self::assertFalse($result['available']);
        self::assertNull($result['sources']);
        self::assertSame([], array_values(array_filter($queries, fn ($sql) => str_contains($sql, 'payment_documents') || str_contains($sql, 'payment_transactions'))));
        $project = app(EstimateFinanceService::class)->projectReport($this->actor, $this->estimate->project_id, 'with_vat', false, 'cash');
        self::assertFalse($project['cash']['available']);
        self::assertNull($project['cash']['sources']);
        $book = app(\App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceExport::class)
            ->workbook($project['estimates'], 'with_vat', [], 'cash', null, $project['cash']);
        self::assertSame('Нет доступа к платёжным документам или транзакциям', $book->getSheet(0)->getCell('L2')->getValue());
        self::assertSame(1, $book->getSheet(1)->getHighestRow());
        self::assertSame(1, $book->getSheet(2)->getHighestRow());
        $book->disconnectWorksheets();
        self::assertSame([], array_values(array_filter($queries, fn ($sql) => str_contains($sql, 'payment_documents') || str_contains($sql, 'payment_transactions'))));
    }

    private function cashDocument(Contract $contract, string $direction): \App\BusinessModules\Core\Payments\Models\PaymentDocument
    {
        return \App\BusinessModules\Core\Payments\Models\PaymentDocument::query()->create(['organization_id' => $this->estimate->organization_id,
            'project_id' => $this->estimate->project_id, 'document_type' => 'invoice', 'document_number' => 'CASH-'.Str::uuid(),
            'document_date' => '2026-09-12', 'due_date' => '2026-09-12', 'direction' => $direction, 'invoice_type' => 'advance',
            'invoiceable_type' => Contract::class, 'invoiceable_id' => $contract->id, 'amount' => '1000', 'paid_amount' => '600', 'remaining_amount' => '400',
            'currency' => 'RUB', 'status' => 'partially_paid', 'created_by_user_id' => $this->actor->id]);
    }

    private function cashTransaction(int $documentId, string $amount, array $extra = []): \App\BusinessModules\Core\Payments\Models\PaymentTransaction
    {
        return \App\BusinessModules\Core\Payments\Models\PaymentTransaction::query()->create($extra + ['organization_id' => $this->estimate->organization_id,
            'project_id' => $this->estimate->project_id, 'payment_document_id' => $documentId, 'amount' => $amount, 'currency' => 'RUB',
            'status' => 'completed', 'payment_method' => 'bank_transfer', 'transaction_date' => '2026-09-12', 'created_by_user_id' => $this->actor->id]);
    }

    public function test_execution_report_uses_approved_documents_and_keeps_unallocated_amount(): void
    {
        $line = $this->line($this->contractor, '100', '1000000');
        $this->save($this->command([$line]));
        $act = \App\Models\ContractPerformanceAct::query()->create(['contract_id' => $this->contractor->id,
            'project_id' => $this->estimate->project_id, 'act_document_number' => 'FACT-1', 'act_date' => '2026-09-12',
            'amount' => '150', 'status' => 'signed', 'is_approved' => true, 'currency' => 'RUB']);
        $base = ['performance_act_id' => $act->id, 'line_type' => 'manual', 'manual_reason' => 'Основание',
            'title' => 'Работа', 'unit' => 'шт', 'quantity' => '1', 'unit_price' => '120', 'amount' => '120', 'currency' => 'RUB'];
        $factLine = \App\Models\PerformanceActLine::query()->create($base + ['estimate_item_id' => $this->item->id,
            'basis_snapshot' => ['basis_type' => 'contract_conditions', 'allocation_key' => $line['key'],
                'condition_version' => 1, 'contract_id' => $this->contractor->id, 'estimate_item_id' => $this->item->id,
                'estimate_id' => $this->estimate->id,
                'base_unit_price' => '100', 'currency' => 'RUB']]);
        \App\Models\PerformanceActLine::query()->create(array_replace($base, ['amount' => '30', 'unit_price' => '30']));
        foreach (['draft', 'annulled'] as $status) {
            \App\Models\ContractPerformanceAct::query()->create(['contract_id' => $this->contractor->id,
                'project_id' => $this->estimate->project_id, 'act_document_number' => 'FACT-'.$status, 'act_date' => '2026-09-12',
                'amount' => '999', 'status' => $status, 'is_approved' => true, 'currency' => 'RUB']);
        }
        $report = $this->finance->report($this->actor, $this->estimate->project_id, $this->estimate->id, 'without_vat', 'execution');
        $result = $report['execution'];
        self::assertTrue($result['available']);
        self::assertCount(1, $result['documents']);
        self::assertCount(1, $result['rows']);
        self::assertSame($factLine->id, $result['rows'][0]['source_id']);
        self::assertSame('100.00000000', $result['rows'][0]['amount_without_vat']);
        self::assertSame('120.00', $result['rows'][0]['amount_with_vat']);
        self::assertSame('30.00', $result['documents'][0]['unallocated_amount_with_vat']);
        self::assertNull($result['documents'][0]['amount_without_vat']);
        self::assertSame($line['key'], $result['rows'][0]['allocation_key']);
        self::assertSame('100.00', $result['summary']['totals']['RUB']['cost']);
        self::assertSame('99.00000000', $result['summary']['contract_quantities'][0]['remaining_quantity']);
        self::assertSame('i:'.$this->item->id, $result['summary']['positions'][0]['target_key']);
        $project = $this->finance->projectReport($this->actor, $this->estimate->project_id, 'without_vat', false, 'execution');
        self::assertSame('execution', $project['view']);
        self::assertSame($result['summary']['totals'], $project['execution']['summary']['totals']);
        self::assertCount(1, $project['execution']['documents']);
        self::assertArrayNotHasKey('rows', $project['estimates'][0]);
        $book = app(EstimateFinanceExport::class)->workbook([$report], 'without_vat', [], 'execution');
        try {
            self::assertEquals($result['summary']['totals']['RUB']['cost'], $book->getSheet(0)->getCell('D2')->getValue());
            self::assertEquals($result['summary']['positions'][0]['currencies']['RUB']['cost'], $book->getSheet(1)->getCell('E2')->getValue());
            self::assertEquals($result['summary']['contract_quantities'][0]['remaining_quantity'], $book->getSheet(3)->getCell('H2')->getValue());
            self::assertEquals($factLine->id, $book->getSheet(5)->getCell('E2')->getValue());
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function test_execution_report_does_not_query_acts_without_permission(): void
    {
        $this->mock(AuthorizationService::class)->shouldReceive('can')
            ->andReturnUsing(static fn ($actor, $permission): bool => ! in_array($permission, ['act_reports.view', 'contracts.performance_acts.view'], true));
        $finance = app(EstimateFinanceService::class);
        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();
        $report = $finance->report($this->actor, $this->estimate->project_id, $this->estimate->id, 'without_vat', 'execution');
        $queries = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();
        self::assertSame(['available' => false, 'rows' => null, 'documents' => null], $report['execution']);
        foreach ($queries as $query) {
            self::assertDoesNotMatchRegularExpression('/contract_performance_acts|performance_act_lines|performance_act_completed_works/', $query['query']);
        }
    }

    public function test_execution_report_accepts_contract_permission_in_project_context(): void
    {
        $projectId = (int) $this->estimate->project_id;
        $this->mock(AuthorizationService::class)->shouldReceive('can')
            ->andReturnUsing(static function ($actor, string $permission, array $context) use ($projectId): bool {
                if ($permission === 'act_reports.view') {
                    return false;
                }
                if ($permission === 'contracts.performance_acts.view') {
                    return ($context['project_id'] ?? null) === $projectId && ($context['context_type'] ?? null) === 'project';
                }

                return true;
            });
        $report = app(EstimateFinanceService::class)->report($this->actor, $projectId, $this->estimate->id, 'without_vat', 'execution');
        self::assertTrue($report['can_view_execution']);
        self::assertTrue($report['execution']['available']);
    }

    public function test_execution_report_uses_legacy_links_only_without_modern_lines_and_keeps_currencies(): void
    {
        $this->save($this->command([$this->line($this->contractor, '100', '1000000')]));
        $work = \App\Models\CompletedWork::query()->create(['organization_id' => $this->estimate->organization_id,
            'project_id' => $this->estimate->project_id, 'contract_id' => $this->contractor->id,
            'estimate_item_id' => $this->item->id, 'user_id' => $this->actor->id, 'quantity' => '100',
            'completed_quantity' => '100', 'price' => '100', 'total_amount' => '10000',
            'completion_date' => '2026-09-12', 'status' => 'confirmed', 'description' => 'Выполнение']);
        foreach ([false, true] as $modern) {
            $act = \App\Models\ContractPerformanceAct::query()->create(['contract_id' => $this->contractor->id,
                'project_id' => $this->estimate->project_id, 'act_document_number' => $modern ? 'MODERN' : 'LEGACY',
                'act_date' => '2026-09-12', 'amount' => $modern ? '120' : '100', 'amount_without_vat' => $modern ? '0' : '80',
                'status' => 'draft', 'currency' => 'RUB']);
            $act->completedWorks()->attach($work->id, ['included_quantity' => '1', 'included_amount' => '100', 'currency' => 'RUB']);
            if ($modern) {
                foreach (['RUB' => '120', 'USD' => '50'] as $currency => $amount) {
                    \App\Models\PerformanceActLine::query()->create(['performance_act_id' => $act->id,
                        'estimate_item_id' => $this->item->id, 'line_type' => 'manual', 'title' => 'Работа',
                        'quantity' => '1', 'unit_price' => $amount, 'amount' => $amount, 'currency' => $currency, 'manual_reason' => 'Основание']);
                }
            }
            $act->update(['status' => 'approved', 'is_approved' => true]);
        }
        $result = $this->finance->report($this->actor, $this->estimate->project_id, $this->estimate->id, 'without_vat', 'execution')['execution'];
        self::assertCount(2, $result['documents']);
        self::assertCount(3, $result['rows']);
        self::assertSame(['act_work', 'act_line', 'act_line'], array_column($result['rows'], 'source_type'));
        self::assertSame(['RUB', 'RUB', 'USD'], array_column($result['rows'], 'currency'));
        self::assertSame(['100.00', '120.00', '50.00'], array_column($result['rows'], 'amount_with_vat'));
        self::assertSame('0.00', $result['documents'][1]['unallocated_amount_with_vat']);
        self::assertSame('80.00', $result['documents'][0]['amount_without_vat']);
        self::assertNull($result['rows'][0]['amount_without_vat']);
        self::assertTrue($result['documents'][1]['needs_review']);
        self::assertNull($result['documents'][1]['amount_without_vat']);
    }

    public function test_coverage_commands_do_not_resynchronize_existing_execution(): void
    {
        $this->app->forgetInstance(ContractEstimateService::class);
        $this->app->forgetInstance(\App\BusinessModules\Features\BudgetEstimates\Services\Integration\EstimateCoverageService::class);
        $this->mock(\App\Services\CompletedWork\CompletedWorkFactService::class)
            ->shouldNotReceive('syncJournalEntriesForContractEstimateCoverage');
        $work = \App\Models\CompletedWork::query()->create([
            'organization_id' => $this->estimate->organization_id,
            'project_id' => $this->estimate->project_id,
            'estimate_item_id' => $this->item->id,
            'contract_id' => $this->contractor->id,
            'user_id' => $this->actor->id,
            'quantity' => '30',
            'completed_quantity' => '30',
            'price' => '8000',
            'total_amount' => '240000',
            'completion_date' => '2026-09-09',
            'status' => 'confirmed',
            'description' => 'Зафиксированное выполнение',
        ]);
        $before = $work->fresh()->getAttributes();
        $coverage = app(\App\BusinessModules\Features\BudgetEstimates\Services\Integration\EstimateCoverageService::class);
        $coverage->attachFullCoverage($this->contractor, $this->estimate, false, $this->actor);
        self::assertSame($before, $work->fresh()->getAttributes());
        $projection = ContractEstimateItem::query()->where('contract_id', $this->contractor->id)->firstOrFail();
        $coverage->syncCoverageItems($this->contractor, $this->estimate, [$this->item->id], false, $this->actor);
        self::assertSame($before, $work->fresh()->getAttributes());
        self::assertSame($projection->id, ContractEstimateItem::query()->where('contract_id', $this->contractor->id)->sole()->id);
        self::assertSame('1000000.00', $projection->fresh()->amount);
    }

    public function test_exact_distribution_has_deterministic_rounding_and_rejects_zero_base(): void
    {
        self::assertSame(['a' => '0.34', 'b' => '0.33', 'c' => '0.33'], FinanceDecimal::allocate('1.00', ['c' => '1', 'b' => '1', 'a' => '1']));
        self::assertSame('200000.00', FinanceDecimal::subtract('1000000.00', '800000.00'));
        $this->expectException(ValidationException::class);
        FinanceDecimal::allocate('1.00', ['a' => '0']);
    }

    public function test_contract_progress_does_not_query_forbidden_execution_sources(): void
    {
        $this->save($this->command([$this->line($this->contractor, '100', '1000000')]));
        $links = app(ContractEstimateService::class)->getItemsForContract($this->contractor);
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturn(false);
        $progress = app(\App\BusinessModules\Features\ContractManagement\Services\ContractEstimateOperationalProgress::class);
        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();
        $progress->prepare($this->contractor, $links, $this->actor);
        $data = \App\Http\Resources\Api\V1\Admin\Contract\ContractEstimateItemResource::collection($links)->resolve(request());
        $queries = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        foreach ($queries as $query) {
            self::assertDoesNotMatchRegularExpression('/completed_works|performance_act|journal_work/i', $query['query']);
        }
        foreach (['actual_quantity', 'available_quantity', 'acted_quantity', 'reserved_quantity', 'completion_percentage'] as $field) {
            self::assertNull($data[0]['item'][$field]);
        }
        self::assertFalse($data[0]['item']['can_view_works']);
        self::assertFalse($data[0]['item']['can_view_acts']);
        self::assertSame([], $data[0]['item']['available_actions']);
    }

    public function test_contract_progress_batches_queries_for_multiple_items(): void
    {
        for ($index = 0; $index < 30; $index++) {
            $item = $this->item->replicate();
            $item->position_number = (string) ($index + 2);
            $item->save();
            ContractEstimateItem::query()->create(['contract_id' => $this->contractor->id,
                'estimate_id' => $this->estimate->id, 'estimate_item_id' => $item->id,
                'quantity' => 100, 'amount' => 1000000, 'amount_without_vat' => 1000000]);
        }
        $links = app(ContractEstimateService::class)->getItemsForContract($this->contractor);
        $progress = app(\App\BusinessModules\Features\ContractManagement\Services\ContractEstimateOperationalProgress::class);
        $itemId = $links->first()->estimate_item_id;
        \App\Models\CompletedWork::query()->create(['organization_id' => $this->estimate->organization_id,
            'project_id' => $this->estimate->project_id, 'estimate_item_id' => $itemId, 'contract_id' => $this->contractor->id,
            'user_id' => $this->actor->id, 'quantity' => 20, 'completed_quantity' => 20, 'price' => 100,
            'total_amount' => 2000, 'completion_date' => '2026-09-09', 'status' => 'confirmed', 'description' => 'Выполнение']);
        foreach (['draft' => 3, 'approved' => 5, 'annulled' => 7] as $status => $quantity) {
            $act = \App\Models\ContractPerformanceAct::query()->create(['contract_id' => $this->contractor->id,
                'project_id' => $this->estimate->project_id, 'act_document_number' => 'BATCH-'.$status,
                'act_date' => '2026-09-12', 'amount' => 100, 'currency' => 'RUB', 'status' => 'draft',
                'created_by_user_id' => $this->actor->id]);
            \App\Models\PerformanceActLine::query()->create(['performance_act_id' => $act->id, 'estimate_item_id' => $itemId,
                'line_type' => 'manual', 'title' => 'Работа', 'quantity' => $quantity, 'unit_price' => 100,
                'amount' => $quantity * 100, 'currency' => 'RUB', 'manual_reason' => 'Проверка', 'created_by' => $this->actor->id]);
            $act->update(['status' => $status, 'is_approved' => $status !== 'draft']);
        }
        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();
        $progress->prepare($this->contractor, $links, $this->actor);
        $queryCount = count(\Illuminate\Support\Facades\DB::getQueryLog());
        $data = \App\Http\Resources\Api\V1\Admin\Contract\ContractEstimateItemResource::collection($links)->resolve(request());
        $serializedQueryCount = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        self::assertCount(30, $data);
        self::assertLessThanOrEqual(5, $queryCount);
        self::assertSame($queryCount, $serializedQueryCount);
        self::assertSame(20.0, $data[0]['item']['actual_quantity']);
        self::assertSame(5.0, $data[0]['item']['acted_quantity']);
        self::assertSame(3.0, $data[0]['item']['reserved_quantity']);
        self::assertSame(12.0, $data[0]['item']['available_quantity']);
        self::assertTrue($data[0]['item']['can_view_works']);
    }

    public function test_large_estimate_reads_without_queries_per_position(): void
    {
        $rows = [];
        for ($index = 0; $index < 8000; $index++) {
            $rows[] = ['estimate_id' => $this->estimate->id, 'position_number' => (string) ($index + 2),
                'name' => 'Импортированная позиция '.$index, 'item_type' => 'work', 'quantity' => 1,
                'quantity_total' => 1, 'unit_price' => 100, 'total_amount' => 100, 'is_manual' => false];
            if (count($rows) === 500) {
                EstimateItem::query()->insert($rows);
                $rows = [];
            }
        }
        $rows = [];
        foreach (EstimateItem::query()->where('estimate_id', $this->estimate->id)->get(['id', 'quantity', 'total_amount']) as $item) {
            $rows[] = ['contract_id' => $this->contractor->id, 'estimate_id' => $this->estimate->id,
                'estimate_item_id' => $item->id, 'quantity' => $item->quantity,
                'amount' => $item->total_amount, 'amount_without_vat' => $item->total_amount, 'finance_managed' => false];
            if (count($rows) === 500) {
                ContractEstimateItem::query()->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            ContractEstimateItem::query()->insert($rows);
        }
        $contracts = app(ContractEstimateService::class);
        $progress = app(\App\BusinessModules\Features\ContractManagement\Services\ContractEstimateOperationalProgress::class);
        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();
        $links = $contracts->getItemsForContract($this->contractor, $this->estimate->id);
        $progress->prepare($this->contractor, $links, $this->actor);
        $loadedQueries = count(\Illuminate\Support\Facades\DB::getQueryLog());
        $serialized = \App\Http\Resources\Api\V1\Admin\Contract\ContractEstimateItemResource::collection($links)->resolve(request());
        $serializedQueries = count(\Illuminate\Support\Facades\DB::getQueryLog());
        $summary = app(\App\BusinessModules\Features\BudgetEstimates\Services\Integration\EstimateCoverageService::class)
            ->getContractCoverageSummary($this->contractor);
        $report = $this->report();
        $totalQueries = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        self::assertCount(8001, $serialized);
        self::assertCount(8001, $report['rows']);
        self::assertSame($loadedQueries, $serializedQueries);
        self::assertLessThanOrEqual(40, $totalQueries);
        self::assertSame(8001, $summary['summary']['linked_items_count']);
        self::assertSame(1800000.0, $summary['summary']['linked_amount']);
    }

    public function test_large_atomic_save_batches_writes_and_preserves_ids_on_update_and_replay(): void
    {
        $rows = [];
        for ($index = 0; $index < 8000; $index++) {
            $rows[] = ['estimate_id' => $this->estimate->id, 'position_number' => (string) ($index + 2),
                'name' => 'Импортированная позиция '.$index, 'item_type' => 'work', 'quantity' => 1,
                'quantity_total' => 1, 'unit_price' => 100, 'total_amount' => 100, 'is_manual' => false];
            if (count($rows) === 500) {
                EstimateItem::query()->insert($rows);
                $rows = [];
            }
        }
        $command = $this->command([]);
        $command['target_keys'] = [];
        foreach (EstimateItem::query()->where('estimate_id', $this->estimate->id)->get(['id', 'quantity']) as $item) {
            $command['target_keys'][] = 'i:'.$item->id;
            foreach ([$this->customer, $this->contractor] as $contract) {
                $line = $this->line($contract, (string) $item->quantity, '100');
                $line['target_key'] = 'i:'.$item->id;
                $command['lines'][] = $line;
            }
        }
        $counting = true;
        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$queries, &$counting): void {
            if ($counting) {
                $queries++;
            }
        });
        $saved = $this->save($command);
        $counting = false;
        self::assertLessThan(300, $queries);
        self::assertSame($command['revision'] + 1, $saved['revision']);
        self::assertSame(16002, EstimateFinanceAllocation::query()->where('estimate_id', $this->estimate->id)->count());
        self::assertSame(16002, ContractEstimateItem::query()->where('estimate_id', $this->estimate->id)->count());
        self::assertSame(16002, \Illuminate\Support\Facades\DB::table('estimate_finance_condition_versions')->where('estimate_id', $this->estimate->id)->count());
        self::assertSame(0, EstimateFinanceAllocation::query()->where('estimate_id', $this->estimate->id)->whereNull('contract_estimate_item_id')->count());
        $ids = ContractEstimateItem::query()->where('estimate_id', $this->estimate->id)->orderBy('id')->pluck('id')->all();
        self::assertTrue($this->save($command)['replayed']);
        self::assertSame(16002, \Illuminate\Support\Facades\DB::table('estimate_finance_condition_versions')->where('estimate_id', $this->estimate->id)->count());
        $command['revision'] = $saved['revision'];
        $command['mutation_id'] = (string) Str::uuid();
        $command['lines'][16001]['quantity'] = '2';
        try {
            $this->save($command);
            self::fail('Превышение объёма должно отменить обе стороны');
        } catch (ValidationException) {
            self::assertSame($saved['revision'], (int) $this->estimate->fresh()->finance_revision);
            self::assertSame(16002, \Illuminate\Support\Facades\DB::table('estimate_finance_condition_versions')->where('estimate_id', $this->estimate->id)->count());
        }
        $command['lines'][16001]['quantity'] = '1';
        $command['lines'][0]['amount'] = '200';
        $this->save($command);
        self::assertSame($ids, ContractEstimateItem::query()->where('estimate_id', $this->estimate->id)->orderBy('id')->pluck('id')->all());
        self::assertSame(32004, \Illuminate\Support\Facades\DB::table('estimate_finance_condition_versions')->where('estimate_id', $this->estimate->id)->count());
        self::assertSame('200.00', EstimateFinanceAllocation::query()->where('key', $command['lines'][0]['key'])->sole()->amount_with_vat);
    }

    public function test_legacy_projection_batches_invalidate_revision_once_per_statement(): void
    {
        $revision = (int) $this->estimate->fresh()->finance_revision;
        $rows = [];
        foreach ([$this->customer, $this->contractor] as $contract) {
            $rows[] = ['contract_id' => $contract->id, 'estimate_id' => $this->estimate->id,
                'estimate_item_id' => $this->item->id, 'quantity' => '100', 'amount' => '1000000'];
        }
        ContractEstimateItem::query()->insert($rows);
        self::assertSame($revision + 1, (int) $this->estimate->fresh()->finance_revision);
        ContractEstimateItem::query()->where('estimate_id', $this->estimate->id)->update(['quantity' => '50']);
        self::assertSame($revision + 2, (int) $this->estimate->fresh()->finance_revision);
        ContractEstimateItem::query()->where('estimate_id', $this->estimate->id)->delete();
        self::assertSame($revision + 3, (int) $this->estimate->fresh()->finance_revision);
    }

    public function test_batch_save_rolls_back_both_sides_when_history_write_fails(): void
    {
        $command = $this->command([$this->line($this->customer, '100', '1000000'), $this->line($this->contractor, '100', '800000')]);
        \Illuminate\Support\Facades\DB::listen(function ($query): void {
            if (str_starts_with($query->sql, 'insert into "estimate_finance_condition_versions"')) {
                throw new \RuntimeException('history_write_failed');
            }
        });
        try {
            $this->save($command);
            self::fail('Ошибка истории должна отменить запись');
        } catch (\RuntimeException $error) {
            self::assertSame('history_write_failed', $error->getMessage());
        }
        self::assertSame(0, EstimateFinanceAllocation::query()->where('estimate_id', $this->estimate->id)->count());
        self::assertSame(0, ContractEstimateItem::query()->where('estimate_id', $this->estimate->id)->count());
        self::assertSame(0, \Illuminate\Support\Facades\DB::table('estimate_finance_condition_versions')->where('estimate_id', $this->estimate->id)->count());
        self::assertSame($command['revision'], (int) $this->estimate->fresh()->finance_revision);
        self::assertSame(0, \Illuminate\Support\Facades\DB::table('estimate_finance_mutations')->where('estimate_id', $this->estimate->id)->count());
    }

    public function test_uppercase_allocation_key_cannot_replace_another_estimate_conditions(): void
    {
        $line = $this->line($this->contractor, '100', '1000000');
        $this->save($this->command([$line]));
        $original = EstimateFinanceAllocation::query()->where('key', $line['key'])->sole()->getAttributes();
        $this->estimate = Estimate::query()->create(['organization_id' => $this->estimate->organization_id,
            'project_id' => $this->estimate->project_id, 'number' => 'FIN-OTHER', 'name' => 'Другая смета', 'estimate_date' => '2026-09-09']);
        $this->item = EstimateItem::query()->create(['estimate_id' => $this->estimate->id, 'position_number' => '1',
            'name' => 'Работа', 'item_type' => 'work', 'quantity' => '100', 'quantity_total' => '100',
            'unit_price' => '10000', 'total_amount' => '1000000', 'is_manual' => true]);
        $line['key'] = strtoupper($line['key']);
        $line['target_key'] = 'i:'.$this->item->id;
        $revision = (int) $this->estimate->fresh()->finance_revision;
        try {
            $this->save($this->command([$line]));
            self::fail('Ключ другой сметы недоступен');
        } catch (ValidationException) {
            self::assertSame($original, EstimateFinanceAllocation::query()->where('key', strtolower($line['key']))->sole()->getAttributes());
        }
        self::assertSame($revision, (int) $this->estimate->fresh()->finance_revision);
    }

    public function test_contract_reads_financial_conditions_instead_of_stale_projection_amounts(): void
    {
        $this->save($this->command([$this->line($this->contractor, '100', '1000000')]));
        $projection = ContractEstimateItem::query()->where('contract_id', $this->contractor->id)->sole();
        $projection->update(['amount' => '180000000', 'amount_without_vat' => '180000000']);
        $this->app->forgetInstance(ContractEstimateService::class);
        $contracts = app(ContractEstimateService::class);
        $coverage = app(\App\BusinessModules\Features\BudgetEstimates\Services\Integration\EstimateCoverageService::class);

        self::assertSame(1000000.0, $contracts->calculateContractEstimateTotal($this->contractor));
        self::assertSame(1000000.0, $contracts->getSummary($this->contractor)['total_amount']);
        $visible = $contracts->getItemsForContract($this->contractor, $this->estimate->id)->sole();
        self::assertSame($projection->id, $visible->id);
        self::assertSame('1000000.00', $visible->amount);
        $visible->setRelation('estimateItem', null);
        $serialized = (new \App\Http\Resources\Api\V1\Admin\Contract\ContractEstimateItemResource($visible))->toArray(request());
        self::assertSame(10000.0, $serialized['contract_unit_price']);
        self::assertSame(1000000.0, $coverage->getCoverageForEstimate($this->estimate)['primary_contract']['linked_amount']);
        self::assertSame(1000000.0, $coverage->getContractCoverageSummary($this->contractor)['summary']['linked_amount']);
        self::assertSame('180000000.00', $projection->fresh()->amount);
    }

    public function test_contract_coverage_preserves_unknown_financial_amounts(): void
    {
        $this->save($this->command([$this->line($this->contractor, '100', '1000000')]));
        EstimateFinanceAllocation::query()->where('contract_id', $this->contractor->id)
            ->update(['amount_with_vat' => null, 'amount_without_vat' => null]);
        $coverage = app(\App\BusinessModules\Features\BudgetEstimates\Services\Integration\EstimateCoverageService::class)
            ->getContractCoverageSummary($this->contractor);

        foreach (['linked_amount', 'coverage_percent', 'uncovered_amount', 'overcovered_amount', 'average_linked_item_amount'] as $field) {
            self::assertNull($coverage['summary'][$field]);
        }
        foreach (['amount', 'amount_without_vat', 'average_amount', 'max_amount'] as $field) {
            self::assertNull($coverage['linked_estimates'][0]['linked_items_summary'][$field]);
        }
        self::assertSame(1, $coverage['summary']['linked_items_count']);
        $coverageService = app(\App\BusinessModules\Features\BudgetEstimates\Services\Integration\EstimateCoverageService::class);
        self::assertNull($coverageService->getCoverageForEstimate($this->estimate)['primary_contract']['linked_amount']);
        $validation = $coverageService->validateContractAmount($this->estimate, $this->contractor);
        foreach (['valid', 'covered_amount', 'difference', 'percentage_difference'] as $field) {
            self::assertNull($validation[$field]);
        }
        self::assertSame(1000000.0, $validation['contract_amount']);
        self::assertStringContainsString('укажите договорные цены', $validation['message']);
        $contracts = app(ContractEstimateService::class);
        self::assertNull($contracts->calculateContractEstimateTotal($this->contractor));
        self::assertNull($contracts->getSummary($this->contractor)['total_amount']);
        self::assertNull($contracts->getSummary($this->contractor)['by_estimate'][0]['total_amount']);
        $visible = $contracts->getItemsForContract($this->contractor, $this->estimate->id)->sole();
        $visible->setRelation('estimateItem', null);
        $serialized = (new \App\Http\Resources\Api\V1\Admin\Contract\ContractEstimateItemResource($visible))->toArray(request());
        self::assertNull($serialized['amount']);
        self::assertNull($serialized['contract_unit_price']);
        self::assertNull(\App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceAmounts::sum([
            ['amount' => '1000000'], ['amount' => null],
        ], 'amount'));
        self::assertSame(0.0, \App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceAmounts::sum([
            ['amount' => '0'],
        ], 'amount'));
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

    public function test_edit_preserves_allocation_and_projection_identifiers(): void
    {
        $income = $this->line($this->customer, '100', '1000000');
        $cost = $this->line($this->contractor, '100', '800000');
        $this->save($this->command([$income, $cost]));
        $allocation = EstimateFinanceAllocation::query()->where('key', $cost['key'])->firstOrFail();
        $incomeId = EstimateFinanceAllocation::query()->where('key', $income['key'])->value('id');
        $linkId = $allocation->contract_estimate_item_id;
        $cost['amount'] = '750000';
        $command = $this->command([$income, $cost]);
        $this->save($command);

        $updated = EstimateFinanceAllocation::query()->where('key', $cost['key'])->firstOrFail();
        self::assertSame($allocation->id, $updated->id);
        self::assertSame($linkId, $updated->contract_estimate_item_id);
        self::assertSame($incomeId, EstimateFinanceAllocation::query()->where('key', $income['key'])->value('id'));
        self::assertSame('750000.00', $updated->amount_with_vat);
        self::assertSame('750000.00', ContractEstimateItem::query()->findOrFail($linkId)->amount);
        self::assertTrue($this->save($command)['replayed']);
        self::assertSame(2, EstimateFinanceAllocation::query()->where('estimate_id', $this->estimate->id)->count());
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

    public function test_accepted_volume_cannot_be_removed_or_reduced_and_annulment_releases_it(): void
    {
        $line = $this->line($this->contractor, '100', '800000');
        $this->save($this->command([$line]));
        $act = \App\Models\ContractPerformanceAct::query()->create(['contract_id' => $this->contractor->id,
            'project_id' => $this->estimate->project_id, 'act_document_number' => 'ACCEPTED-1', 'act_date' => '2026-09-12',
            'amount' => '120000', 'currency' => 'RUB', 'status' => 'draft', 'created_by_user_id' => $this->actor->id]);
        \App\Models\PerformanceActLine::query()->create(['performance_act_id' => $act->id, 'estimate_item_id' => $this->item->id,
            'line_type' => 'manual', 'title' => 'Бетон', 'quantity' => '15', 'unit_price' => '8000', 'amount' => '120000',
            'currency' => 'RUB', 'manual_reason' => 'Принятые работы', 'created_by' => $this->actor->id]);
        $act->update(['status' => 'signed', 'is_approved' => true]);
        $before = $act->fresh()->getAttributes();
        $this->app->forgetInstance(ContractEstimateService::class);
        try {
            app(ContractEstimateService::class)->detachItems($this->contractor, [$this->item->id], $this->actor);
            self::fail('Legacy detach must preserve accepted volume');
        } catch (ValidationException) {
            self::assertSame($before, $act->fresh()->getAttributes());
            self::assertDatabaseCount('estimate_finance_allocations', 1);
        }
        $reduced = $line;
        $reduced['quantity'] = '14';
        foreach ([[], [$reduced]] as $lines) {
            try {
                $this->save($this->command($lines));
                self::fail('Accepted volume was removed');
            } catch (ValidationException) {
                self::assertSame('100.00000000', EstimateFinanceAllocation::query()->where('key', $line['key'])->firstOrFail()->quantity);
                self::assertDatabaseCount('estimate_finance_condition_versions', 1);
            }
        }
        $reduced['quantity'] = '15';
        $reduced['amount'] = '120000';
        $this->save($this->command([$reduced]));
        self::assertSame($before, $act->fresh()->getAttributes());
        $act->update(['status' => 'annulled', 'annulled_at' => now()]);
        $this->save($this->command([]));
        self::assertDatabaseCount('estimate_finance_allocations', 0);
    }

    public function test_draft_act_does_not_prevent_removing_planned_allocation(): void
    {
        $line = $this->line($this->contractor, '100', '800000');
        $this->save($this->command([$line]));
        $act = \App\Models\ContractPerformanceAct::query()->create(['contract_id' => $this->contractor->id,
            'project_id' => $this->estimate->project_id, 'act_document_number' => 'DRAFT-1', 'act_date' => '2026-09-12',
            'amount' => '120000', 'currency' => 'RUB', 'status' => 'draft', 'created_by_user_id' => $this->actor->id]);
        \App\Models\PerformanceActLine::query()->create(['performance_act_id' => $act->id, 'estimate_item_id' => $this->item->id,
            'line_type' => 'manual', 'title' => 'Бетон', 'quantity' => '15', 'unit_price' => '8000', 'amount' => '120000',
            'currency' => 'RUB', 'manual_reason' => 'Черновик', 'created_by' => $this->actor->id]);
        $this->save($this->command([]));
        self::assertDatabaseCount('estimate_finance_allocations', 0);
    }

    public function test_accepted_volume_uses_lines_once_and_falls_back_to_legacy_acts(): void
    {
        $work = \App\Models\CompletedWork::query()->create(['organization_id' => $this->estimate->organization_id,
            'project_id' => $this->estimate->project_id, 'estimate_item_id' => $this->item->id, 'contract_id' => $this->contractor->id,
            'user_id' => $this->actor->id, 'quantity' => '30', 'completed_quantity' => '30', 'price' => '8000',
            'total_amount' => '240000', 'completion_date' => '2026-09-09', 'status' => 'confirmed', 'description' => 'Бетонирование']);
        foreach (['LINES', 'LEGACY'] as $kind) {
            $act = \App\Models\ContractPerformanceAct::query()->create(['contract_id' => $this->contractor->id,
                'project_id' => $this->estimate->project_id, 'act_document_number' => $kind, 'act_date' => '2026-09-12',
                'amount' => '120000', 'currency' => 'RUB', 'status' => 'draft', 'created_by_user_id' => $this->actor->id]);
            $act->completedWorks()->attach($work->id, ['included_quantity' => '15', 'included_amount' => '120000', 'currency' => 'RUB']);
            if ($kind === 'LINES') {
                \App\Models\PerformanceActLine::query()->create(['performance_act_id' => $act->id, 'estimate_item_id' => $this->item->id,
                    'line_type' => 'manual', 'title' => 'Бетон', 'quantity' => '15', 'unit_price' => '8000', 'amount' => '120000',
                    'currency' => 'RUB', 'manual_reason' => 'Принятые работы', 'created_by' => $this->actor->id]);
            }
            $act->update(['status' => 'approved', 'is_approved' => true]);
        }
        $accepted = app(\App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceAcceptedVolume::class);
        $quantities = $accepted->quantities($this->estimate, [$this->contractor->id], [$this->item->id]);
        self::assertSame(0, \App\BusinessModules\Features\BudgetEstimates\Services\Finance\FinanceDecimal::compare(
            $quantities[$this->contractor->id.':'.$this->item->id], '30'));
        $otherOrganization = clone $this->estimate;
        $otherOrganization->organization_id = 0;
        self::assertSame([], $accepted->quantities($otherOrganization, [$this->contractor->id], [$this->item->id]));
    }

    public function test_act_basis_uses_contract_conditions_and_keeps_previous_snapshot(): void
    {
        $line = $this->line($this->contractor, '100', '800000');
        $line['vat_mode'] = 'exclusive';
        $line['price_basis'] = 'without_vat';
        $line['vat_rate'] = '5';
        $this->save($this->command([$line]));
        $work = new \App\Models\CompletedWork;
        $work->setRelation('estimateItem', $this->item);
        $basisService = app(\App\Services\Acting\PerformanceActFinancialBasisService::class);
        $first = $basisService->forCompletedWork($work, $this->contractor, 15);
        self::assertSame('8400.00', $first['unit_price']);
        self::assertSame('5.00', $first['vat_rate']);
        self::assertSame('contract_conditions', $first['snapshot']['basis_type']);
        self::assertSame($line['key'], $first['snapshot']['allocation_key']);
        self::assertSame(1, $first['snapshot']['condition_version']);
        $line['amount'] = '900000';
        $this->save($this->command([$line]));
        $second = $basisService->forCompletedWork($work, $this->contractor, 15);
        self::assertSame('9450.00', $second['unit_price']);
        self::assertSame(2, $second['snapshot']['condition_version']);
        self::assertSame('8400.00', $first['snapshot']['unit_price_with_vat']);
    }

    public function test_act_basis_requires_selection_between_contract_allocations(): void
    {
        $first = $this->line($this->contractor, '60', '480000');
        $second = $this->line($this->contractor, '40', '360000');
        $this->save($this->command([$first, $second]));
        $basis = app(\App\Services\Acting\PerformanceActContractBasisService::class);
        self::assertSame($second['key'], $basis->resolve($this->item, $this->contractor, $second['key'])['snapshot']['allocation_key']);
        $this->expectException(\App\Exceptions\BusinessLogicException::class);
        $basis->resolve($this->item, $this->contractor);
    }

    public function test_act_basis_does_not_fall_back_to_estimate_for_unknown_contract_tax(): void
    {
        $line = $this->line($this->contractor, '100', '800000');
        $line['vat_mode'] = 'unknown';
        $line['price_basis'] = 'unknown';
        $line['vat_rate'] = null;
        $line['composition_confirmed'] = false;
        $this->save($this->command([$line]));
        $this->expectException(\App\Exceptions\BusinessLogicException::class);
        app(\App\Services\Acting\PerformanceActContractBasisService::class)->resolve($this->item, $this->contractor);
    }

    public function test_approval_rejects_changed_or_deleted_contract_conditions_without_changing_act(): void
    {
        $line = $this->line($this->contractor, '100', '800000');
        $this->save($this->command([$line]));
        $basis = app(\App\Services\Acting\PerformanceActContractBasisService::class)->resolve($this->item, $this->contractor);
        $act = \App\Models\ContractPerformanceAct::query()->create(['contract_id' => $this->contractor->id,
            'project_id' => $this->estimate->project_id, 'act_document_number' => 'VERSION-GUARD', 'act_date' => '2026-09-12',
            'amount' => '8000', 'currency' => 'RUB', 'status' => 'draft', 'is_approved' => false, 'created_by_user_id' => $this->actor->id]);
        $actLine = \App\Models\PerformanceActLine::query()->create(['performance_act_id' => $act->id, 'estimate_item_id' => $this->item->id,
            'line_type' => 'manual', 'title' => 'Бетон', 'quantity' => '1', 'unit_price' => '8000', 'amount' => '8000',
            'currency' => 'RUB', 'manual_reason' => 'Принятые работы', 'basis_snapshot' => $basis['snapshot'], 'created_by' => $this->actor->id]);
        $act->update(['status' => 'pending_approval']);
        app(\App\Services\Acting\PerformanceActConditionGuard::class)->assertCurrent($act, $this->contractor);
        $before = $act->fresh()->getAttributes();
        $beforeLine = $actLine->fresh()->getAttributes();
        $line['amount'] = '900000';
        foreach ([[$line], []] as $replacement) {
            $this->save($this->command($replacement));
            try {
                app(\App\Services\ActReport\ActReportWorkflowService::class)->approve($act, $this->actor->id);
                self::fail('Obsolete contract conditions were approved');
            } catch (\App\Exceptions\BusinessLogicException $exception) {
                self::assertSame(409, $exception->getCode());
                self::assertSame($before, $act->fresh()->getAttributes());
                self::assertSame($beforeLine, $actLine->fresh()->getAttributes());
            }
        }
    }

    public function test_accepted_allocation_cannot_be_replaced_by_another_key_of_same_contract(): void
    {
        $first = $this->line($this->contractor, '60', '480000');
        $second = $this->line($this->contractor, '40', '320000');
        $this->save($this->command([$first, $second]));
        $basis = app(\App\Services\Acting\PerformanceActContractBasisService::class)->resolve($this->item, $this->contractor, $first['key']);
        $act = \App\Models\ContractPerformanceAct::query()->create(['contract_id' => $this->contractor->id,
            'project_id' => $this->estimate->project_id, 'act_document_number' => 'ALLOCATION-FACT', 'act_date' => '2026-09-12',
            'amount' => '120000', 'currency' => 'RUB', 'status' => 'draft', 'is_approved' => false, 'created_by_user_id' => $this->actor->id]);
        \App\Models\PerformanceActLine::query()->create(['performance_act_id' => $act->id, 'estimate_item_id' => $this->item->id,
            'line_type' => 'manual', 'title' => 'Бетон', 'quantity' => '15', 'unit_price' => '8000', 'amount' => '120000',
            'currency' => 'RUB', 'manual_reason' => 'Принятые работы', 'basis_snapshot' => $basis['snapshot'], 'created_by' => $this->actor->id]);
        $act->update(['status' => 'approved', 'is_approved' => true]);
        $replacement = $second;
        $replacement['quantity'] = '100';
        $replacement['amount'] = '800000';
        $reduced = $first;
        $reduced['quantity'] = '14';
        foreach ([[$replacement], [$reduced, $second]] as $lines) {
            try {
                $this->save($this->command($lines));
                self::fail('Accepted allocation was reassigned');
            } catch (ValidationException) {
                self::assertSame('60.00000000', EstimateFinanceAllocation::query()->where('key', $first['key'])->firstOrFail()->quantity);
                self::assertDatabaseCount('estimate_finance_condition_versions', 2);
            }
        }
        $act->update(['status' => 'annulled', 'annulled_at' => now()]);
        $this->save($this->command([$replacement]));
        self::assertDatabaseCount('estimate_finance_allocations', 1);
    }

    public function test_new_price_applies_only_to_remaining_volume_and_total_keeps_accepted_cost(): void
    {
        $line = $this->line($this->contractor, '100', '600000');
        $line['vat_mode'] = 'included';
        $line['vat_rate'] = '20';
        $line['price_basis'] = 'with_vat';
        $this->save($this->command([$line]));
        $basisService = app(\App\Services\Acting\PerformanceActContractBasisService::class);
        $basis = $basisService->resolve($this->item, $this->contractor);
        $act = \App\Models\ContractPerformanceAct::query()->create(['contract_id' => $this->contractor->id,
            'project_id' => $this->estimate->project_id, 'act_document_number' => 'REMAINDER', 'act_date' => '2026-09-12',
            'amount' => '120000', 'currency' => 'RUB', 'status' => 'draft', 'is_approved' => false, 'created_by_user_id' => $this->actor->id]);
        $actLine = \App\Models\PerformanceActLine::query()->create(['performance_act_id' => $act->id, 'estimate_item_id' => $this->item->id,
            'line_type' => 'manual', 'title' => 'Бетон', 'quantity' => '20', 'unit_price' => '6000', 'amount' => '120000',
            'currency' => 'RUB', 'manual_reason' => 'Принятые работы', 'basis_snapshot' => $basis['snapshot'], 'created_by' => $this->actor->id]);
        $act->update(['status' => 'approved', 'is_approved' => true]);
        $before = $actLine->fresh()->getAttributes();
        self::assertSame('120000.00', $this->report()['rows'][0]['allocations'][0]['accepted_basis']['amount_with_vat']);
        $line['method'] = 'unit';
        $line['unit_price'] = '12000';
        foreach ([1, 2] as $attempt) {
            $this->save($this->command([$line]));
            $saved = EstimateFinanceAllocation::query()->where('key', $line['key'])->firstOrFail();
            self::assertSame('1080000.00', $saved->amount_with_vat);
            self::assertSame('900000.00', $saved->amount_without_vat);
            self::assertSame('120000.00', $saved->accepted_basis['amount_with_vat']);
            self::assertSame('960000.00', $saved->condition_basis['amount_with_vat']);
            self::assertSame('12000.00', $basisService->resolve($this->item, $this->contractor)['unit_price']);
        }
        $line['method'] = 'total';
        $line['amount'] = '1000000';
        $line['unit_price'] = null;
        $this->save($this->command([$line]));
        $saved = EstimateFinanceAllocation::query()->where('key', $line['key'])->firstOrFail();
        self::assertSame('1000000.00', $saved->amount_with_vat);
        self::assertSame('880000.00', $saved->condition_basis['amount_with_vat']);
        self::assertSame('11000.00', $basisService->resolve($this->item, $this->contractor)['unit_price']);
        self::assertSame($before, $actLine->fresh()->getAttributes());
        self::assertSame('1000000.00', ContractEstimateItem::query()->where('contract_id', $this->contractor->id)->firstOrFail()->amount);
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturnUsing(
            static fn ($actor, string $permission): bool => ! in_array($permission, ['act_reports.view', 'contracts.performance_acts.view'], true));
        $restricted = app(EstimateFinanceService::class);
        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        try {
            $report = $restricted->report($this->actor, $this->estimate->project_id, $this->estimate->id);
            self::assertFalse($report['can_view_execution']);
            self::assertNull($report['rows'][0]['allocations'][0]['accepted_basis']);
            self::assertNull($report['rows'][0]['allocations'][0]['condition_basis']);
            foreach (\Illuminate\Support\Facades\DB::getQueryLog() as $query) {
                self::assertStringNotContainsString('performance_act', $query['query']);
            }
        } finally {
            \Illuminate\Support\Facades\DB::disableQueryLog();
            \Illuminate\Support\Facades\DB::flushQueryLog();
        }
        $history = $restricted->history($this->actor, $this->estimate->project_id, $this->estimate->id);
        foreach ($history['data'] as $entry) {
            self::assertArrayNotHasKey('accepted_basis', $entry['after']);
            self::assertArrayNotHasKey('condition_basis', $entry['after']);
        }
    }

    public function test_legacy_detach_preserves_other_contract_conditions(): void
    {
        $cost = $this->line($this->contractor, '100', '800000');
        $income = $this->line($this->customer, '100', '1000000');
        $this->save($this->command([$cost, $income]));
        $incomeId = EstimateFinanceAllocation::query()->where('key', $income['key'])->firstOrFail()->id;
        $this->app->forgetInstance(ContractEstimateService::class);
        app(ContractEstimateService::class)->detachItems($this->contractor, [$this->item->id], $this->actor);
        self::assertDatabaseMissing('estimate_finance_allocations', ['key' => $cost['key']]);
        $retained = EstimateFinanceAllocation::query()->findOrFail($incomeId);
        self::assertSame($income['key'], $retained->key);
        self::assertSame('1000000.00', $retained->amount_with_vat);
        self::assertSame(0.0, app(ContractEstimateService::class)->calculateContractEstimateTotal($this->contractor));
        self::assertSame(0, ContractEstimateItem::query()->countedInCoverage()->where('contract_id', $this->contractor->id)->count());
        app(\App\BusinessModules\Features\BudgetEstimates\Services\Integration\EstimateCoverageService::class)
            ->detachCoverage($this->customer, $this->estimate, $this->actor);
        self::assertDatabaseCount('estimate_finance_allocations', 0);
    }

    public function test_attach_adapter_keeps_contract_price_when_estimate_changes(): void
    {
        $this->app->forgetInstance(ContractEstimateService::class);
        $adapter = app(ContractEstimateService::class);
        $adapter->attachItems($this->customer, $this->estimate, [$this->item->id], true, $this->actor, '20');
        $saved = EstimateFinanceAllocation::query()->firstOrFail();
        self::assertSame('1000000.00', $saved->amount_without_vat);
        self::assertSame('1200000.00', $saved->amount_with_vat);
        $version = $saved->condition_version;
        $this->item->update(['total_amount' => '2000000']);
        $adapter->attachItems($this->customer, $this->estimate, [$this->item->id], true, $this->actor, '5');
        self::assertDatabaseCount('estimate_finance_allocations', 1);
        self::assertSame('1200000.00', $saved->fresh()->amount_with_vat);
        self::assertSame($version, $saved->fresh()->condition_version);
    }

    public function test_sync_keeps_existing_conditions_of_deeply_nested_positions(): void
    {
        $child = $this->item->replicate();
        $child->forceFill(['position_number' => '1.1', 'name' => 'Вложенная работа', 'parent_work_id' => $this->item->id])->save();
        $leaf = $this->item->replicate();
        $leaf->forceFill(['position_number' => '1.1.1', 'name' => 'Вложенный материал', 'parent_work_id' => $child->id])->save();
        $rootLine = $this->line($this->contractor, '100', '1000000');
        $leafLine = $this->line($this->contractor, '100', '100000');
        $leafLine['target_key'] = 'i:'.$leaf->id;
        $command = $this->command([$rootLine, $leafLine]) + ['confirm_resource_changes' => true];
        $command['target_keys'][] = 'i:'.$child->id;
        $command['target_keys'][] = $leafLine['target_key'];
        $this->save($command);
        $before = EstimateFinanceAllocation::query()->where('key', $leafLine['key'])->firstOrFail()->getAttributes();
        $revision = (int) $this->estimate->fresh()->finance_revision;
        $this->app->forgetInstance(ContractEstimateService::class);
        app(ContractEstimateService::class)->syncItems($this->contractor, $this->estimate, [$this->item->id], false, $this->actor);
        self::assertSame($before, EstimateFinanceAllocation::query()->where('key', $leafLine['key'])->firstOrFail()->getAttributes());
        self::assertSame($revision, (int) $this->estimate->fresh()->finance_revision);
        self::assertDatabaseCount('estimate_finance_allocations', 2);
    }

    public function test_initial_amount_matches_attached_roots_and_explicit_tax(): void
    {
        $child = $this->item->replicate();
        $child->forceFill(['position_number' => '1.1', 'parent_work_id' => $this->item->id, 'total_amount' => '400000'])->save();
        $excluded = $this->item->replicate();
        $excluded->forceFill(['position_number' => '2', 'is_not_accounted' => true, 'total_amount' => '154253129.99'])->save();
        $this->estimate->update(['vat_rate' => '5']);
        $this->app->forgetInstance(ContractEstimateService::class);
        $service = app(ContractEstimateService::class);
        $ids = [$this->item->id, $child->id, $excluded->id];
        $preview = $this->finance->preview($this->actor, $this->estimate->project_id, $this->estimate->id,
            ['preview_operation' => 'source_amount', 'item_ids' => $ids]);
        self::assertSame('1000000.00', $preview['amount_without_vat']);
        self::assertSame(1, $preview['items_count']);
        self::assertDatabaseCount('estimate_finance_allocations', 0);
        self::assertSame(1000000.0, $service->calculateItemsTotal($this->estimate, $ids));
        self::assertSame(1200000.0, $service->calculateItemsTotal($this->estimate, $ids, true, '20'));
        $service->attachItems($this->customer, $this->estimate, $ids, true, $this->actor, '20');
        self::assertSame(1200000.0, $service->calculateContractEstimateTotal($this->customer));
        self::assertDatabaseCount('estimate_finance_allocations', 1);
        $this->expectException(ValidationException::class);
        $service->calculateItemsTotal($this->estimate, $ids, true);
    }

    public function test_sync_rolls_back_removed_conditions_when_new_tax_is_missing(): void
    {
        $line = $this->line($this->customer, '100', '1000000');
        $this->save($this->command([$line]));
        $next = $this->item->replicate();
        $next->forceFill(['position_number' => '2', 'name' => 'Следующая работа', 'total_amount' => '200000'])->save();
        $before = EstimateFinanceAllocation::query()->where('key', $line['key'])->firstOrFail()->getAttributes();
        $revision = (int) $this->estimate->fresh()->finance_revision;
        $this->app->forgetInstance(ContractEstimateService::class);
        $service = app(ContractEstimateService::class);
        try {
            $service->syncItems($this->customer, $this->estimate, [$next->id], true, $this->actor);
            self::fail('Unknown VAT must reject the replacement');
        } catch (ValidationException) {
            self::assertSame($before, EstimateFinanceAllocation::query()->where('key', $line['key'])->firstOrFail()->getAttributes());
            self::assertSame($revision, (int) $this->estimate->fresh()->finance_revision);
        }
        $service->syncItems($this->customer, $this->estimate, [$next->id], true, $this->actor, '20');
        self::assertDatabaseMissing('estimate_finance_allocations', ['key' => $line['key']]);
        $saved = EstimateFinanceAllocation::query()->where('estimate_item_id', $next->id)->firstOrFail();
        self::assertSame('240000.00', $saved->amount_with_vat);
        self::assertSame(240000.0, $service->calculateContractEstimateTotal($this->customer));
    }

    public function test_legacy_detach_removes_resource_conditions_and_preserves_parent_income(): void
    {
        $resource = EstimateItemResource::query()->create(['estimate_item_id' => $this->item->id, 'resource_type' => 'material',
            'name' => 'Материал', 'total_quantity' => '100', 'quantity_per_unit' => '1', 'total_amount' => '100000']);
        $income = $this->line($this->customer, '100', '1000000');
        $cost = $this->line($this->contractor, '100', '100000');
        $cost['target_key'] = 'r:'.$resource->id;
        $command = $this->command([$income, $cost]) + ['confirm_resource_changes' => true];
        $command['target_keys'][] = $cost['target_key'];
        $this->save($command);
        $this->app->forgetInstance(ContractEstimateService::class);
        app(\App\BusinessModules\Features\BudgetEstimates\Services\Integration\EstimateCoverageService::class)
            ->detachCoverage($this->contractor, $this->estimate, $this->actor);
        self::assertDatabaseMissing('estimate_finance_allocations', ['key' => $cost['key']]);
        self::assertSame('1000000.00', EstimateFinanceAllocation::query()->where('key', $income['key'])->firstOrFail()->amount_with_vat);
    }

    public function test_legacy_vat_command_updates_financial_source_and_preserves_ids(): void
    {
        $cost = $this->line($this->contractor, '100', '800000');
        $cost['price_basis'] = 'without_vat';
        $cost['vat_mode'] = 'exclusive';
        $cost['vat_rate'] = '5';
        $income = $this->line($this->customer, '100', '1000000');
        $this->save($this->command([$cost, $income]));
        $this->estimate->update(['vat_rate' => '0']);
        $allocationId = EstimateFinanceAllocation::query()->where('key', $cost['key'])->firstOrFail()->id;
        $linkId = ContractEstimateItem::query()->where('contract_id', $this->contractor->id)->firstOrFail()->id;
        $this->app->forgetInstance(ContractEstimateService::class);
        $service = app(ContractEstimateService::class);
        foreach (['20', null] as $rate) {
            $service->updateCoverageVat($this->contractor, $this->estimate, true, $this->actor, $rate);
            $saved = EstimateFinanceAllocation::query()->where('key', $cost['key'])->firstOrFail();
            self::assertSame($allocationId, $saved->id);
            self::assertSame('960000.00', $saved->amount_with_vat);
            self::assertSame('800000.00', $saved->amount_without_vat);
            self::assertSame('960000.00', ContractEstimateItem::query()->findOrFail($linkId)->amount);
            self::assertSame('1000000.00', EstimateFinanceAllocation::query()->where('key', $income['key'])->firstOrFail()->amount_with_vat);
        }
        $service->updateCoverageVat($this->contractor, $this->estimate, false, $this->actor);
        self::assertSame('800000.00', ContractEstimateItem::query()->findOrFail($linkId)->amount);
        self::assertSame('none', EstimateFinanceAllocation::query()->findOrFail($allocationId)->vat_mode);
        $revision = (int) $this->estimate->fresh()->finance_revision;
        try {
            $service->updateCoverageVat($this->contractor, $this->estimate, true, $this->actor, '20', $revision - 1);
            self::fail('Stale VAT edit must be rejected');
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException) {
            self::assertSame($revision, (int) $this->estimate->fresh()->finance_revision);
            self::assertSame('800000.00', ContractEstimateItem::query()->findOrFail($linkId)->amount);
            self::assertSame('none', EstimateFinanceAllocation::query()->findOrFail($allocationId)->vat_mode);
        }
        $mutation = (string) \Illuminate\Support\Str::uuid();
        $service->updateCoverageVat($this->contractor, $this->estimate, true, $this->actor, '20', $revision, $mutation);
        $savedRevision = (int) $this->estimate->fresh()->finance_revision;
        $savedVersion = EstimateFinanceAllocation::query()->findOrFail($allocationId)->condition_version;
        $service->updateCoverageVat($this->contractor, $this->estimate, true, $this->actor, '20', $revision, $mutation);
        self::assertSame($savedRevision, (int) $this->estimate->fresh()->finance_revision);
        self::assertSame($savedVersion, EstimateFinanceAllocation::query()->findOrFail($allocationId)->condition_version);
        try {
            $service->updateCoverageVat($this->contractor, $this->estimate, true, $this->actor, '5', $revision, $mutation);
            self::fail('Reused mutation must reject changed conditions');
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException) {
            self::assertSame($savedRevision, (int) $this->estimate->fresh()->finance_revision);
        }
        self::assertSame('960000.00', ContractEstimateItem::query()->findOrFail($linkId)->amount);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $service->updateCoverageVat($this->contractor, $this->estimate, true);
    }

    public function test_condition_history_preserves_snapshots_after_edit_and_delete(): void
    {
        $line = $this->line($this->customer, '100', '1000000');
        $this->save($this->command([$line]));
        $line['amount'] = '1200000';
        $line['condition_version'] = 1;
        $edit = $this->command([$line]);
        $this->save($edit);
        $this->save($edit);
        $this->save($this->command([]));
        $history = $this->finance->history($this->actor, $this->estimate->project_id, $this->estimate->id);
        self::assertFalse($history['has_more']);
        self::assertCount(3, $history['data']);
        self::assertSame(['created', 'updated', 'deleted'], array_column($history['data'], 'action'));
        self::assertSame([1, 2, 3], array_column($history['data'], 'condition_version'));
        self::assertSame('1000000.00', $history['data'][1]['before']['amount_with_vat']);
        self::assertSame('1200000.00', $history['data'][1]['after']['amount_with_vat']);
        self::assertSame('1200000.00', $history['data'][2]['before']['amount_with_vat']);
        self::assertNull($history['data'][2]['after']);
        self::assertSame($this->actor->id, $history['data'][1]['actor_id']);
        self::assertDatabaseCount('estimate_finance_allocations', 0);
        $this->expectException(ConflictHttpException::class);
        $this->save($this->command([$line]));
    }

    public function test_stale_condition_version_is_rejected_even_with_current_estimate_revision(): void
    {
        $line = $this->line($this->customer, '100', '1000000');
        $this->save($this->command([$line]));
        $line['condition_version'] = 0;
        try {
            $this->save($this->command([$line]));
            self::fail('Stale condition version was accepted');
        } catch (ConflictHttpException) {
            self::assertDatabaseCount('estimate_finance_condition_versions', 1);
        }
    }

    public function test_condition_history_respects_view_permissions(): void
    {
        $this->save($this->command([$this->line($this->customer, '100', '1000000')]));
        $this->mock(AuthorizationService::class)->shouldReceive('can')->andReturn(false);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(EstimateFinanceService::class)->history($this->actor, $this->estimate->project_id, $this->estimate->id);
    }

    public function test_explicit_vat_modes_are_independent_of_estimate_and_repeated_save(): void
    {
        $this->estimate->update(['vat_rate' => '0']);
        $income = $this->line($this->customer, '100', '1000000');
        $income['vat_mode'] = 'exclusive';
        $income['price_basis'] = 'without_vat';
        $income['vat_rate'] = '20';
        $cost = $this->line($this->contractor, '100', '840000');
        $cost['vat_mode'] = 'included';
        $cost['vat_rate'] = '5';
        $this->save($this->command([$income, $cost]));
        $this->save($this->command([$income, $cost]));
        $rows = EstimateFinanceAllocation::query()->where('estimate_id', $this->estimate->id)->get()->keyBy('side');
        self::assertSame('1000000.00', $rows['revenue']->amount_without_vat);
        self::assertSame('1200000.00', $rows['revenue']->amount_with_vat);
        self::assertSame('800000.00', $rows['cost']->amount_without_vat);
        self::assertSame('840000.00', $rows['cost']->amount_with_vat);
        self::assertSame('exclusive', $rows['revenue']->vat_mode);
        self::assertSame('included', $rows['cost']->vat_mode);
    }

    public function test_without_vat_is_distinct_from_zero_rate_and_has_known_margin(): void
    {
        $income = $this->line($this->customer, '100', '1000000');
        $income['vat_mode'] = 'none';
        $income['price_basis'] = 'without_vat';
        $income['vat_rate'] = null;
        $cost = $this->line($this->contractor, '100', '800000');
        $cost['vat_mode'] = 'included';
        $cost['vat_rate'] = '0';
        $this->save($this->command([$income, $cost]));
        $rows = EstimateFinanceAllocation::query()->where('estimate_id', $this->estimate->id)->get()->keyBy('side');
        self::assertSame('none', $rows['revenue']->vat_mode);
        self::assertNull($rows['revenue']->vat_rate);
        self::assertSame('1000000.00', $rows['revenue']->amount_with_vat);
        self::assertSame('included', $rows['cost']->vat_mode);
        self::assertSame('0.0000', $rows['cost']->vat_rate);
        self::assertSame('200000.00', $this->report()['rows'][0]['margin']);
        self::assertNotContains('unknown_price_or_tax', $this->report()['rows'][0]['warnings']);
    }

    public function test_explicit_tax_mode_rejects_missing_or_contradictory_rate(): void
    {
        foreach ([['none', 'without_vat', '0'], ['exclusive', 'without_vat', null],
            ['included', 'with_vat', null], ['included', 'without_vat', '20']] as [$mode, $basis, $rate]) {
            $line = $this->line($this->customer, '100', '1000000');
            $line['vat_mode'] = $mode;
            $line['price_basis'] = $basis;
            $line['vat_rate'] = $rate;
            try {
                $this->save($this->command([$line]));
                self::fail('Contradictory tax conditions were accepted');
            } catch (ValidationException) {
                self::assertDatabaseCount('estimate_finance_allocations', 0);
            }
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

    public function test_resource_source_comparison_is_independent_for_each_work_and_resource(): void
    {
        $query = app(\App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceQuery::class);
        $this->item->update(['resource_calculation' => [['name' => 'Арматура', 'quantity' => '100']]]);
        $other = EstimateItem::query()->create(['estimate_id' => $this->estimate->id, 'position_number' => '2',
            'name' => 'Другая работа', 'item_type' => 'work', 'quantity' => '1', 'quantity_total' => '1',
            'total_amount' => '100', 'resource_calculation' => [['name' => 'Кирпич', 'quantity' => '20']]]);
        $firstHash = hash('sha256', json_encode($query->pendingResources($this->item), JSON_THROW_ON_ERROR));
        $otherHash = hash('sha256', json_encode($query->pendingResources($other), JSON_THROW_ON_ERROR));
        $cases = [[$this->item, $firstHash, false], [$this->item, $otherHash, true], [$this->item, null, false],
            [$other, $otherHash, false], [$other, $firstHash, true]];
        $expected = [];
        foreach ($cases as [$item, $hash, $changed]) {
            $resource = EstimateItemResource::query()->create(['estimate_item_id' => $item->id, 'resource_type' => 'material',
                'name' => 'Материал', 'total_quantity' => '1', 'quantity_per_unit' => '1', 'total_amount' => '100',
                'finance_source_hash' => $hash]);
            $expected['r:'.$resource->id] = $changed;
        }
        $targets = $query->targets($this->estimate);
        foreach ($expected as $key => $changed) {
            self::assertSame($changed, $targets[$key]['source_changed']);
        }
        self::assertSame([], $targets['i:'.$this->item->id]['pending_resources']);
        self::assertSame([], $targets['i:'.$other->id]['pending_resources']);
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

    public function test_finance_permission_alone_cannot_create_contract_allocations(): void
    {
        $revision = (int) $this->estimate->fresh()->finance_revision;
        $this->mock(AuthorizationService::class)->shouldReceive('can')
            ->andReturnUsing(static fn ($actor, $permission): bool => $permission !== 'contracts.edit');
        $finance = app(EstimateFinanceService::class);
        try {
            $finance->save($this->actor, $this->estimate->project_id, $this->estimate->id,
                $this->command([$this->line($this->customer, '100', '1000000')]));
            self::fail('Contract edit permission was not enforced');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            self::assertDatabaseCount('estimate_finance_allocations', 0);
            self::assertSame($revision, (int) $this->estimate->fresh()->finance_revision);
        }
    }

    public function test_empty_replacement_cannot_remove_contract_conditions_without_contract_permission(): void
    {
        $this->save($this->command([$this->line($this->customer, '100', '1000000')]));
        $allocation = EstimateFinanceAllocation::query()->where('estimate_id', $this->estimate->id)->firstOrFail();
        $revision = (int) $this->estimate->fresh()->finance_revision;
        $this->mock(AuthorizationService::class)->shouldReceive('can')
            ->andReturnUsing(static fn ($actor, $permission): bool => $permission !== 'contracts.edit');
        try {
            app(EstimateFinanceService::class)->save($this->actor, $this->estimate->project_id, $this->estimate->id,
                $this->command([]));
            self::fail('Removing contract conditions was allowed');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            self::assertSame('1000000.00', $allocation->fresh()->amount_with_vat);
            self::assertSame($revision, (int) $this->estimate->fresh()->finance_revision);
        }
    }

    public function test_estimate_price_preview_requires_contract_permission(): void
    {
        $this->mock(AuthorizationService::class)->shouldReceive('can')
            ->andReturnUsing(static fn ($actor, $permission): bool => $permission !== 'contracts.edit');
        $line = $this->line($this->customer, '100', '1000000');
        $line['adopt_estimate_price'] = true;
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(EstimateFinanceService::class)->preview($this->actor, $this->estimate->project_id, $this->estimate->id,
            $this->command([$line]) + ['preview_operation' => 'estimate_prices']);
    }

    public function test_own_cost_without_contract_does_not_require_contract_permission(): void
    {
        $this->mock(AuthorizationService::class)->shouldReceive('can')
            ->andReturnUsing(static fn ($actor, $permission): bool => $permission !== 'contracts.edit');
        $line = $this->line($this->contractor, '100', '800000');
        $line['source'] = 'own';
        $line['contract_id'] = null;
        $result = app(EstimateFinanceService::class)->save($this->actor, $this->estimate->project_id, $this->estimate->id,
            $this->command([$line]));
        self::assertFalse($result['replayed']);
        self::assertDatabaseCount('estimate_finance_allocations', 1);
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
