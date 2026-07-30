<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportSnapshotSealVerifier;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExportSource;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportSnapshotSealVerificationInput;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportArtifactStream;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportExportData;
use App\BusinessModules\Core\Reporting\Application\Rows\ReportRowChunkReader;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use App\BusinessModules\Core\Reporting\Infrastructure\Cursors\SignedReportCursorCodec;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\CsvReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\XlsxReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Jobs\MaterializeReportRunJob;
use App\BusinessModules\Core\Reporting\Infrastructure\Registry\ProductionReportDefinitionRegistry;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Backfill\QualityDefectFlowBackfill;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\DrillDown\QualityDefectFlowDrillDownProvider;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Providers\QualityDefectFlowReportProvider;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Queries\QualityDefectFlowRowQuery;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Backfill\WorkforceAdmissionBackfill;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\DrillDown\WorkforceAdmissionDrillDownProvider;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Providers\WorkforceAdmissionReportProvider;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Queries\WorkforceAdmissionRowQuery;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Backfill\SafetyExposureBackfill;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Backfill\SafetyIncidentBackfill;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\DrillDown\SafetyIncidentDrillDownProvider;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Providers\SafetyIncidentActionsReportProvider;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Queries\SafetyIncidentRowQuery;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Jobs\ReportingSourceBackfillJob;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use ZipArchive;

#[Group('postgres-contract')]
final class QualityReportsEndToEndPostgresTest extends TestCase
{
    private ?PDO $schemaConnection = null;

    private ?string $schema = null;

    /** @var array<string, array{getenv: string|false, env_exists: bool, env: mixed, server_exists: bool, server: mixed}> */
    private array $previousEnvironment = [];

    private bool $previousMigrationState = false;

    /** @var array<string, mixed> */
    private array $previousReportingConfig = [];

