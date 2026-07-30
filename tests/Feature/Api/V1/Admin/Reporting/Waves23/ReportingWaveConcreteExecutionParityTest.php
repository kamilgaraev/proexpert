<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Application\Input\CreateReportExportData;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotSeal;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\CsvReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Registry\ProductionCandidateReportDefinitionRegistry;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\DrillDown\QualityDefectFlowDrillDownProvider;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Providers\QualityDefectFlowReportProvider;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Queries\QualityDefectFlowRowQuery;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\DrillDown\WorkforceAdmissionDrillDownProvider;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Providers\WorkforceAdmissionReportProvider;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Queries\WorkforceAdmissionRowQuery;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\DrillDown\SafetyIncidentDrillDownProvider;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Providers\SafetyIncidentActionsReportProvider;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Queries\SafetyIncidentRowQuery;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ReportingWaveConcreteExecutionParityTest extends TestCase
{
    private Capsule $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = new Capsule;
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->database->setEventDispatcher(new Dispatcher(new Container));
        $this->database->setAsGlobal();
        $this->database->bootEloquent();
        $this->createTables();
    }

    #[DataProvider('reports')]
    public function test_actual_definition_provider_rows_and_drill_down_share_one_snapshot(
        string $code,
        string $snapshotTable,
        string $rowTable,
        string $provider,
        string $rowQuery,
        string $drillDown,
        string $sort,
        array $snapshotValues,
        array $rowValues,
    ): void {
        $definition = (new ProductionCandidateReportDefinitionRegistry)->candidate($code)->definition;
        $snapshot = $this->snapshot($code, $definition->definitionHash, $definition->formulaVersion);
        $this->database->table($snapshotTable)->insert([
            ...$this->snapshotBase($snapshot),
            ...$snapshotValues,
        ]);
        $this->database->table($rowTable)->insert([
            'organization_id' => 1,
            'snapshot_id' => $snapshot->id,
            ...$rowValues,
        ]);

        $context = $this->context(false);
        $result = (new ReflectionClass($provider))->newInstanceWithoutConstructor()->result($context, $snapshot);
        $page = (new $rowQuery)->page(
            $context,
            $snapshot,
            new ReportWindowSort($sort, ReportSortDirection::ASC),
            null,
            10,
        );
        $cursorRows = iterator_to_array((new $rowQuery)->cursor(
            $context,
            $snapshot,
            new ReportWindowSort($sort, ReportSortDirection::ASC),
            1,
        ));
        $token = $this->token($snapshot, (string) $rowValues['row_key'], $code === 'quality_defect_flow');
        $drill = (new $drillDown)->drillDown(
            $context,
            $snapshot,
            new ReportDrillDownRequest($token, null, 10),
        );

        $definitionColumns = array_column($definition->columns, 'id');
        $resultColumns = array_column($result->rowSchema, 'id');
        if ($code === 'workforce_admission') {
            $definitionColumns = array_values(array_diff($definitionColumns, ['medical_details']));
        }
        sort($definitionColumns);
        sort($resultColumns);
        self::assertSame($definitionColumns, $resultColumns);
        self::assertSame(1, $result->metadata->rowCount);
        self::assertSame($page->rows[0], $cursorRows[0]['values']);
        self::assertSame(
            $this->csvProjection($page->rows[0], array_column($result->rowSchema, 'id'), $sort),
            $this->csvProjection($cursorRows[0]['values'], array_column($result->rowSchema, 'id'), $sort),
        );
        self::assertSame($snapshot->id, $cursorRows[0]['snapshot_id']);
        self::assertSame($snapshot->sourceHash->value, $cursorRows[0]['source_hash']);
        self::assertSame($page->rows[0]['row_key'], $drill->rows[0]['row_key']);
    }

    public function test_workforce_medical_row_and_drill_down_are_redacted_consistently(): void
    {
        $definition = (new ProductionCandidateReportDefinitionRegistry)->candidate('workforce_admission')->definition;
        $snapshot = $this->snapshot('workforce_admission', $definition->definitionHash, $definition->formulaVersion);
        $this->database->table('safety_admission_snapshots')->insert([
            ...$this->snapshotBase($snapshot),
            ...self::admissionSnapshot(),
        ]);
        $this->database->table('safety_admission_rows')->insert([
            'organization_id' => 1,
            'snapshot_id' => $snapshot->id,
            ...self::admissionRow(),
        ]);
        $query = new WorkforceAdmissionRowQuery;
        $drill = new WorkforceAdmissionDrillDownProvider;
        $sort = new ReportWindowSort('employee_id', ReportSortDirection::ASC);
        $token = $this->token($snapshot, 'admission-1', false);

        $redacted = $query->page($this->context(false), $snapshot, $sort, null, 10)->rows[0];
        $redactedDrill = $drill->drillDown(
            $this->context(false),
            $snapshot,
            new ReportDrillDownRequest($token, null, 10),
        )->rows[0];
        $sensitive = $query->page($this->context(true), $snapshot, $sort, null, 10)->rows[0];
        $sensitiveDrill = $drill->drillDown(
            $this->context(true),
            $snapshot,
            new ReportDrillDownRequest($token, null, 10),
        )->rows[0];

        self::assertArrayNotHasKey('evidence_id', $redacted);
        self::assertArrayNotHasKey('medical_details', $redacted);
        self::assertArrayNotHasKey('evidence_id', $redactedDrill);
        self::assertArrayNotHasKey('medical_details', $redactedDrill);
        self::assertSame(991, $sensitive['evidence_id']);
        self::assertSame(['conclusion' => 'fit'], $sensitive['medical_details']);
        self::assertSame(991, $sensitiveDrill['evidence_id']);
        self::assertSame(['conclusion' => 'fit'], $sensitiveDrill['medical_details']);
    }

    public static function reports(): iterable
    {
        yield 'R23' => [
            'quality_defect_flow',
            'quality_defect_flow_snapshots',
            'quality_defect_flow_rows',
            QualityDefectFlowReportProvider::class,
            QualityDefectFlowRowQuery::class,
            QualityDefectFlowDrillDownProvider::class,
            'cohort_date',
            self::qualitySnapshot(),
            self::qualityRow(),
        ];
        yield 'R24' => [
            'safety_incident_actions',
            'safety_incident_snapshots',
            'safety_incident_rows',
            SafetyIncidentActionsReportProvider::class,
            SafetyIncidentRowQuery::class,
            SafetyIncidentDrillDownProvider::class,
            'event_date',
            self::incidentSnapshot(),
            self::incidentRow(),
        ];
        yield 'R25' => [
            'workforce_admission',
            'safety_admission_snapshots',
            'safety_admission_rows',
            WorkforceAdmissionReportProvider::class,
            WorkforceAdmissionRowQuery::class,
            WorkforceAdmissionDrillDownProvider::class,
            'employee_id',
            self::admissionSnapshot(),
            self::admissionRow(),
        ];
    }

    private function createTables(): void
    {
        foreach (['quality_defect_flow', 'safety_incident', 'safety_admission'] as $prefix) {
            $this->database->schema()->create($prefix.'_snapshots', function (Blueprint $table): void {
                $table->string('id')->primary();
                foreach ([
                    'organization_id', 'definition_hash', 'formula_version', 'source_hash', 'query_hash',
                    'source_watermark', 'generated_at', 'stale_at', 'sealed_at', 'input_hash',
                    'row_count', 'eligible_count', 'projected_count', 'gap_count', 'unknown_count',
                    'opening_count', 'created_count', 'reopened_count', 'closed_count', 'closing_count',
                    'due_count', 'overdue_count', 'overdue_pct', 'mature_cohort_count', 'first_pass_count',
                    'mature_reopened_count', 'reopen_rate', 'first_pass_yield', 'opening_backlog_count',
                    'closing_backlog_count', 'incident_count', 'violation_count', 'action_due_count',
                    'action_overdue_count', 'action_closed_on_time_count', 'exposure_hours',
                    'incident_frequency', 'exposure_complete', 'evaluated_people', 'admitted_people',
                    'partial_people', 'not_admitted_people', 'blocker_count', 'expiring_count',
                    'unverified_count',
                ] as $column) {
                    $table->text($column)->nullable();
                }
            });
            $this->database->schema()->create($prefix.'_rows', function (Blueprint $table): void {
                $table->increments('id');
                foreach ([
                    'organization_id', 'snapshot_id', 'row_key', 'cohort_date', 'project_id',
                    'contractor_id', 'schedule_task_id', 'quality_defect_id', 'event_version',
                    'severity', 'status', 'created_flag', 'reopened_flag', 'closed_flag', 'closing_flag',
                    'cycle_days', 'due_date', 'evidence_refs', 'safety_site_id', 'subject_type',
                    'subject_id', 'event_date', 'owner_user_id', 'closure_verified', 'closure_days',
                    'evidence_id', 'snapshot_date', 'site_assignment_id', 'workforce_assignment_id',
                    'employee_id', 'requirement_code', 'requirement_type', 'mandatory', 'blocked',
                    'verified', 'valid_until', 'evidence_version_id', 'evidence_hash',
                    'evidence_identity', 'medical_details',
                ] as $column) {
                    $table->text($column)->nullable();
                }
            });
        }
    }

    private function snapshot(string $kind, Sha256Hash $definitionHash, string $formula): ReportSnapshotRef
    {
        $scope = new ReportScope(1, [1], [10], [], new DateTimeZone('UTC'));
        $generated = new DateTimeImmutable('2026-07-30T08:00:00Z');

        return new ReportSnapshotRef(
            $kind,
            'snapshot_'.$kind,
            $scope,
            $definitionHash,
            $formula,
            new Sha256Hash(str_repeat('b', 64)),
            $generated,
            new DateTimeImmutable('2026-07-31T08:00:00Z'),
            ['source' => '2026-07-30T07:59:00Z'],
            \App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification::OFFICIAL,
            new ReportSnapshotSeal(
                'test-key',
                'ed25519-sha256',
                new Sha256Hash(str_repeat('c', 64)),
                rtrim(strtr(base64_encode(str_repeat("\0", 64)), '+/', '-_'), '='),
                $generated,
            ),
        );
    }

    private function snapshotBase(ReportSnapshotRef $snapshot): array
    {
        return [
            'id' => $snapshot->id,
            'organization_id' => 1,
            'definition_hash' => $snapshot->definitionHash->value,
            'formula_version' => $snapshot->formulaVersion,
            'source_hash' => $snapshot->sourceHash->value,
            'query_hash' => str_repeat('d', 64),
            'input_hash' => str_repeat('e', 64),
            'source_watermark' => '2026-07-30 07:59:00',
            'generated_at' => '2026-07-30 08:00:00',
            'stale_at' => '2026-07-31 08:00:00',
            'sealed_at' => '2026-07-30 08:00:00',
            'row_count' => 1,
            'eligible_count' => 1,
            'projected_count' => 1,
            'gap_count' => 0,
            'unknown_count' => 0,
        ];
    }

    private function context(bool $sensitive): ReportExecutionContext
    {
        $scope = new ReportScope(1, [1], [10], [], new DateTimeZone('UTC'));

        return new ReportExecutionContext(
            new ReportActor(7, 'active', []),
            $scope,
            new ReportVisibility(true, true, true, true, false, $sensitive, false),
            new AuthorizationDecisionContext('http', 1, [1], [10], [], new DateTimeZone('UTC'), 'test', null),
        );
    }

    private function token(ReportSnapshotRef $snapshot, string $rowKey, bool $quality): string
    {
        $payload = [
            'snapshot_id' => $snapshot->id,
            'source_hash' => $snapshot->sourceHash->value,
            'row_key' => $rowKey,
            ...($quality ? ['column_id' => 'status'] : []),
        ];

        return rtrim(strtr(base64_encode((string) json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=').'.sig';
    }

    private function csvProjection(array $row, array $publishedColumns, string $sort): array
    {
        $columns = array_values(array_filter(
            array_intersect($publishedColumns, array_keys($row)),
            static fn (string $column): bool => $row[$column] === null || is_scalar($row[$column]),
        ));
        $data = new CreateReportExportData(
            'csv',
            $columns,
            new ReportWindowSort($sort, ReportSortDirection::ASC),
            'ru-RU',
            new DateTimeZone('UTC'),
        );
        $cell = new \ReflectionMethod(CsvReportExportRenderer::class, 'cell');
        $renderer = new CsvReportExportRenderer;

        return array_map(
            static fn (string $column): string => $cell->invoke($renderer, $row[$column], $data, true),
            $columns,
        );
    }

    private static function qualitySnapshot(): array
    {
        return ['opening_count' => 0, 'created_count' => 1, 'reopened_count' => 0, 'closed_count' => 0, 'closing_count' => 1, 'due_count' => 1, 'overdue_count' => 0, 'overdue_pct' => '0.0000', 'mature_cohort_count' => 1, 'first_pass_count' => 1, 'mature_reopened_count' => 0, 'reopen_rate' => '0.0000', 'first_pass_yield' => '100.0000'];
    }

    private static function qualityRow(): array
    {
        return ['row_key' => 'quality-1', 'cohort_date' => '2026-07-01', 'project_id' => 10, 'contractor_id' => 20, 'schedule_task_id' => 30, 'quality_defect_id' => 40, 'event_version' => 1, 'severity' => 'major', 'status' => 'open', 'created_flag' => 1, 'reopened_flag' => 0, 'closed_flag' => 0, 'closing_flag' => 1, 'cycle_days' => null, 'due_date' => '2026-08-01', 'evidence_refs' => '[]'];
    }

    private static function incidentSnapshot(): array
    {
        return ['opening_backlog_count' => 0, 'closing_backlog_count' => 1, 'incident_count' => 1, 'violation_count' => 0, 'action_due_count' => 1, 'action_overdue_count' => 0, 'action_closed_on_time_count' => 0, 'exposure_hours' => '100.0000', 'incident_frequency' => '1.0000', 'exposure_complete' => 1];
    }

    private static function incidentRow(): array
    {
        return ['row_key' => 'incident-1', 'project_id' => 10, 'safety_site_id' => 50, 'contractor_id' => 20, 'subject_type' => 'incident', 'subject_id' => 60, 'event_date' => '2026-07-01', 'event_version' => 1, 'severity' => 'major', 'status' => 'open', 'owner_user_id' => 7, 'due_date' => '2026-08-01', 'created_flag' => 1, 'reopened_flag' => 0, 'closed_flag' => 0, 'closure_verified' => 0, 'closure_days' => null, 'evidence_id' => null];
    }

    private static function admissionSnapshot(): array
    {
        return ['evaluated_people' => 1, 'admitted_people' => 1, 'partial_people' => 0, 'not_admitted_people' => 0, 'blocker_count' => 0, 'expiring_count' => 0, 'unverified_count' => 0];
    }

    private static function admissionRow(): array
    {
        return ['row_key' => 'admission-1', 'snapshot_date' => '2026-07-30', 'project_id' => 10, 'safety_site_id' => 50, 'site_assignment_id' => 70, 'workforce_assignment_id' => 80, 'employee_id' => 90, 'requirement_code' => 'medical', 'requirement_type' => 'medical_exam', 'status' => 'valid', 'mandatory' => 1, 'blocked' => 0, 'verified' => 1, 'valid_until' => '2027-07-30', 'evidence_id' => 991, 'evidence_version_id' => 992, 'evidence_hash' => str_repeat('f', 64), 'evidence_identity' => '{}', 'medical_details' => '{"conclusion":"fit"}'];
    }
}
