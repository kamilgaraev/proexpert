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