    protected function setUp(): void
    {
        $dsn = getenv('QUALITY_REPORTS_PG_DSN');
        if (! is_string($dsn) || trim($dsn) === '') {
            self::markTestSkipped('QUALITY_REPORTS_PG_DSN is not configured.');
        }

        $connection = $this->connectionFromUrl($dsn);
        if (! preg_match('/_(test|testing|contract)$/D', $connection['database'])) {
            self::markTestSkipped('QUALITY_REPORTS_PG_DSN must target a disposable test database.');
        }

        $this->rememberEnvironment(['DB_CONNECTION', 'DB_URL', 'DB_SCHEMA']);
        $this->previousMigrationState = RefreshDatabaseState::$migrated;
        $this->schema = 'quality_reports_'.strtolower(Str::random(16));
        $this->schemaConnection = new PDO(
            $connection['pdo_dsn'],
            $connection['username'],
            $connection['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $this->schemaConnection->exec('CREATE SCHEMA '.$this->quotedIdentifier($this->schema));
        $this->environment('DB_CONNECTION', 'pgsql');
        $this->environment('DB_URL', $dsn);
        $this->environment('DB_SCHEMA', $this->schema);

        try {
            parent::setUp();
            $this->previousReportingConfig = (array) config('reporting.snapshot_signing', []);
            $this->configureSnapshotSigning();

            self::assertSame('pgsql', DB::connection()->getDriverName());
            self::assertTrue(DB::getSchemaBuilder()->hasTable('quality_defect_flow_snapshots'));
            self::assertTrue(DB::getSchemaBuilder()->hasTable('safety_incident_snapshots'));
            self::assertTrue(DB::getSchemaBuilder()->hasTable('safety_admission_snapshots'));
        } catch (\Throwable $exception) {
            $this->cleanupPostgresContract();
            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            $this->cleanupPostgresContract();
        }
    }

    public function test_r23_r24_r25_execute_the_real_postgres_reporting_pipeline(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);
        $this->grantReportRunAccess($actor, $organization, $project);
        $sourceTime = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $siteId = $this->seedSafetySite((int) $organization->id, (int) $project->id);

        $employeeId = $this->seedWorkforceAdmission(
            (int) $organization->id,
            (int) $project->id,
            $siteId,
            $sourceTime,
        );
        $this->seedQualityDefectFlow(
            (int) $organization->id,
            (int) $project->id,
            (int) $actor->id,
            $sourceTime,
        );
        $this->seedSafetyIncidentActions(
            (int) $organization->id,
            (int) $project->id,
            $siteId,
            $employeeId,
            (int) $actor->id,
            $sourceTime,
        );

        foreach ([
            ReportingSourceBackfillJob::QUALITY_DEFECTS,
            ReportingSourceBackfillJob::SAFETY_INCIDENTS,
            ReportingSourceBackfillJob::WORKFORCE_ADMISSION,
            ReportingSourceBackfillJob::SAFETY_EXPOSURE,
        ] as $sourceCode) {
            $this->synchronizeSource((int) $organization->id, $sourceCode);
        }
        $asOf = new DateTimeImmutable('+1 second', new DateTimeZone('UTC'));

        $ordinary = $this->context(
            (int) $organization->id,
            (int) $project->id,
            (int) $actor->id,
            false,
        );
        $sensitive = $this->context(
            (int) $organization->id,
            (int) $project->id,
            (int) $actor->id,
            true,
        );

        $quality = $this->execute(
            'quality_defect_flow',
            QualityDefectFlowReportProvider::class,
            QualityDefectFlowRowQuery::class,
            QualityDefectFlowDrillDownProvider::class,
            'cohort_date',
            'status',
            $ordinary,
            $asOf,
        );
        $incident = $this->execute(
            'safety_incident_actions',
            SafetyIncidentActionsReportProvider::class,
            SafetyIncidentRowQuery::class,
            SafetyIncidentDrillDownProvider::class,
            'event_date',
            'status',
            $ordinary,
            $asOf,
        );
        $admissionOrdinary = $this->execute(
            'workforce_admission',
            WorkforceAdmissionReportProvider::class,
            WorkforceAdmissionRowQuery::class,
            WorkforceAdmissionDrillDownProvider::class,
            'employee_id',
            'medical_details',
            $ordinary,
            $asOf,
        );
        $admissionSensitive = $this->readExisting(
            $admissionOrdinary['source'],
            WorkforceAdmissionRowQuery::class,
            WorkforceAdmissionDrillDownProvider::class,
            'employee_id',
            'medical_details',
            $sensitive,
        );

        self::assertGreaterThan(1, $quality['row_count']);
        self::assertGreaterThan(1, $incident['row_count']);
        self::assertGreaterThan(1, $admissionOrdinary['row_count']);
        foreach ($admissionOrdinary['rows'] as $row) {
            self::assertArrayNotHasKey('medical_details', $row);
            self::assertArrayNotHasKey('evidence_id', $row);
        }
        foreach ($admissionOrdinary['drills'] as $drill) {
            self::assertArrayNotHasKey('medical_details', $drill);
            self::assertArrayNotHasKey('evidence_id', $drill);
        }
        self::assertStringNotContainsString('evidence_id', $admissionOrdinary['csv']);
        self::assertStringNotContainsString('evidence_id', $admissionOrdinary['xlsx_xml']);

        $sensitiveByType = [];
        foreach ($admissionSensitive['rows'] as $row) {
            $sensitiveByType[$row['requirement_type']] = $row;
            self::assertArrayHasKey('evidence_id', $row);
        }
        self::assertArrayHasKey('medical_exam', $sensitiveByType);
        self::assertArrayHasKey('training', $sensitiveByType);
        self::assertArrayHasKey('medical_details', $sensitiveByType['medical_exam']);
        self::assertArrayNotHasKey('medical_details', $sensitiveByType['training']);
        self::assertStringContainsString('evidence_id', $admissionSensitive['csv']);
        self::assertStringContainsString('evidence_id', $admissionSensitive['xlsx_xml']);
    }

    private function execute(
        string $code,
        string $providerClass,
        string $rowQueryClass,
        string $drillClass,
        string $sortField,
        string $drillColumn,
        ReportExecutionContext $context,
        DateTimeImmutable $asOf,
    ): array {
        $definitions = app(ReportDefinitionRegistry::class);
        $definition = $definitions->published($code)->definition;
        self::assertContains($sortField, array_column($definition->sorts, 'id'));
        $query = new ReportQuery(
            $definition,
            $context->scope,
            new ReportFilterSet([]),
            [],
            $asOf,
            'ru-RU',
        );
        $binding = app(ReportDefinitionBindingAssembler::class)->assemble($definitions)->get($code);
        self::assertInstanceOf($providerClass, $binding->dataProvider);

        $runs = app(ReportRunStore::class);
        $run = $runs->createOrReuse(
            $context,
            $query,
            null,
            new IdempotencyKey('quality-pg-'.$code.'-'.Str::uuid()),
        );
        Bus::dispatchSync(new MaterializeReportRunJob($run->id));
        $ready = $runs->get($context, $run->id);
        self::assertSame(ReportRunStatus::READY, $ready->status);
        $source = $runs->exportSource($context, $run->id);
        self::assertInstanceOf(ReportRunExportSource::class, $source);
        self::assertGreaterThan(0, $source->result->metadata->rowCount);
        $this->assertTrustedSeal($source->snapshot);

        return [
            ...$this->readExisting($source, $rowQueryClass, $drillClass, $sortField, $drillColumn, $context),
            'source' => $source,
        ];
    }

    private function readExisting(
        ReportRunExportSource $source,
        string $rowQueryClass,
        string $drillClass,
        string $sortField,
        string $drillColumn,
        ReportExecutionContext $context,
    ): array {
        $rowQuery = app($rowQueryClass);
        $drill = app($drillClass);
        self::assertInstanceOf(ReportRowQuery::class, $rowQuery);
        self::assertInstanceOf(ReportDrillDownProvider::class, $drill);
        $sort = new ReportWindowSort($sortField, ReportSortDirection::ASC);
        $page = $rowQuery->page($context, $source->snapshot, $sort, null, 1);
        self::assertNotEmpty($page->rows);
        self::assertTrue($page->hasMore);
        $row = $page->rows[0];

        $codec = app(SignedReportCursorCodec::class);
        $cursorToken = $codec->encode(
            $context->scope->organizationId,
            $source->run->reportCode,
            $source->run->id,
            $source->snapshot,
            $source->query->queryHash,
            $sort,
            $row[$sortField],
            $row['row_key'],
            $source->run->expiresAt,
        );
        $cursor = $codec->decode(
            $cursorToken,
            $context->scope->organizationId,
            $source->run->reportCode,
            $source->run->id,
            $source->snapshot,
            $source->query->queryHash,
            $sort,
        );
        self::assertSame($source->run->id, $cursor->runId);
        $nextPage = $rowQuery->page($context, $source->snapshot, $sort, $cursor, 1);
        self::assertNotEmpty($nextPage->rows);
        self::assertNotSame($row['row_key'], $nextPage->rows[0]['row_key']);

        $rows = [];
        $drills = [];
        foreach ($rowQuery->cursor($context, $source->snapshot, $sort, 100) as $envelope) {
            $currentRow = $envelope->values;
            $rows[] = $currentRow;
            $drillToken = $codec->encodeDrillDownCell(
                $context->scope->organizationId,
                $source->run->reportCode,
                $source->run->id,
                $source->snapshot,
                $source->query->queryHash,
                $currentRow['row_key'],
                $drillColumn,
                $source->run->expiresAt,
            );
            self::assertSame(
                ['row_key' => $currentRow['row_key'], 'column_id' => $drillColumn],
                $codec->decodeDrillDownCell(
                    $drillToken,
                    $context->scope->organizationId,
                    $source->run->reportCode,
                    $source->run->id,
                    $source->snapshot,
                    $source->query->queryHash,
                ),
            );
            $drillResult = $drill->drillDown(
                $context,
                $source->snapshot,
                new ReportDrillDownRequest($drillToken, null, 25),
            );
            $drills[] = $drillResult->rows[0];
        }
        self::assertCount($source->result->metadata->rowCount, $rows);

        $columns = array_values(array_filter(
            array_column($source->query->definition->columns, 'id'),
            static fn (string $column): bool => array_key_exists($column, $rows[0]),
        ));
        self::assertNotEmpty($columns);
        foreach ($columns as $column) {
            self::assertContains($column, array_column($source->query->definition->columns, 'id'));
        }
        $data = new CreateReportExportData('csv', $columns, $sort, 'ru-RU', new DateTimeZone('UTC'));
        $reader = app(ReportRowChunkReader::class);
        $published = app(ProductionReportDefinitionRegistry::class)->published($source->run->reportCode);
        $csv = new BufferedReportArtifactStream;
        $csvRows = app(CsvReportExportRenderer::class)
            ->forDefinition($published)
            ->render(
                $source,
                $data,
                $reader->read(
                    $context,
                    $source->snapshot,
                    $source->query->queryHash,
                    $sort,
                    1,
                    $rowQuery,
                ),
                $csv,
            );
        self::assertSame($source->result->metadata->rowCount, $csvRows);
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv->bytes);

        $xlsx = new BufferedReportArtifactStream;
        $xlsxRows = app(XlsxReportExportRenderer::class)
            ->forDefinition($published)
            ->render(
                $source,
                new CreateReportExportData('xlsx', $columns, $sort, 'ru-RU', new DateTimeZone('UTC')),
                $reader->read(
                    $context,
                    $source->snapshot,
                    $source->query->queryHash,
                    $sort,
                    1,
                    $rowQuery,
                ),
                $xlsx,
            );
        self::assertSame($source->result->metadata->rowCount, $xlsxRows);
        self::assertStringStartsWith('PK', $xlsx->bytes);

        return [
            'row_count' => $source->result->metadata->rowCount,
            'rows' => $rows,
            'drills' => $drills,
            'csv' => $csv->bytes,
            'xlsx_xml' => $this->xlsxSheetXml($xlsx->bytes),
        ];
    }

