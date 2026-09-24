<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessModules\Core\Payments;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentSchedule;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentService;
use App\BusinessModules\Core\Payments\Services\PaymentScheduleGenerator;
use App\BusinessModules\Core\Payments\Services\PaymentScheduleService;
use App\BusinessModules\Core\Payments\Services\PaymentScheduleSynchronizationService;
use App\BusinessModules\Core\Payments\Services\PaymentCalendarSourceService;
use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarSourceFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class PaymentScheduleSynchronizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_sync_preserves_installment_identity_and_undated_balance(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $document = $this->document($context);
        $parts = [
            ['source_key' => 'receipt:1', 'due_date' => '2026-09-15', 'amount_minor' => 105000],
            ['source_key' => 'unreceived', 'due_date' => null, 'amount_minor' => 105000],
        ];
        $service = app(PaymentScheduleSynchronizationService::class);

        $first = $service->synchronize($document->id, $context->organization->id, 'procurement', $parts);
        $second = $service->synchronize($document->id, $context->organization->id, 'procurement', $parts);

        $this->assertSame($first->modelKeys(), $second->modelKeys());
        $this->assertCount(2, $second);
        $this->assertSame('2100.00', number_format((float) $second->sum('amount'), 2, '.', ''));
        $undated = $second->firstWhere('source_key', 'procurement:unreceived');
        $this->assertNull($undated->due_date);
        $this->assertFalse($undated->isOverdue());
        $this->assertNull($undated->getDaysUntilDue());
    }

    public function test_receipts_and_returns_preserve_real_payment_and_stable_rows(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $document = $this->document($context);
        $service = app(PaymentScheduleSynchronizationService::class);
        $first = $service->synchronize($document->id, $context->organization->id, 'procurement', [
            ['source_key' => 'receipt:1', 'due_date' => '2026-09-15', 'amount_minor' => 105000],
            ['source_key' => 'unreceived', 'due_date' => null, 'amount_minor' => 105000],
        ])->keyBy('source_key');

        app(PaymentDocumentService::class)->registerPayment($document, '1050.00', [
            'payment_method' => 'bank_transfer',
            'reference_number' => 'SYNC-PAYMENT-001',
            'transaction_date' => '2026-09-05',
            'created_by_user_id' => $context->user->id,
        ]);
        $transaction = PaymentTransaction::query()->where('payment_document_id', $document->id)->sole();
        $second = $service->synchronize($document->id, $context->organization->id, 'procurement', [
            ['source_key' => 'receipt:1', 'due_date' => '2026-09-15', 'amount_minor' => 105000],
            ['source_key' => 'receipt:2', 'due_date' => '2026-09-17', 'amount_minor' => 105000],
        ])->keyBy('source_key');

        $this->assertSame($first['procurement:receipt:1']->id, $second['procurement:receipt:1']->id);
        $this->assertSame('paid', $second['procurement:receipt:1']->status);
        $this->assertSame($transaction->id, $second['procurement:receipt:1']->payment_transaction_id);
        $this->assertSame('0.00', $second['procurement:unreceived']->amount);

        $afterReturn = $service->synchronize($document->id, $context->organization->id, 'procurement', [
            ['source_key' => 'receipt:2', 'due_date' => '2026-09-17', 'amount_minor' => 105000],
            ['source_key' => 'unreceived', 'due_date' => null, 'amount_minor' => 105000],
        ])->keyBy('source_key');

        $this->assertSame($first['procurement:receipt:1']->id, $afterReturn['procurement:receipt:1']->id);
        $this->assertSame('0.00', $afterReturn['procurement:receipt:1']->amount);
        $this->assertSame($first['procurement:unreceived']->id, $afterReturn['procurement:unreceived']->id);
        $this->assertSame('1050.00', $afterReturn['procurement:receipt:2']->paid_amount);
        $this->assertSame('0.00', $afterReturn['procurement:unreceived']->paid_amount);
        $this->assertSame('1050.00', $document->fresh()->paid_amount);
        $this->assertSame('1050.00', $transaction->fresh()->amount);
        $this->assertSame(1, PaymentTransaction::query()->where('payment_document_id', $document->id)->count());
    }

    public function test_manual_schedule_is_not_replaced(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $document = $this->document($context);
        $manual = PaymentSchedule::query()->create([
            'payment_document_id' => $document->id,
            'installment_number' => 1,
            'due_date' => '2026-09-15',
            'amount' => '2100.00',
            'status' => 'pending',
        ]);

        try {
            app(PaymentScheduleSynchronizationService::class)->synchronize(
                $document->id, $context->organization->id, 'procurement',
                [['source_key' => 'unreceived', 'due_date' => null, 'amount_minor' => 210000]],
            );
            $this->fail('Manual payment schedule must remain unchanged.');
        } catch (\DomainException) {
            $this->assertSame('2100.00', $manual->fresh()->amount);
            $this->assertNull($manual->fresh()->source_key);
            $this->assertSame(1, PaymentSchedule::query()->where('payment_document_id', $document->id)->count());
        }
    }

    public function test_foreign_organization_and_invalid_sum_do_not_write_rows(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $foreign = AdminApiTestContext::create(roleSlug: 'web_admin');
        $document = $this->document($context);
        $service = app(PaymentScheduleSynchronizationService::class);

        foreach ([[$foreign->organization->id, 210000], [$context->organization->id, 209999]] as [$organizationId, $amount]) {
            try {
                $service->synchronize($document->id, $organizationId, 'procurement', [
                    ['source_key' => 'unreceived', 'due_date' => null, 'amount_minor' => $amount],
                ]);
                $this->fail('Invalid settlement must not be saved.');
            } catch (\DomainException|\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $this->assertSame(0, PaymentSchedule::query()->where('payment_document_id', $document->id)->count());
            }
        }
    }

    #[DataProvider('manualMutations')]
    public function test_manual_mutations_cannot_overwrite_managed_schedule(string $operation): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $document = $this->document($context);
        $row = app(PaymentScheduleSynchronizationService::class)->synchronize(
            $document->id, $context->organization->id, 'procurement',
            [['source_key' => 'unreceived', 'due_date' => null, 'amount_minor' => 210000]],
        )->sole();
        $manual = [['installment_number' => 1, 'due_date' => '2026-09-15', 'amount' => '2100.00']];

        try {
            match ($operation) {
                'create' => app(PaymentScheduleService::class)->createSchedule($document, $manual),
                'replace' => app(PaymentScheduleService::class)->replacePendingSchedule($document, $manual, $context->user),
                'edit' => app(PaymentScheduleService::class)->updateSchedule($row, ['due_date' => '2026-09-15']),
                'generate' => app(PaymentScheduleGenerator::class)->generate($document, ['installments_count' => 1]),
                'regenerate' => app(PaymentScheduleGenerator::class)->update($document, ['installments_count' => 1]),
            };
            $this->fail('Managed schedule must reject manual replacement.');
        } catch (\DomainException $exception) {
            $this->assertSame(trans_message('payments.schedule.managed_edit_forbidden'), $exception->getMessage());
            $this->assertSame(1, PaymentSchedule::query()->where('payment_document_id', $document->id)->count());
            $this->assertSame('2100.00', $row->fresh()->amount);
            $this->assertNull($row->fresh()->due_date);
        }
    }

    public function test_schedule_source_is_owned_by_service_not_editable_document_data(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $document = $this->document($context);
        $document->fill(['schedule_source' => 'procurement'])->save();
        $this->assertNull($document->fresh()->schedule_source);

        app(PaymentScheduleSynchronizationService::class)->synchronize(
            $document->id, $context->organization->id, 'procurement',
            [['source_key' => 'unreceived', 'due_date' => null, 'amount_minor' => 210000]],
        );
        $this->assertSame('procurement', $document->fresh()->schedule_source);

        $document->fill(['schedule_source' => null, 'metadata' => ['note' => 'Updated']])->save();
        $this->assertSame('procurement', $document->fresh()->schedule_source);
        $this->assertSame(['note' => 'Updated'], $document->fresh()->metadata);
    }

    public function test_calendar_collects_only_own_undated_balance_without_parent_duplicate(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $foreign = AdminApiTestContext::create(roleSlug: 'web_admin');
        $document = $this->document($context);
        $service = app(PaymentScheduleSynchronizationService::class);
        foreach ([$document, $this->document($foreign)] as $paymentDocument) {
            $service->synchronize($paymentDocument->id, $paymentDocument->organization_id, 'procurement', [
                ['source_key' => 'receipt:1', 'due_date' => '2026-09-15', 'amount_minor' => 105000],
                ['source_key' => 'unreceived', 'due_date' => null, 'amount_minor' => 105000],
            ]);
        }
        $source = app(PaymentCalendarSourceService::class);
        $filters = new PaymentCalendarSourceFilters(
            organizationId: $context->organization->id, periodStart: '2030-01-01', periodEnd: '2030-01-31',
        );

        $items = $source->collectUndated($filters);

        $this->assertCount(1, $items);
        $this->assertSame('1050.00', $items[0]->remainingAmount);
        $this->assertSame($document->id, $items[0]->drillDown['payment_document_id']);
        $this->assertNull($source->fromPaymentDocument($document->fresh()));
        $contract = app(\App\BusinessModules\Core\Payments\Services\PaymentCalendarContractService::class)->build($filters);
        $this->assertCount(1, $contract['undated']['items']);
        $this->assertSame('1050.00', $contract['undated']['totals_by_currency']['RUB']['outflow']);
    }

    public function test_forecast_exposes_undated_only_currency_without_inventing_cash_flow(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $document = $this->document($context);
        $document->update(['currency' => 'USD']);
        app(PaymentScheduleSynchronizationService::class)->synchronize(
            $document->id, $context->organization->id, 'procurement',
            [['source_key' => 'unreceived', 'due_date' => null, 'amount_minor' => 210000]],
        );
        $request = [
            'organization_id' => $context->organization->id,
            'period_start' => '2030-01-01', 'period_end' => '2030-01-02',
            '_skip_data_mart_meta' => true,
        ];
        $service = app(\App\BusinessModules\Features\Budgeting\Services\CashGapForecastReadService::class);
        $withoutBalance = $service->build($request);
        $this->assertArrayHasKey('USD', $withoutBalance['unavailable_currencies']);
        $this->assertSame('2100.00', $withoutBalance['undated']['totals_by_currency']['USD']['outflow']);

        \App\BusinessModules\Features\Budgeting\Models\CashGapOpeningBalance::query()->create([
            'organization_id' => $context->organization->id,
            'balance_date' => '2029-12-31', 'currency' => 'USD', 'amount' => '1000.00',
            'status' => 'approved', 'created_by_user_id' => $context->user->id,
            'approved_by_user_id' => $context->user->id, 'approved_at' => now(),
        ]);
        $withBalance = $service->build($request);
        $this->assertSame('1000.00', $withBalance['forecasts']['USD']['closing_balance']);
        $this->assertSame('2100.00', $withBalance['forecasts']['USD']['undated']['outflow']);
        $this->assertSame(
            [trans_message('payments.undated.forecast_warning')],
            $withBalance['forecasts']['USD']['warnings'],
        );
        $this->assertSame(1, $withBalance['undated']['items_count']);
    }

    public function test_historical_liquidity_preserves_undated_balance_until_receipt(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-05 12:00:00'));
        try {
            $context = AdminApiTestContext::create(roleSlug: 'web_admin');
            $document = $this->document($context);
            $reader = app(\App\BusinessModules\Features\Budgeting\Reporting\Portfolio\PortfolioLiquidityAsOfSource::class);
            $this->travel(1)->minutes();
            $schedule = app(PaymentScheduleSynchronizationService::class)->synchronize(
                $document->id, $context->organization->id, 'procurement',
                [['source_key' => 'unreceived', 'due_date' => null, 'amount_minor' => 210000]],
            )->sole();
            $versions = \App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models\PortfolioLiquiditySourceVersion::query()
                ->where('organization_id', $context->organization->id);
            $parentVersion = (clone $versions)->where('source_type', 'payment_document')
                ->where('source_id', (string) $document->id)->orderByDesc('id')->first();
            $version = (clone $versions)->where('source_type', 'payment_schedule')
                ->where('source_id', (string) $schedule->id)->orderByDesc('id')->first();
            $this->assertNotNull($version);
            $this->assertNull($version->payload['date']);
            $this->assertNotNull($parentVersion);
            $this->assertNull($parentVersion->payload);
            $asOfBeforeReceipt = now()->toImmutable();
            $filters = new PaymentCalendarSourceFilters(
                organizationId: $context->organization->id,
                periodStart: '2026-09-01', periodEnd: '2026-09-30',
            );

            $this->travel(1)->days();
            app(PaymentScheduleSynchronizationService::class)->synchronize(
                $document->id, $context->organization->id, 'procurement',
                [['source_key' => 'receipt:1', 'due_date' => '2026-09-20', 'amount_minor' => 210000]],
            );
            $before = $reader->read($context->organization->id, $filters, $asOfBeforeReceipt);
            $after = $reader->read($context->organization->id, $filters, now());

            $this->assertSame([], $before['calendar']);
            $this->assertCount(1, $before['undated']);
            $this->assertSame('2100.00', $before['undated'][0]->remainingAmount);
            $this->assertSame([], $after['undated']);
            $this->assertCount(1, $after['calendar']);
            $this->assertSame('2026-09-20', $after['calendar'][0]->date);
            $this->assertSame('2100.00', $after['calendar'][0]->remainingAmount);
            $this->assertSame([], $after['gaps']);
            $this->assertNull($version->fresh()->payload['date']);
        } finally {
            $this->travelBack();
        }
    }

    public static function manualMutations(): array
    {
        return array_map(fn (string $operation): array => [$operation], ['create', 'replace', 'edit', 'generate', 'regenerate']);
    }

    public function test_liquidity_snapshot_exposes_undated_obligations_without_daily_outflow(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $document = $this->document($context);
        app(PaymentScheduleSynchronizationService::class)->synchronize(
            $document->id, $context->organization->id, 'procurement',
            [['source_key' => 'unreceived', 'due_date' => null, 'amount_minor' => 210000]],
        );
        \App\BusinessModules\Features\Budgeting\Models\CashGapOpeningBalance::query()->create([
            'organization_id' => $context->organization->id,
            'balance_date' => '2026-09-01', 'currency' => 'RUB', 'amount' => '100.00',
            'status' => 'approved', 'approved_at' => now(),
        ]);
        $timezone = new \DateTimeZone('UTC');
        $scope = new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope(
            $context->organization->id, [$context->organization->id], [], [], $timezone,
        );
        $execution = new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext(
            new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor($context->user->id, 'active', ['reports.view']),
            $scope,
            new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility(true, false, false, false, false, false, false),
            new \App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext(
                'http', $context->organization->id, [$context->organization->id], [], [], $timezone, 'liquidity-test', null,
            ),
        );
        $query = new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery(
            (new \Tests\Support\Reporting\ReportDefinitionBuilder)->code('portfolio_liquidity')->payload(),
            $scope,
            new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet([
                'horizon_from' => '2026-09-05', 'horizon_to' => '2026-09-06',
            ]),
            [], now()->toDateTimeImmutable(), 'ru',
        );
        $service = app(\App\BusinessModules\Features\Budgeting\Reporting\Portfolio\BudgetingPortfolioProjectionService::class);
        $snapshot = $service->materialize(
            $execution, $query, new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress(0), 'portfolio_liquidity',
        );
        $record = \App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models\BudgetingPortfolioSnapshot::query()->findOrFail($snapshot->id);

        $this->assertSame('2100.00', $record->totals['undated']['RUB']['outflow'] ?? null);
        $this->assertSame('partial', $record->quality_status);
        $rows = \App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models\PortfolioLiquidityProjection::query()
            ->where('snapshot_id', $snapshot->id)->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('0.00', $row->outflow);
            $this->assertSame('partial', $row->quality_status);
        }
        $this->assertArrayHasKey('undated_payments', $snapshot->watermarks);
    }

    public function test_cfo_dashboard_exposes_undated_obligations_only_for_current_organization(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $other = AdminApiTestContext::create(roleSlug: 'web_admin');
        $synchronizer = app(PaymentScheduleSynchronizationService::class);
        foreach ([$context, $other] as $owner) {
            $document = $this->document($owner);
            $document->update(['currency' => 'USD']);
            $synchronizer->synchronize($document->id, $owner->organization->id, 'procurement', [
                ['source_key' => 'unreceived', 'due_date' => null, 'amount_minor' => 210000],
            ]);
        }

        $dashboard = app(\App\BusinessModules\Features\Budgeting\Services\CfoCommandCenterService::class)->dashboard([
            'current_organization_id' => $context->organization->id,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'currency' => 'USD',
            '_skip_data_mart_meta' => true,
        ], $context->user);

        $summary = $dashboard['summary']['cash_gap'];
        $this->assertFalse($summary['complete']);
        $this->assertSame(1, $summary['undated']['items_count']);
        $this->assertSame('2100.00', $summary['undated']['totals_by_currency']['USD']['outflow']);
        $this->assertSame(['USD'], $dashboard['aggregates']['cash_gap']['requested_currencies']);
        $this->assertContains('payment_due_date_missing', array_column($dashboard['problem_flags'], 'code'));
    }

    public function test_project_portfolio_keeps_undated_project_currency_and_scope(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $other = AdminApiTestContext::create(roleSlug: 'web_admin');
        $projectIds = [];
        foreach ([$context, $other] as $owner) {
            $project = \App\Models\Project::factory()->create([
                'organization_id' => $owner->organization->id,
                'is_archived' => false,
            ]);
            $projectIds[] = $project->id;
            $document = $this->document($owner);
            $document->update(['project_id' => $project->id, 'currency' => 'USD']);
            app(PaymentScheduleSynchronizationService::class)->synchronize(
                $document->id, $owner->organization->id, 'procurement',
                [['source_key' => 'unreceived', 'due_date' => null, 'amount_minor' => 210000]],
            );
        }

        $dashboard = app(\App\BusinessModules\Features\Budgeting\Services\ProjectPortfolioDashboardService::class)->dashboard([
            'organization_id' => $context->organization->id,
            'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
            'currency' => 'USD', '_skip_data_mart_meta' => true,
        ], $context->user);

        $this->assertCount(1, $dashboard['projects']);
        $row = $dashboard['projects'][0];
        $this->assertSame($projectIds[0], $row['project']['id']);
        $this->assertSame('USD', $row['currency']);
        $this->assertFalse($row['cash_gap']['complete']);
        $this->assertSame('2100.00', $row['cash_gap']['undated']['totals_by_currency']['USD']['outflow']);
        $this->assertSame(0.0, $row['cash_gap']['outflows']);
        $this->assertContains('cash_gap_dates_partial', $row['problem_flags']);
        $this->assertNotSame('low', $row['risk_level']);
    }

    private function document(AdminApiTestContext $context): PaymentDocument
    {
        return PaymentDocument::query()->create([
            'organization_id' => $context->organization->id,
            'document_type' => PaymentDocumentType::PAYMENT_ORDER,
            'document_number' => 'SYNC-'.uniqid(),
            'document_date' => '2026-09-05',
            'direction' => InvoiceDirection::OUTGOING,
            'amount' => '2100.00',
            'paid_amount' => '0.00',
            'remaining_amount' => '2100.00',
            'currency' => 'RUB',
            'status' => PaymentDocumentStatus::SCHEDULED,
            'due_date' => '2026-09-15',
            'created_by_user_id' => $context->user->id,
        ]);
    }
}
