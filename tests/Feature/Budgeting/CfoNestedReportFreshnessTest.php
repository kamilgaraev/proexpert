<?php

declare(strict_types=1);

namespace Tests\Feature\Budgeting;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Features\Budgeting\DTOs\EpmDataMartScope;
use App\BusinessModules\Features\Budgeting\DTOs\EpmDataMartStatus;
use App\BusinessModules\Features\Budgeting\Models\BudgetAmount;
use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\BudgetLine;
use App\BusinessModules\Features\Budgeting\Models\BudgetPeriod;
use App\BusinessModules\Features\Budgeting\Models\BudgetScenario;
use App\BusinessModules\Features\Budgeting\Models\BudgetVersion;
use App\BusinessModules\Features\Budgeting\Models\EpmDataMartRecalculationRun;
use App\BusinessModules\Features\Budgeting\Models\EpmDataMartSnapshot;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\BusinessModules\Features\Budgeting\Services\CfoCommandCenterService;
use App\BusinessModules\Features\Budgeting\Services\ProjectPortfolioDashboardService;
use App\Domain\Authorization\Services\ModulePermissionChecker;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\ContractPerformanceAct;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class CfoNestedReportFreshnessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00 UTC'));
        Notification::fake();
        Queue::fake();
    }

    public function test_cfo_keeps_nonempty_payload_and_exposed_freshness_without_reading_discarded_child_metadata(): void
    {
        [$context, $input] = $this->fixture();
        $expected = app(CfoCommandCenterService::class)->dashboard([
            ...$input, 'current_organization_id' => $context->organization->id, '_skip_data_mart_meta' => true,
        ], $context->user);
        [$first, $queries] = $this->captureQueries(fn (): array => $this->dashboard($context, $input));

        self::assertTrue($first['aggregates']['project_portfolio']['available']);
        self::assertSame(1, $first['aggregates']['project_portfolio']['summary']['projects_count']);
        self::assertSame('1000.00', $first['aggregates']['project_portfolio']['summary']['by_currency']['RUB']['revenue']);
        self::assertSame('250.00', $first['aggregates']['project_portfolio']['summary']['by_currency']['RUB']['cost']);
        self::assertSame('750.00', $first['aggregates']['project_portfolio']['summary']['by_currency']['RUB']['gross_margin']);
        self::assertGreaterThan(0, (float) $first['aggregates']['project_portfolio']['summary']['by_currency']['RUB']['eac']);
        self::assertTrue($first['aggregates']['plan_fact']['available']);
        self::assertSame(EpmDataMartScope::CFO_COMMAND_CENTER, $first['meta']['data_mart']['report_scope']);
        self::assertSame(EpmDataMartScope::PLAN_FACT, $first['aggregates']['plan_fact']['meta']['data_mart']['report_scope']);
        self::assertSame(EpmDataMartStatus::ONLINE, $first['meta']['data_mart']['status']);
        self::assertSame($this->withoutDataMartMetadata($this->jsonArray($expected)), $this->withoutDataMartMetadata($first));

        $cfoSnapshot = $this->snapshot($context, $first['meta']['data_mart']);
        $planFactSnapshot = $this->snapshot($context, $first['aggregates']['plan_fact']['meta']['data_mart']);
        $run = EpmDataMartRecalculationRun::query()->create([
            'uuid' => (string) Str::uuid(), 'organization_id' => $context->organization->id,
            'report_scope' => EpmDataMartScope::CFO_COMMAND_CENTER,
            'scope_hash' => $first['meta']['data_mart']['scope_hash'],
            'status' => EpmDataMartStatus::QUEUED, 'formula_version' => $first['meta']['data_mart']['formula_version'],
            'filters' => [], 'queued_at' => now(),
        ]);
        [$second, $secondQueries] = $this->captureQueries(fn (): array => $this->dashboard($context, $input));
        self::assertSame(EpmDataMartStatus::QUEUED, $second['meta']['data_mart']['status']);
        self::assertSame($cfoSnapshot->uuid, $second['meta']['data_mart']['snapshot']['uuid']);
        self::assertSame($run->uuid, $second['meta']['data_mart']['recalculation']['uuid']);
        self::assertSame(EpmDataMartStatus::SUCCEEDED, $second['aggregates']['plan_fact']['meta']['data_mart']['status']);
        self::assertSame($planFactSnapshot->uuid, $second['aggregates']['plan_fact']['meta']['data_mart']['snapshot']['uuid']);
        self::assertSame($this->withoutDataMartMetadata($first), $this->withoutDataMartMetadata($second));

        foreach ([$queries, $secondQueries] as $requestQueries) {
            $this->assertFreshnessQueryPair($requestQueries, EpmDataMartScope::CFO_COMMAND_CENTER, 1);
            $this->assertFreshnessQueryPair($requestQueries, EpmDataMartScope::PLAN_FACT, 1);
            $this->assertFreshnessQueryPair($requestQueries, EpmDataMartScope::PROJECT_MARGIN, 0);
            $this->assertFreshnessQueryPair($requestQueries, EpmDataMartScope::WIP_FORECAST, 0);
        }

        [$standalone, $standaloneQueries] = $this->captureQueries(
            fn (): array => app(ProjectPortfolioDashboardService::class)->dashboard($input, $context->user),
        );
        self::assertCount(1, $standalone['projects']);
        self::assertSame(EpmDataMartScope::PROJECT_PORTFOLIO_DASHBOARD, $standalone['meta']['data_mart']['report_scope']);
        self::assertSame([
            'status' => EpmDataMartStatus::ONLINE, 'generated_at' => null, 'stale_at' => null,
        ], $standalone['freshness']['sections']['wip_forecast']['data_mart']);
        foreach ([EpmDataMartScope::PROJECT_MARGIN, EpmDataMartScope::WIP_FORECAST, EpmDataMartScope::PLAN_FACT, EpmDataMartScope::PROJECT_PORTFOLIO_DASHBOARD] as $scope) {
            $this->assertFreshnessQueryPair($standaloneQueries, $scope, 1);
        }
    }

    public function test_discarded_child_freshness_failure_does_not_make_the_cfo_portfolio_unavailable(): void
    {
        [$context, $input] = $this->fixture();
        $connection = DB::connection();
        $originalDispatcher = $connection->getEventDispatcher();
        self::assertNotNull($originalDispatcher);
        $dispatcher = clone $originalDispatcher;
        $childReads = 0;
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$childReads): void {
            if ($this->isFreshnessQuery($event->sql)
                && (in_array(EpmDataMartScope::PROJECT_MARGIN, $event->bindings, true)
                    || in_array(EpmDataMartScope::WIP_FORECAST, $event->bindings, true))) {
                $childReads++;
                throw new RuntimeException('Synthetic discarded child freshness failure');
            }
        });
        $connection->setEventDispatcher($dispatcher);
        try {
            $payload = $this->dashboard($context, $input);
        } finally {
            $connection->setEventDispatcher($originalDispatcher);
        }

        self::assertSame($originalDispatcher, $connection->getEventDispatcher());
        self::assertTrue($payload['aggregates']['project_portfolio']['available']);
        self::assertSame('750.00', $payload['aggregates']['project_portfolio']['summary']['by_currency']['RUB']['gross_margin']);
        self::assertSame(0, $childReads);
        self::assertSame(EpmDataMartScope::CFO_COMMAND_CENTER, $payload['meta']['data_mart']['report_scope']);
        self::assertSame(EpmDataMartScope::PLAN_FACT, $payload['aggregates']['plan_fact']['meta']['data_mart']['report_scope']);
    }

    public function test_the_existing_internal_skip_still_skips_all_metadata_without_changing_report_values(): void
    {
        [$context, $input] = $this->fixture();
        [$payload, $queries] = $this->captureQueries(
            fn (): array => app(CfoCommandCenterService::class)->dashboard([
                ...$input, 'current_organization_id' => $context->organization->id, '_skip_data_mart_meta' => true,
            ], $context->user),
        );
        self::assertTrue($payload['aggregates']['project_portfolio']['available']);
        self::assertSame('750.00', $payload['aggregates']['project_portfolio']['summary']['by_currency']['RUB']['gross_margin']);
        self::assertArrayNotHasKey('data_mart', $payload['meta']);
        self::assertArrayNotHasKey('data_mart', $payload['aggregates']['plan_fact']['meta']);
        foreach ([EpmDataMartScope::CFO_COMMAND_CENTER, EpmDataMartScope::PLAN_FACT, EpmDataMartScope::PROJECT_MARGIN, EpmDataMartScope::WIP_FORECAST] as $scope) {
            $this->assertFreshnessQueryPair($queries, $scope, 0);
        }
    }

    private function fixture(): array
    {
        $context = AdminApiTestContext::create(roleSlug: 'finance_admin');
        $module = Module::query()->firstOrCreate(['slug' => 'budgeting'], [
            'name' => 'Budgeting', 'version' => '1.0.0', 'type' => 'feature', 'billing_model' => 'free',
            'category' => 'finance', 'permissions' => ['budgeting.cfo.view'], 'is_active' => true, 'is_system_module' => true,
        ]);
        $module->forceFill(['is_system_module' => true])->save();
        OrganizationModuleActivation::query()->create([
            'organization_id' => $context->organization->id, 'module_id' => $module->id,
            'status' => 'active', 'activated_at' => now(),
        ]);
        app(ModulePermissionChecker::class)->clearModuleCache($context->organization->id, 'budgeting');
        $project = Project::factory()->create(['organization_id' => $context->organization->id, 'status' => 'active', 'is_archived' => false]);
        $contractor = Contractor::query()->create(['organization_id' => $context->organization->id, 'name' => 'CFO fixture contractor']);
        $contract = Contract::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'contractor_id' => $contractor->id, 'number' => 'CFO-FRESHNESS', 'date' => '2026-10-01',
            'subject' => 'CFO fixture', 'total_amount' => 2000, 'currency' => 'RUB', 'status' => 'active',
        ]);
        ContractPerformanceAct::withoutEvents(static fn (): ContractPerformanceAct => ContractPerformanceAct::query()->create([
            'contract_id' => $contract->id, 'project_id' => $project->id, 'act_document_number' => 'CFO-ACT',
            'act_date' => '2026-10-16', 'period_start' => '2026-10-01', 'period_end' => '2026-10-16',
            'amount' => 1000, 'amount_without_vat' => 1000, 'vat_rate' => 0, 'vat_amount' => 0,
            'currency' => 'RUB', 'status' => ContractPerformanceAct::STATUS_APPROVED, 'is_approved' => true,
        ]));
        PaymentDocument::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'document_type' => PaymentDocumentType::INVOICE, 'document_number' => 'CFO-COST',
            'document_date' => '2026-10-16', 'due_date' => '2026-10-25', 'direction' => InvoiceDirection::OUTGOING,
            'amount' => 250, 'amount_without_vat' => 250, 'paid_amount' => 0, 'remaining_amount' => 250,
            'currency' => 'RUB', 'status' => PaymentDocumentStatus::APPROVED,
            'invoiceable_type' => Contract::class, 'invoiceable_id' => $contract->id,
        ]);
        $period = BudgetPeriod::query()->create([
            'organization_id' => $context->organization->id, 'code' => 'CFO-OCT', 'name' => 'CFO October',
            'period_type' => 'month', 'starts_at' => '2026-10-01', 'ends_at' => '2026-10-31', 'status' => 'open',
        ]);
        $scenario = BudgetScenario::query()->create([
            'organization_id' => $context->organization->id, 'code' => 'CFO-BASE', 'name' => 'CFO base',
            'scenario_type' => 'base', 'is_default' => true, 'is_active' => true,
        ]);
        $center = ResponsibilityCenter::query()->create([
            'organization_id' => $context->organization->id, 'center_type' => 'project',
            'code' => 'CFO-CENTER', 'name' => 'CFO center', 'is_active' => true,
        ]);
        foreach (['bdds' => ['outflow', 100.0], 'bdr' => ['income', 1200.0]] as $kind => [$direction, $amount]) {
            $article = BudgetArticle::query()->create([
                'organization_id' => $context->organization->id, 'code' => 'CFO-'.$kind, 'name' => 'CFO '.$kind,
                'budget_kind' => $kind, 'flow_direction' => $direction, 'is_leaf' => true, 'is_active' => true,
            ]);
            $version = BudgetVersion::query()->create([
                'organization_id' => $context->organization->id, 'budget_period_id' => $period->id,
                'scenario_id' => $scenario->id, 'budget_kind' => $kind, 'version_number' => 1,
                'name' => 'CFO '.$kind, 'status' => 'active', 'approved_at' => now(), 'activated_at' => now(),
            ]);
            $line = BudgetLine::query()->create([
                'budget_version_id' => $version->id, 'budget_article_id' => $article->id,
                'responsibility_center_id' => $center->id, 'project_id' => $project->id,
                'contract_id' => $contract->id, 'currency' => 'RUB',
            ]);
            BudgetAmount::query()->create([
                'budget_line_id' => $line->id, 'month' => '2026-10-01',
                'plan_amount' => $amount, 'forecast_amount' => $amount, 'currency' => 'RUB',
            ]);
        }

        return [$context, ['organization_id' => $context->organization->id, 'project_id' => $project->id,
            'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'currency' => 'RUB']];
    }

    private function dashboard(AdminApiTestContext $context, array $input): array
    {
        return $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/budgeting/cfo-command-center?'.http_build_query($input))
            ->assertOk()->json('data');
    }

    private function captureQueries(callable $read): array
    {
        $connection = DB::connection();
        $wasLogging = $connection->logging();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        try {
            $payload = $read();

            return [$payload, $connection->getQueryLog()];
        } finally {
            $connection->flushQueryLog();
            if (! $wasLogging) {
                $connection->disableQueryLog();
            }
        }
    }

    private function assertFreshnessQueryPair(array $queries, string $scope, int $expected): void
    {
        foreach (['epm_data_mart_snapshots', 'epm_data_mart_recalculation_runs'] as $table) {
            $matching = array_filter($queries, static fn (array $query): bool =>
                preg_match('/\bfrom\s+"'.preg_quote($table, '/').'"/i', $query['query']) === 1
                && in_array($scope, $query['bindings'], true));
            self::assertCount($expected, $matching, $scope.' '.$table);
        }
    }

    private function isFreshnessQuery(string $sql): bool
    {
        return preg_match('/\bfrom\s+"epm_data_mart_(?:snapshots|recalculation_runs)"/i', $sql) === 1;
    }

    private function snapshot(AdminApiTestContext $context, array $metadata): EpmDataMartSnapshot
    {
        return EpmDataMartSnapshot::query()->create([
            'uuid' => (string) Str::uuid(), 'organization_id' => $context->organization->id,
            'report_scope' => $metadata['report_scope'], 'scope_hash' => $metadata['scope_hash'],
            'status' => EpmDataMartStatus::SUCCEEDED, 'formula_version' => $metadata['formula_version'],
            'source_hash' => hash('sha256', 'CFO synthetic snapshot'), 'filters' => [], 'payload' => [],
            'freshness' => [], 'source_refs' => [], 'generated_at' => now()->subMinute(), 'stale_at' => now()->addDay(),
        ]);
    }

    private function withoutDataMartMetadata(array $payload): array
    {
        unset($payload['data_mart']);
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->withoutDataMartMetadata($value);
            }
        }
        if (($payload['freshness'] ?? null) === []) {
            unset($payload['freshness']);
        }

        return $payload;
    }

    private function jsonArray(array $payload): array
    {
        return json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}