    private function assertTrustedSeal(ReportSnapshotRef $snapshot): void
    {
        self::assertNotNull($snapshot->seal);
        app(ReportSnapshotSealVerifier::class)->assertTrusted(new ReportSnapshotSealVerificationInput(
            $snapshot->seal,
            $snapshot->id,
            $snapshot->kind,
            $snapshot->classification,
            $snapshot->generatedAt,
            $snapshot->sourceHash,
        ));
    }

    private function context(
        int $organizationId,
        int $projectId,
        int $actorId,
        bool $sensitive,
    ): ReportExecutionContext {
        $timezone = new DateTimeZone('UTC');
        $scope = new ReportScope($organizationId, [$organizationId], [$projectId], [], $timezone);

        return new ReportExecutionContext(
            new ReportActor($actorId, 'active', []),
            $scope,
            new ReportVisibility(true, true, true, true, false, $sensitive, true),
            new AuthorizationDecisionContext(
                'queue',
                $organizationId,
                [$organizationId],
                [$projectId],
                [],
                $timezone,
                'quality-reports-pg-contract',
                null,
            ),
        );
    }

    private function synchronizeSource(int $organizationId, string $sourceCode): void
    {
        ReportingSourceBackfillJob::request($organizationId, $sourceCode);
        (new ReportingSourceBackfillJob($organizationId, $sourceCode))->handle(
            app(QualityDefectFlowBackfill::class),
            app(SafetyIncidentBackfill::class),
            app(SafetyExposureBackfill::class),
            app(WorkforceAdmissionBackfill::class),
        );

        self::assertSame('ready', DB::table('report_source_sync_ledgers')
            ->where('organization_id', $organizationId)
            ->where('source_code', $sourceCode)
            ->value('status'));
    }

