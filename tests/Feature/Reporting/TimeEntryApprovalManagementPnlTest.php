<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\ManagementAccountingPolicy;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\ManagementPnlCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\ManagementPnlComponentSet;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\ManagementPnlProjectionService;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Models\ManagementPnlPolicy;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Models\ManagementPnlSnapshot;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Readiness\ManagementPnlReadinessProbe;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\BudgetPlanFactManagementPnlComponentSource;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\Models\ProjectFinanceSnapshot;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\ProjectMarginManagementPnlComponentSource;
use App\BusinessModules\Features\TimeTracking\Reporting\ApprovedTimeEntryReportingFactRecorder;
use App\BusinessModules\Features\TimeTracking\Reporting\Models\ApprovedTimeEntryReportingFact;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostManagementPnlComponentSource;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Models\PayrollReadinessSnapshot;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessManagementPnlComponentSource;
use App\Models\Project;
use App\Models\TimeEntry;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class TimeEntryApprovalManagementPnlTest extends TestCase
{
    use RefreshDatabase;

    public function test_unpriced_hours_change_source_identity_without_fabricating_a_money_bucket(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $query = $this->query($context, $project);
        $priced = $this->record($context, $project, 1500);
        $source = new ProjectLaborCostManagementPnlComponentSource;
        $before = iterator_to_array($source->snapshots($query->scope, $query));
        self::assertSame([], $before[0]->warnings);

        $unpriced = $this->record($context, $project, null);
        $components = iterator_to_array($source->snapshots($query->scope, $query));
        self::assertCount(1, $components);
        $component = $components[0];
        self::assertSame('RUB', $component->currency);
        self::assertSame(1, $component->rowCount);
        self::assertSame(1, $component->coverageNumerator);
        self::assertSame(1, $component->coverageDenominator);
        self::assertSame(['approved_time_entry_rate_missing'], $component->warnings);
        self::assertCount(1, $component->facts);
        self::assertSame(1500, $component->facts[0]->amountMinor);
        self::assertNotSame($before[0]->sourceHash->value, $component->sourceHash->value);
        self::assertSame(hash('sha256', CanonicalJson::encode([
            ['id' => (int) $priced->id, 'hash' => $priced->source_hash],
            ['id' => (int) $unpriced->id, 'hash' => $unpriced->source_hash],
        ])), $component->sourceHash->value);

        $otherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->record($context, $otherProject, null);
        $foreign = AdminApiTestContext::create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreign->organization->id]);
        $this->record($foreign, $foreignProject, null);
        $afterForeignRecords = iterator_to_array($source->snapshots($query->scope, $query));
        self::assertSame($component->sourceHash->value, $afterForeignRecords[0]->sourceHash->value);
    }

    public function test_unpriced_hours_alone_cannot_become_a_money_report(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $query = $this->query($context, $project);
        $this->record($context, $project, null);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('management_pnl_project_labor_cost_unavailable');
        iterator_to_array((new ProjectLaborCostManagementPnlComponentSource)->snapshots($query->scope, $query));
    }

    public function test_database_rejects_money_with_an_unknown_currency(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $fact = $this->record($context, $project, null);
        $attributes = $fact->getAttributes();
        unset($attributes['id']);
        $attributes['hourly_rate_minor'] = 100;

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('time_entry_unpriced_currency_check');
        ApprovedTimeEntryReportingFact::query()->create($attributes);
    }

    public function test_unpriced_hours_keep_known_totals_but_make_readiness_and_projection_partial(): void
    {
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $query = $this->query($context, $project);
        $this->record($context, $project, 1500);
        $policy = $this->prepareMoneySources($query);
        $margin = new ProjectMarginManagementPnlComponentSource;
        $planFact = new BudgetPlanFactManagementPnlComponentSource;
        $labor = new ProjectLaborCostManagementPnlComponentSource;
        $payroll = new PayrollReadinessManagementPnlComponentSource;
        $componentSet = new ManagementPnlComponentSet;
        $probe = new ManagementPnlReadinessProbe($margin, $planFact, $labor, $payroll, $componentSet);
        $projection = new ManagementPnlProjectionService([$margin, $planFact, $labor, $payroll], $componentSet);

        self::assertTrue($probe->inspect($query->scope, $query)->hasExactSealedTuple);
        $beforeRef = $projection->materialize($query->scope, $query, $policy);
        $before = ManagementPnlSnapshot::query()->findOrFail($beforeRef->id);
        self::assertSame('complete', $before->quality_status);

        $this->record($context, $project, null);
        self::assertFalse($probe->inspect($query->scope, $query)->hasExactSealedTuple);
        $afterRef = $projection->materialize($query->scope, $query, $policy);
        $after = ManagementPnlSnapshot::query()->findOrFail($afterRef->id);
        self::assertNotSame($before->id, $after->id);
        self::assertSame($before->totals, $after->totals);
        self::assertSame(['RUB'], array_keys($after->totals));
        self::assertSame(2500, $after->totals['RUB']['direct_cost_minor']);
        self::assertSame('partial', $after->quality_status);
        self::assertSame((int) $after->coverage_numerator, (int) $after->coverage_denominator);
        self::assertContains('approved_time_entry_rate_missing', array_column($after->warnings, 'code'));
    }

    private function query(AdminApiTestContext $context, Project $project): ReportQuery
    {
        $scope = new ReportScope((int) $context->organization->id, [(int) $context->organization->id], [(int) $project->id], [], new DateTimeZone('UTC'));

        return new ReportQuery(
            (new ManagementPnlCandidateContract)->definition(),
            $scope,
            new ReportFilterSet(['period_from' => '2026-05-01', 'period_to' => '2026-05-31', 'scenarios' => ['actual'], 'currencies' => ['RUB']]),
            [],
            new DateTimeImmutable('2026-05-31T23:59:59+00:00'),
            'ru-RU',
        );
    }

    private function record(AdminApiTestContext $context, Project $project, ?int $rate): ApprovedTimeEntryReportingFact
    {
        $entry = TimeEntry::query()->create([
            'organization_id' => $context->organization->id,
            'user_id' => $context->user->id,
            'worker_type' => 'user',
            'project_id' => $project->id,
            'work_date' => '2026-05-22',
            'hours_worked' => 0.01,
            'break_time' => 0,
            'title' => 'Проверка стоимости времени',
            'status' => 'approved',
            'approved_at' => '2026-05-23T12:00:00+00:00',
            'approved_by_user_id' => $context->user->id,
            'is_billable' => false,
            'hourly_rate' => $rate,
            'custom_fields' => $rate === null ? [] : ['rate_currency' => 'RUB'],
        ]);

        return (new ApprovedTimeEntryReportingFactRecorder)->record($entry);
    }

    private function prepareMoneySources(ReportQuery $query): ManagementAccountingPolicy
    {
        $classification = [
            'project_margin.actual_revenue' => 'revenue',
            'budget_plan_fact.actual_non_labor_cost' => 'direct_non_labor_cost',
            'project_labor_cost.direct_labor' => 'direct_labor',
        ];
        ManagementPnlPolicy::query()->create([
            'organization_id' => $query->scope->organizationId,
            'version' => 'qa-v1',
            'status' => 'active',
            'classification_rules' => $classification,
            'allocation_rules' => [],
            'policy_hash' => hash('sha256', CanonicalJson::encode($classification)),
        ]);
        foreach (['project_margin', 'budget_plan_fact'] as $code) {
            $formula = $code === 'project_margin' ? 'budgeting.project-margin.v1' : 'budgeting.plan-fact.v1';
            $snapshot = ProjectFinanceSnapshot::query()->create([
                'id' => (string) Str::ulid(),
                'organization_id' => $query->scope->organizationId,
                'report_code' => $code,
                'definition_hash' => $query->definition->definitionHash->value,
                'formula_version' => $formula,
                'source_schema_version' => $formula,
                'scope_hash' => hash('sha256', CanonicalJson::encode($query->scope->canonicalIdentity())),
                'query_hash' => $query->queryHash->value,
                'source_hash' => hash('sha256', $code),
                'source_snapshot_kind' => $code,
                'source_snapshot_id' => 'qa-source',
                'source_snapshot_hash' => hash('sha256', $code),
                'period_from' => '2026-05-01',
                'period_to' => '2026-05-31',
                'as_of' => $query->asOf,
                'generated_at' => $query->asOf->modify('-1 day'),
                'stale_at' => $query->asOf->modify('+1 day'),
                'row_count' => 1,
                'totals' => [],
                'source_refs' => [],
                'quality_status' => 'complete',
                'coverage_numerator' => 1,
                'coverage_denominator' => 1,
            ]);
            $snapshot->rows()->create([
                'organization_id' => $query->scope->organizationId,
                'report_code' => $code,
                'row_key' => $code . '-qa',
                'project_id' => $query->scope->projectIds[0],
                'period' => '2026-05-22',
                'scenario' => 'actual',
                'currency' => 'RUB',
                'currency_source' => 'qa',
                'direction' => 'expense',
                'cost_class' => 'non_labor',
                'actual_revenue_minor' => $code === 'project_margin' ? 10000 : null,
                'actual_minor' => $code === 'budget_plan_fact' ? 1000 : null,
                'quality_status' => 'complete',
                'source_refs' => [],
            ]);
        }
        PayrollReadinessSnapshot::query()->create([
            'organization_id' => $query->scope->organizationId,
            'payroll_period_id' => 1,
            'project_id' => $query->scope->projectIds[0],
            'period_from' => '2026-05-01',
            'period_to' => '2026-05-31',
            'currency' => 'RUB',
            'currency_source' => 'qa',
            'owner_source_hash' => hash('sha256', 'qa-payroll'),
            'source_hash' => hash('sha256', 'qa-payroll'),
            'row_count' => 0,
            'blocking_issue_count' => 0,
            'locked_at' => $query->asOf->modify('-1 day'),
        ]);

        return new ManagementAccountingPolicy('qa-v1', $classification, []);
    }
}
