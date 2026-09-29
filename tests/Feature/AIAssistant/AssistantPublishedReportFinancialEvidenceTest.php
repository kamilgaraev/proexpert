<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Core\Payments\Reporting\FinanceSourceAccessPolicy;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Core\Reporting\Support\OwnerSnapshotIdentityGuard;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantPublishedReportProjection;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\ProjectFinanceOutputRedactor;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\ProjectFinanceQueryService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedPostgresTestDatabase;
use Tests\TestCase;

final class AssistantPublishedReportFinancialEvidenceTest extends TestCase
{
    private ?string $connectionName = null;
    private ?array $originalConfiguration = null;
    private ?string $privateSchema = null;

    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectionName = DB::getDefaultConnection();
        $this->originalConfiguration = config('database.connections.'.$this->connectionName);
        $configuration = IsolatedPostgresTestDatabase::configuration();
        $this->privateSchema = $configuration['schema'];
        $this->assertMatchesRegularExpression('/^most_phpunit_[a-f0-9]{24}$/D', $this->privateSchema);
        config()->set('database.connections.'.$this->connectionName, $configuration);
        DB::purge($this->connectionName);
        Schema::create('organizations', static function (Blueprint $table): void { $table->id(); });
        DB::table('organizations')->insert(['id' => 1]);
        (require base_path('app/BusinessModules/Features/Budgeting/migrations/2026_07_26_000110_create_budgeting_project_finance_report_projections.php'))->up();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->connectionName !== null && $this->privateSchema !== null && preg_match('/^most_phpunit_[a-f0-9]{24}$/D', $this->privateSchema) === 1) {
                DB::connection($this->connectionName)->statement('DROP SCHEMA IF EXISTS "'.$this->privateSchema.'" CASCADE');
            }
        } finally {
            if ($this->connectionName !== null && $this->originalConfiguration !== null) {
                DB::purge($this->connectionName);
                config()->set('database.connections.'.$this->connectionName, $this->originalConfiguration);
            }
            parent::tearDown();
        }
    }

    public function test_native_postgres_report_rows_and_totals_retain_exact_bigint_and_do_not_sum_a_page(): void
    {
        [$context, $snapshot, $asOf] = $this->fixture('project_margin');
        $query = $this->query();
        $result = $query->result($context, $snapshot);
        $page = $query->page($context, $snapshot, new ReportWindowSort('project_name', ReportSortDirection::ASC), null, 1);
        $projection = (new AssistantPublishedReportProjection)->project('01K5ABCDEFGHJKMNPQRSTVWXYZ', 'project_margin', 1, $result, $page, $asOf, new DateTimeImmutable);
        $rows = $projection['financial_evidence']['rows'];
        self::assertTrue($projection['has_more']);
        self::assertSame('9007199254740993123', $rows[0]['fields']['actual_revenue']);
        self::assertSame('9007199254740993124', $rows[1]['fields']['actual_revenue_minor']);
        self::assertSame('complete_report_totals', $rows[1]['scope']);
        self::assertSame('snapshot_as_of', $rows[0]['source_ref']['evidence_time_basis']);
        self::assertSame($snapshot->sourceHash->value, $rows[0]['source_ref']['source_hash']);
        self::assertSame($asOf->format(DATE_ATOM), $rows[0]['source_ref']['as_of']);
        $otherScope = new ReportScope(2, [2], [7], [], new DateTimeZone('UTC'));
        $otherContext = new ReportExecutionContext($context->actor, $otherScope, $context->visibility,
            new AuthorizationDecisionContext('http', 2, [2], [7], [], new DateTimeZone('UTC'), 'other-organization', null));
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $query->result($otherContext, $snapshot);
    }

    public function test_native_current_sensitive_visibility_removes_wip_money_from_rows_and_totals(): void
    {
        [$context, $snapshot, $asOf] = $this->fixture('wip_completion_forecast');
        $query = $this->query();
        $authorized = (new AssistantPublishedReportProjection)->project('01K5ABCDEFGHJKMNPQRSTVWXYZ', 'wip_completion_forecast', 1,
            $query->result($context, $snapshot), $query->page($context, $snapshot, new ReportWindowSort('project_name', ReportSortDirection::ASC), null, 20), $asOf, new DateTimeImmutable);
        self::assertSame('9007199254740993123', $authorized['financial_evidence']['rows'][0]['fields']['eac']);
        $restricted = new ReportExecutionContext($context->actor, $context->scope, new ReportVisibility(true, false, false, false, false, false, false), $context->authorization);
        $output = (new AssistantPublishedReportProjection)->project('01K5ABCDEFGHJKMNPQRSTVWXYZ', 'wip_completion_forecast', 1,
            $query->result($restricted, $snapshot), $query->page($restricted, $snapshot, new ReportWindowSort('project_name', ReportSortDirection::ASC), null, 20), $asOf, new DateTimeImmutable);
        foreach ($output['financial_evidence']['rows'] as $row) {
            self::assertArrayNotHasKey('eac', $row['fields']);
            self::assertArrayNotHasKey('eac_minor', $row['fields']);
        }
        self::assertStringNotContainsString('9007199254740993123', $output['server_formatted_answer']);
        self::assertSame(['wip' => '3'], $output['financial_evidence']['rows'][0]['fields']);
    }

    private function query(): ProjectFinanceQueryService
    {
        return new ProjectFinanceQueryService(new FinanceSourceAccessPolicy, new OwnerSnapshotIdentityGuard, new ProjectFinanceOutputRedactor);
    }

    private function fixture(string $code): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $generated = $now->modify('-1 hour');
        $asOf = $generated->modify('-1 day');
        $scope = new ReportScope(1, [1], [7], [], new DateTimeZone('UTC'));
        $context = new ReportExecutionContext(new ReportActor(9, 'active', []), $scope, new ReportVisibility(true, true, true, true, true, true, true),
            new AuthorizationDecisionContext('http', 1, [1], [7], [], new DateTimeZone('UTC'), 'assistant-financial-projection', null));
        $hash = new Sha256Hash(hash('sha256', $code));
        $watermarks = ['query_hash' => $hash->value, 'as_of' => $asOf->format(DATE_ATOM), 'source_schema_version' => 'v1', 'budget_version_id' => 0, 'forecast_version_id' => 0];
        $snapshot = new ReportSnapshotRef('project_finance', '01K5ABCDEFGHJKMNPQRSTVWXYZ', $scope, $hash, 'v1', $hash, $generated, $now->modify('+1 day'), $watermarks, ReportSnapshotClassification::OPERATIONAL, null);
        $totals = $code === 'project_margin' ? ['RUB' => ['actual_revenue_minor' => '9007199254740993124']] : ['RUB' => ['wip_minor' => 6, 'eac_minor' => '9007199254740993124']];
        DB::table('budgeting_project_finance_snapshots')->insert(['id' => $snapshot->id, 'organization_id' => 1, 'report_code' => $code,
            'definition_hash' => $hash->value, 'formula_version' => 'v1', 'source_schema_version' => 'v1', 'scope_hash' => hash('sha256', CanonicalJson::encode($scope->canonicalIdentity())),
            'query_hash' => $hash->value, 'source_hash' => $hash->value, 'source_snapshot_kind' => 'budgeting_epm_data_mart', 'source_snapshot_id' => 'source1', 'source_snapshot_hash' => $hash->value,
            'as_of' => $asOf, 'row_count' => 2, 'totals' => json_encode($totals, JSON_THROW_ON_ERROR), 'source_refs' => '[]', 'quality_status' => 'complete',
            'coverage_numerator' => 2, 'coverage_denominator' => 2, 'generated_at' => $generated, 'stale_at' => $snapshot->staleAt]);
        foreach ([['a', '9007199254740993123'], ['b', '1']] as [$key, $amount]) {
            DB::table('budgeting_project_finance_rows')->insert(['snapshot_id' => $snapshot->id, 'organization_id' => 1, 'report_code' => $code,
                'row_key' => $key, 'project_id' => 7, 'project_name' => $key, 'currency' => 'RUB', 'currency_source' => 'ledger', 'quality_status' => 'complete', 'source_refs' => '[]',
                ...($code === 'project_margin' ? ['actual_revenue_minor' => $amount] : ['wip_minor' => 3, 'eac_minor' => $amount])]);
        }
        return [$context, $snapshot, $asOf];
    }
}