    private function seedQualityDefectFlow(
        int $organizationId,
        int $projectId,
        int $actorId,
        DateTimeImmutable $asOf,
    ): void {
        $defectId = DB::table('quality_defects')->insertGetId([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'created_by' => $actorId,
            'defect_number' => 'PG-R23-1',
            'title' => 'Проверка потока дефектов',
            'severity' => 'major',
            'status' => 'open',
            'inspection_required' => true,
            'created_at' => $asOf->modify('-10 days'),
            'updated_at' => $asOf->modify('-10 days'),
        ]);
        $historyId = DB::table('quality_defect_status_history')->insertGetId([
            'quality_defect_id' => $defectId,
            'organization_id' => $organizationId,
            'from_status' => null,
            'to_status' => 'open',
            'changed_by' => $actorId,
            'changed_at' => $asOf->modify('-10 days'),
            'reporting_dimensions' => json_encode([
                'project_id' => $projectId,
                'contractor_id' => null,
                'schedule_task_id' => null,
                'severity' => 'major',
                'due_date' => $asOf->modify('+5 days')->format('Y-m-d'),
            ], JSON_THROW_ON_ERROR),
            'reporting_evidence_refs' => '[]',
        ]);
        self::assertGreaterThan(0, $historyId);
        $secondDefectId = DB::table('quality_defects')->insertGetId([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'created_by' => $actorId,
            'defect_number' => 'PG-R23-2',
            'title' => 'Второй дефект для проверки курсора',
            'severity' => 'major',
            'status' => 'open',
            'inspection_required' => true,
            'created_at' => $asOf->modify('-9 days'),
            'updated_at' => $asOf->modify('-9 days'),
        ]);
        DB::table('quality_defect_status_history')->insert([
            'quality_defect_id' => $secondDefectId,
            'organization_id' => $organizationId,
            'from_status' => null,
            'to_status' => 'open',
            'changed_by' => $actorId,
            'changed_at' => $asOf->modify('-9 days'),
            'reporting_dimensions' => json_encode([
                'project_id' => $projectId,
                'contractor_id' => null,
                'schedule_task_id' => null,
                'severity' => 'major',
                'due_date' => $asOf->modify('+6 days')->format('Y-m-d'),
            ], JSON_THROW_ON_ERROR),
            'reporting_evidence_refs' => '[]',
        ]);
        DB::table('quality_defect_flow_policy_versions')->insert([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'version' => 'pg-contract-v1',
            'effective_from' => $asOf->modify('-1 year')->format('Y-m-d'),
            'terminal_statuses' => json_encode(['closed'], JSON_THROW_ON_ERROR),
            'maturity_days' => 1,
            'sla_days' => 30,
            'calendar_code' => 'calendar_days',
            'closure_evidence_required' => true,
            'severity_weights' => json_encode(['major' => 1], JSON_THROW_ON_ERROR),
            'source_hash' => hash('sha256', 'r23-policy'),
            'created_at' => $asOf->modify('-1 year'),
            'updated_at' => $asOf->modify('-1 year'),
        ]);
    }

