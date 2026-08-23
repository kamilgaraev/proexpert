<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\ProjectEvmControlBuiltinPublishedReport;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\ProjectEvmControlCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Services\ProjectControlSourceAssembler;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Services\ProjectEvmControlOptionsService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;

final class ProjectEvmControlOptionsServiceTest extends TestCase
{
    private Capsule $database;

    private ConnectionInterface $connection;

    private ProjectEvmControlOptionsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = new Capsule;
        $this->database->addConnection(\Tests\Support\IsolatedPostgresTestDatabase::configuration());
        $this->database->setAsGlobal();
        $this->database->bootEloquent();
        $this->connection = $this->database->getConnection();
        $this->createSchema();
        $this->seed();
        $this->service = new ProjectEvmControlOptionsService(
            new ProjectControlSourceAssembler,
            new ProjectEvmControlBuiltinPublishedReport(new ProjectEvmControlCandidateContract),
            $this->connection,
        );
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();

        parent::tearDown();
    }

    public function test_options_use_exact_runtime_source_and_server_project_scope(): void
    {
        $options = $this->service->options(
            $this->scope(10),
            new DateTimeImmutable('2026-08-04T15:00:00+03:00'),
        );

        self::assertTrue($options['available'], json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        self::assertNull($options['reason']);
        self::assertSame('2026-08-04T15:00:00+03:00', $options['as_of']);
        self::assertSame(2, $options['baseline']['version_number']);
        self::assertSame(2, $options['wip_version']['version_number']);
        self::assertSame([
            ['id' => 1001, 'name' => '1 — Фундамент'],
            ['id' => 1002, 'name' => '1.1 — Каркас'],
        ], $options['tasks']);
        self::assertSame([
            ['id' => '1', 'name' => '1'],
            ['id' => '1.1', 'name' => '1.1'],
        ], $options['wbs']);
        self::assertSame([['id' => 301, 'name' => 'Подрядчик А']], $options['contractors']);
        self::assertSame([['id' => 401, 'name' => 'ЦФО-1 — Строительство']], $options['cost_centers']);
        self::assertSame([
            ['id' => 'RUB', 'name' => 'RUB'],
            ['id' => 'USD', 'name' => 'USD'],
        ], $options['currencies']);

        $otherProject = $this->service->options(
            $this->scope(20),
            new DateTimeImmutable('2026-08-04T15:00:00+03:00'),
        );
        self::assertSame([['id' => 2001, 'name' => '9 — Чужой проект']], $otherProject['tasks']);
    }

    public function test_task_resource_scope_is_applied_by_the_same_runtime_assembler(): void
    {
        $scope = new ReportScope(
            1,
            [1],
            [10],
            [new ReportScopedResource('task', 1001, 10)],
            new DateTimeZone('Europe/Moscow'),
        );

        $options = $this->service->options(
            $scope,
            new DateTimeImmutable('2026-08-04T15:00:00+03:00'),
        );

        self::assertTrue($options['available'], json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        self::assertSame([['id' => 1001, 'name' => '1 — Фундамент']], $options['tasks']);
        self::assertSame([['id' => 'RUB', 'name' => 'RUB']], $options['currencies']);
    }

    public function test_exact_as_of_does_not_expose_sources_approved_later_the_same_day(): void
    {
        $beforeApproval = $this->service->options(
            $this->scope(10),
            new DateTimeImmutable('2026-08-04T10:00:00+03:00'),
        );
        $afterApproval = $this->service->options(
            $this->scope(10),
            new DateTimeImmutable('2026-08-04T15:00:00+03:00'),
        );

        self::assertTrue($beforeApproval['available']);
        self::assertSame(1, $beforeApproval['baseline']['version_number']);
        self::assertSame(1, $beforeApproval['wip_version']['version_number']);
        self::assertCount(1, $beforeApproval['tasks']);
        self::assertSame(2, $afterApproval['baseline']['version_number']);
        self::assertSame(2, $afterApproval['wip_version']['version_number']);
        self::assertCount(2, $afterApproval['tasks']);
    }

    public function test_runtime_source_gap_is_unavailable_instead_of_returning_partial_options(): void
    {
        $this->connection->table('wip_forecast_lines')->where('id', 2)->delete();

        $options = $this->service->options(
            $this->scope(10),
            new DateTimeImmutable('2026-08-04T15:00:00+03:00'),
        );

        self::assertFalse($options['available']);
        self::assertSame('source_incomplete', $options['reason']);
        self::assertSame([], $options['tasks']);
    }

    public function test_runtime_validation_failure_is_unavailable_without_exposing_technical_reason(): void
    {
        $this->connection->table('wip_forecast_lines')->where('id', 2)->update([
            'dimensions' => $this->json([
                'task_id' => 1001,
                'contractor_id' => 'invalid',
                'cost_center_id' => 401,
            ]),
        ]);

        $options = $this->service->options(
            $this->scope(10),
            new DateTimeImmutable('2026-08-04T15:00:00+03:00'),
        );

        self::assertFalse($options['available']);
        self::assertSame('source_unavailable', $options['reason']);
    }

    private function scope(int $projectId): ReportScope
    {
        return new ReportScope(1, [1], [$projectId], [], new DateTimeZone('Europe/Moscow'));
    }

    private function createSchema(): void
    {
        foreach ([
            'CREATE TABLE project_control_baseline_versions (id BIGINT PRIMARY KEY, organization_id BIGINT, project_id BIGINT, schedule_id BIGINT, version_number INTEGER, approved_at TIMESTAMPTZ, approved_by BIGINT, source_hash CHAR(64), source_payload JSONB)',
            'CREATE TABLE wip_forecast_versions (id BIGINT PRIMARY KEY, uuid UUID, organization_id BIGINT, project_id BIGINT, version_number INTEGER, name TEXT, status TEXT, as_of_date DATE, source_snapshot_hash TEXT, approved_by BIGINT NULL, activated_by BIGINT NULL, approved_at TIMESTAMPTZ NULL, activated_at TIMESTAMPTZ NULL, deleted_at TIMESTAMPTZ NULL)',
            'CREATE TABLE wip_forecast_lines (id BIGINT PRIMARY KEY, forecast_version_id BIGINT, organization_id BIGINT, project_id BIGINT, currency VARCHAR(3), percent_complete NUMERIC(15,4), ac NUMERIC(18,2), etc NUMERIC(18,2) NULL, dimensions JSONB, group_values JSONB, source_row_refs JSONB)',
            'CREATE TABLE project_schedules (id INTEGER PRIMARY KEY, organization_id INTEGER, project_id INTEGER)',
            'CREATE TABLE schedule_tasks (id INTEGER PRIMARY KEY, organization_id INTEGER, schedule_id INTEGER, name TEXT)',
            'CREATE TABLE contractors (id INTEGER PRIMARY KEY, organization_id INTEGER, name TEXT)',
            'CREATE TABLE responsibility_centers (id INTEGER PRIMARY KEY, organization_id INTEGER, code TEXT, name TEXT)',
        ] as $statement) {
            $this->connection->statement($statement);
        }
    }

    private function seed(): void
    {
        $this->connection->table('project_schedules')->insert([
            ['id' => 50, 'organization_id' => 1, 'project_id' => 10],
            ['id' => 60, 'organization_id' => 1, 'project_id' => 20],
        ]);
        $this->connection->table('schedule_tasks')->insert([
            ['id' => 1001, 'organization_id' => 1, 'schedule_id' => 50, 'name' => 'Фундамент'],
            ['id' => 1002, 'organization_id' => 1, 'schedule_id' => 50, 'name' => 'Каркас'],
            ['id' => 2001, 'organization_id' => 1, 'schedule_id' => 60, 'name' => 'Чужой проект'],
        ]);
        $this->connection->table('contractors')->insert([
            ['id' => 301, 'organization_id' => 1, 'name' => 'Подрядчик А'],
            ['id' => 302, 'organization_id' => 1, 'name' => 'Подрядчик Б'],
        ]);
        $this->connection->table('responsibility_centers')->insert([
            ['id' => 401, 'organization_id' => 1, 'code' => 'ЦФО-1', 'name' => 'Строительство'],
            ['id' => 402, 'organization_id' => 1, 'code' => 'ЦФО-2', 'name' => 'Другой проект'],
        ]);

        $this->connection->table('project_control_baseline_versions')->insert([
            $this->baseline(1, 10, 50, 1, '2026-08-01T08:00:00+03:00', [
                $this->baselineRow(1001, '1', 'RUB'),
            ]),
            $this->baseline(2, 10, 50, 2, '2026-08-04T14:00:00+03:00', [
                $this->baselineRow(1001, '1', 'RUB'),
                $this->baselineRow(1002, '1.1', 'USD'),
            ]),
            $this->baseline(3, 20, 60, 1, '2026-08-01T08:00:00+03:00', [
                $this->baselineRow(2001, '9', 'RUB'),
            ]),
        ]);
        $this->connection->table('wip_forecast_versions')->insert([
            $this->version(10, '00000000-0000-4000-8000-000000000010', 10, 1, 'Прогноз 1', '2026-08-01', '2026-08-01T09:00:00+03:00'),
            $this->version(11, '00000000-0000-4000-8000-000000000011', 10, 2, 'Прогноз 2', '2026-08-04', '2026-08-04T14:00:00+03:00'),
            $this->version(20, '00000000-0000-4000-8000-000000000020', 20, 1, 'Чужой прогноз', '2026-08-01', '2026-08-01T09:00:00+03:00'),
        ]);
        $this->connection->table('wip_forecast_lines')->insert([
            $this->line(1, 10, 10, 1001, 'RUB', 301, 401),
            $this->line(2, 11, 10, 1001, 'RUB', 301, 401),
            $this->line(3, 11, 10, 1002, 'USD'),
            $this->line(4, 20, 20, 2001, 'RUB', 302, 402),
        ]);
    }

    /** @return array<string, mixed> */
    private function baseline(int $id, int $projectId, int $scheduleId, int $version, string $approvedAt, array $rows): array
    {
        return [
            'id' => $id,
            'organization_id' => 1,
            'project_id' => $projectId,
            'schedule_id' => $scheduleId,
            'version_number' => $version,
            'approved_at' => $approvedAt,
            'approved_by' => 7,
            'source_hash' => str_repeat(dechex($id), 64),
            'source_payload' => $this->json(['rows' => $rows]),
        ];
    }

    /** @return array<string, mixed> */
    private function baselineRow(int $taskId, string $wbs, string $currency): array
    {
        return [
            'task_id' => $taskId,
            'wbs_code' => $wbs,
            'currency' => $currency,
            'bac' => '100.00',
            'baseline_curve_version' => 'curve-v1',
            'baseline_curve' => [['date' => '2026-08-01', 'planned_value_minor' => 5000]],
        ];
    }

    /** @return array<string, mixed> */
    private function version(
        int $id,
        string $uuid,
        int $projectId,
        int $version,
        string $name,
        string $asOfDate,
        string $activatedAt,
    ): array {
        return [
            'id' => $id,
            'uuid' => $uuid,
            'organization_id' => 1,
            'project_id' => $projectId,
            'version_number' => $version,
            'name' => $name,
            'status' => 'active',
            'as_of_date' => $asOfDate,
            'source_snapshot_hash' => 'source-'.$id,
            'approved_by' => null,
            'activated_by' => 8,
            'approved_at' => null,
            'activated_at' => $activatedAt,
            'deleted_at' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function line(
        int $id,
        int $versionId,
        int $projectId,
        int $taskId,
        string $currency,
        ?int $contractorId = null,
        ?int $costCenterId = null,
    ): array {
        return [
            'id' => $id,
            'forecast_version_id' => $versionId,
            'organization_id' => 1,
            'project_id' => $projectId,
            'currency' => $currency,
            'percent_complete' => '50.0000',
            'ac' => '40.00',
            'etc' => '60.00',
            'dimensions' => $this->json(array_filter([
                'task_id' => $taskId,
                'contractor_id' => $contractorId,
                'cost_center_id' => $costCenterId,
            ], static fn (mixed $value): bool => $value !== null)),
            'group_values' => $this->json([]),
            'source_row_refs' => $this->json([]),
        ];
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