    private function seedSafetyIncidentActions(
        int $organizationId,
        int $projectId,
        int $siteId,
        int $employeeId,
        int $actorId,
        DateTimeImmutable $asOf,
    ): void {
        $incidentId = DB::table('safety_incidents')->insertGetId([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'reported_by_user_id' => $actorId,
            'incident_number' => 'PG-R24-1',
            'title' => 'Проверка действий по инциденту',
            'incident_type' => 'near_miss',
            'severity' => 'minor',
            'status' => 'reported',
            'occurred_at' => $asOf->modify('-2 days'),
            'metadata' => json_encode(['safety_site_id' => $siteId], JSON_THROW_ON_ERROR),
            'created_at' => $asOf->modify('-2 days'),
            'updated_at' => $asOf->modify('-2 days'),
        ]);
        self::assertGreaterThan(0, $incidentId);
        DB::table('safety_incidents')->insert([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'reported_by_user_id' => $actorId,
            'incident_number' => 'PG-R24-2',
            'title' => 'Второй инцидент для проверки курсора',
            'incident_type' => 'near_miss',
            'severity' => 'minor',
            'status' => 'reported',
            'occurred_at' => $asOf->modify('-3 days'),
            'metadata' => json_encode(['safety_site_id' => $siteId], JSON_THROW_ON_ERROR),
            'created_at' => $asOf->modify('-3 days'),
            'updated_at' => $asOf->modify('-3 days'),
        ]);
        DB::table('workforce_attendance_corrections')->insert([
            'organization_id' => $organizationId,
            'employee_id' => $employeeId,
            'project_id' => $projectId,
            'work_date' => $asOf->modify('-2 days')->format('Y-m-d'),
            'status' => 'at_work',
            'hours' => '8.00',
            'reason' => 'Подтвержденная смена',
            'created_by_user_id' => $actorId,
            'created_at' => $asOf->modify('-2 days'),
            'updated_at' => $asOf->modify('-2 days'),
        ]);
        DB::table('safety_incident_policy_versions')->insert([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'version' => 'pg-contract-v1',
            'effective_from' => $asOf->modify('-1 year')->format('Y-m-d'),
            'qualifying_incident_types' => json_encode(['near_miss'], JSON_THROW_ON_ERROR),
            'terminal_statuses' => json_encode(['closed'], JSON_THROW_ON_ERROR),
            'closure_evidence_required' => true,
            'overdue_rule' => 'due_date_before_as_of',
            'calendar_code' => 'calendar_days',
            'frequency_multiplier' => 1000000,
            'source_hash' => hash('sha256', 'r24-policy'),
            'created_at' => $asOf->modify('-1 year'),
            'updated_at' => $asOf->modify('-1 year'),
        ]);
    }

    private function seedWorkforceAdmission(
        int $organizationId,
        int $projectId,
        int $siteId,
        DateTimeImmutable $asOf,
    ): int {
        $employeeId = DB::table('workforce_employees')->insertGetId([
            'organization_id' => $organizationId,
            'personnel_number' => 'PG-R25-1',
            'last_name' => 'Тестов',
            'first_name' => 'Сотрудник',
            'employment_status' => 'active',
            'hire_date' => $asOf->modify('-1 month')->format('Y-m-d'),
            'created_at' => $asOf->modify('-1 month'),
            'updated_at' => $asOf->modify('-1 month'),
        ]);
        $departmentId = DB::table('workforce_departments')->insertGetId([
            'organization_id' => $organizationId,
            'code' => 'PG-DEP',
            'name' => 'Производство',
            'is_active' => true,
            'created_at' => $asOf->modify('-1 month'),
            'updated_at' => $asOf->modify('-1 month'),
        ]);
        $positionId = DB::table('workforce_positions')->insertGetId([
            'organization_id' => $organizationId,
            'code' => 'PG-POS',
            'name' => 'Рабочий',
            'is_active' => true,
            'created_at' => $asOf->modify('-1 month'),
            'updated_at' => $asOf->modify('-1 month'),
        ]);
        $unitId = DB::table('workforce_staff_units')->insertGetId([
            'organization_id' => $organizationId,
            'department_id' => $departmentId,
            'position_id' => $positionId,
            'code' => 'PG-UNIT',
            'headcount' => 1,
            'rate' => 1,
            'valid_from' => $asOf->modify('-1 month')->format('Y-m-d'),
            'is_active' => true,
            'created_at' => $asOf->modify('-1 month'),
            'updated_at' => $asOf->modify('-1 month'),
        ]);
        $assignmentId = DB::table('workforce_employee_assignments')->insertGetId([
            'organization_id' => $organizationId,
            'employee_id' => $employeeId,
            'staff_unit_id' => $unitId,
            'department_id' => $departmentId,
            'position_id' => $positionId,
            'project_id' => $projectId,
            'valid_from' => $asOf->modify('-1 month')->format('Y-m-d'),
            'status' => 'active',
            'created_at' => $asOf->modify('-1 month'),
            'updated_at' => $asOf->modify('-1 month'),
        ]);
        self::assertGreaterThan(0, $assignmentId);
        DB::table('safety_medical_exams')->insert([
            'organization_id' => $organizationId,
            'employee_id' => $employeeId,
            'exam_type' => 'medical',
            'completed_at' => $asOf->modify('-5 days')->format('Y-m-d'),
            'valid_until' => $asOf->modify('+1 year')->format('Y-m-d'),
            'result' => 'fit',
            'metadata' => json_encode(['conclusion' => 'fit'], JSON_THROW_ON_ERROR),
            'created_at' => $asOf->modify('-5 days'),
            'updated_at' => $asOf->modify('-5 days'),
        ]);
        DB::table('safety_training_records')->insert([
            'organization_id' => $organizationId,
            'employee_id' => $employeeId,
            'program_code' => 'training',
            'program_name' => 'Обязательное обучение',
            'training_type' => 'mandatory',
            'completed_at' => $asOf->modify('-4 days')->format('Y-m-d'),
            'valid_until' => $asOf->modify('+1 year')->format('Y-m-d'),
            'result' => 'passed',
            'created_at' => $asOf->modify('-4 days'),
            'updated_at' => $asOf->modify('-4 days'),
        ]);
        DB::table('safety_admission_policy_versions')->insert([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'safety_site_id' => $siteId,
            'version' => 'pg-contract-v1',
            'effective_from' => $asOf->modify('-1 year')->format('Y-m-d'),
            'mandatory_requirements' => json_encode([
                [
                    'code' => 'medical',
                    'type' => 'medical_exam',
                    'label' => 'Медицинский допуск',
                    'required' => true,
                ],
                [
                    'code' => 'training',
                    'type' => 'training',
                    'label' => 'Обязательное обучение',
                    'required' => true,
                ],
            ], JSON_THROW_ON_ERROR),
            'expiring_soon_days' => 30,
            'waiver_evidence_required' => true,
            'source_hash' => hash('sha256', 'r25-policy'),
            'created_at' => $asOf->modify('-1 year'),
            'updated_at' => $asOf->modify('-1 year'),
        ]);

        return (int) $employeeId;
    }

    private function seedSafetySite(int $organizationId, int $projectId): int
    {
        return (int) DB::table('safety_sites')->insertGetId([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'code' => 'PG-SITE',
            'name' => 'Тестовая площадка',
            'timezone' => 'UTC',
            'is_active' => true,
            'active_from' => now()->subYear()->toDateString(),
            'created_at' => now()->subYear(),
            'updated_at' => now()->subYear(),
        ]);
    }

    private function grantReportRunAccess(User $actor, Organization $organization, Project $project): void
    {
        $actor->organizations()->attach($organization->id, [
            'is_owner' => true,
            'is_active' => true,
            'project_access_mode' => 'all',
        ]);
        $actor->assignedProjects()->attach($project->id, [
            'role' => 'member',
            'is_active' => true,
            'assigned_at' => now(),
        ]);
        $role = OrganizationCustomRole::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Контракт выполнения отчетов',
            'slug' => 'quality_reports_contract',
            'system_permissions' => [
                'reports.view',
                'reports.run',
                'reports.export',
                'reports.download',
                'reports.sensitive',
                'reports.audit',
            ],
            'module_permissions' => [],
            'interface_access' => ['lk'],
            'conditions' => null,
            'is_active' => true,
            'created_by' => $actor->id,
        ]);
        UserRoleAssignment::query()->create([
            'user_id' => $actor->id,
            'role_slug' => $role->slug,
            'role_type' => UserRoleAssignment::TYPE_CUSTOM,
            'context_id' => AuthorizationContext::getOrganizationContext((int) $organization->id)->id,
            'assigned_by' => $actor->id,
            'expires_at' => null,
            'is_active' => true,
        ]);
    }

    private function configureSnapshotSigning(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $privateKey = sodium_crypto_sign_secretkey($keyPair);
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

        config([
            'reporting.snapshot_signing.active_key_id' => 'quality-contract-v1',
            'reporting.snapshot_signing.active_private_key' => $encode($privateKey),
            'reporting.snapshot_signing.trusted_public_keys_json' => json_encode([
                'quality-contract-v1' => [
                    'public_key' => $encode($publicKey),
                    'revoked' => false,
                ],
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    private function xlsxSheetXml(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'quality-report-xlsx-');
        self::assertIsString($path);
        file_put_contents($path, $bytes);
        $archive = new ZipArchive;
        self::assertTrue($archive->open($path));
        try {
            return (string) $archive->getFromName('xl/worksheets/sheet1.xml')
                .(string) $archive->getFromName('xl/sharedStrings.xml');
        } finally {
            $archive->close();
            unlink($path);
        }
    }

    /** @param list<string> $keys */
    private function rememberEnvironment(array $keys): void
    {
        foreach ($keys as $key) {
            $this->previousEnvironment[$key] = [
                'getenv' => getenv($key),
                'env_exists' => array_key_exists($key, $_ENV),
                'env' => $_ENV[$key] ?? null,
                'server_exists' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
            ];
        }
    }

    private function cleanupPostgresContract(): void
    {
        try {
            if (isset($this->app)) {
                DB::purge('pgsql');
                config(['reporting.snapshot_signing' => $this->previousReportingConfig]);
            }
        } catch (\Throwable) {
        }
        if ($this->schemaConnection instanceof PDO && is_string($this->schema)) {
            $this->schemaConnection->exec(
                'DROP SCHEMA IF EXISTS '.$this->quotedIdentifier($this->schema).' CASCADE',
            );
        }
        foreach ($this->previousEnvironment as $key => $state) {
            $state['getenv'] === false ? putenv($key) : putenv($key.'='.$state['getenv']);
            if ($state['env_exists']) {
                $_ENV[$key] = $state['env'];
            } else {
                unset($_ENV[$key]);
            }
            if ($state['server_exists']) {
                $_SERVER[$key] = $state['server'];
            } else {
                unset($_SERVER[$key]);
            }
        }
        RefreshDatabaseState::$migrated = $this->previousMigrationState;
        $this->schemaConnection = null;
        $this->schema = null;
    }

    private function connectionFromUrl(string $dsn): array
    {
        $parts = parse_url($dsn);
        if (! is_array($parts)
            || ! in_array($parts['scheme'] ?? null, ['pgsql', 'postgres', 'postgresql'], true)
            || ! isset($parts['host'], $parts['path'], $parts['user'])) {
            self::markTestSkipped('QUALITY_REPORTS_PG_DSN must be a PostgreSQL URL.');
        }
        $database = ltrim((string) $parts['path'], '/');
        $port = (int) ($parts['port'] ?? 5432);
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        $sslmode = isset($query['sslmode']) ? ';sslmode='.(string) $query['sslmode'] : '';

        return [
            'database' => $database,
            'pdo_dsn' => sprintf(
                'pgsql:host=%s;port=%d;dbname=%s%s',
                (string) $parts['host'],
                $port,
                $database,
                $sslmode,
            ),
            'username' => urldecode((string) $parts['user']),
            'password' => urldecode((string) ($parts['pass'] ?? '')),
        ];
    }

    private function environment(string $key, string $value): void
    {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function quotedIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}

final class BufferedReportArtifactStream implements ReportArtifactStream
{
    public string $bytes = '';

    public function write(string $bytes): void
    {
        $this->bytes .= $bytes;
    }

    public function cancellationRequested(): bool
    {
        return false;
    }
}
