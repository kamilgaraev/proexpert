<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionFormula;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionSnapshotMaterializer;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\ProductionAcceptanceRecognitionGrain;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\TestCase;

#[Group('postgresql')]
final class AcceptedProductionMaterializationPostgresTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        self::assertSame(
            'pgsql',
            DB::connection()->getDriverName(),
            'Accepted production materialization requires isolated PostgreSQL.',
        );
    }

    public function test_period_filter_keeps_reversal_on_its_transition_day_and_isolates_tenant(): void
    {
        $acceptedId = $this->insertEvent(
            organizationId: 3,
            projectId: 7,
            eventType: 'accepted',
            quantity: '2.500',
            recognizedAt: '2026-07-28T08:00:00Z',
        );
        $this->insertEvent(
            organizationId: 3,
            projectId: 7,
            eventType: 'reversed',
            quantity: '-2.500',
            recognizedAt: '2026-07-30T06:15:00Z',
            reversesEventId: $acceptedId,
            transitionVersion: 2,
        );
        $foreignAccepted = $this->insertEvent(
            organizationId: 4,
            projectId: 8,
            eventType: 'accepted',
            quantity: '9.000',
            recognizedAt: '2026-07-30T07:00:00Z',
        );
        $this->insertEvent(
            organizationId: 4,
            projectId: 8,
            eventType: 'reversed',
            quantity: '-9.000',
            recognizedAt: '2026-07-30T07:01:00Z',
            reversesEventId: $foreignAccepted,
            transitionVersion: 2,
        );
        $scope = new ReportScope(3, [3], [7], [], new DateTimeZone('UTC'));
        $definition = (new ReportDefinitionBuilder)
            ->code('accepted_production_progress')
            ->formulaVersion('accepted_production.v1')
            ->sourceSchemaVersion('production_acceptance_events_v1')
            ->payload();
        $query = new ReportQuery(
            $definition,
            $scope,
            new ReportFilterSet([
                'period' => ['from' => '2026-07-30', 'to' => '2026-07-30'],
                'statuses' => ['reversed'],
            ]),
            [],
            new DateTimeImmutable('2026-07-30T23:59:59Z'),
            'ru-RU',
        );

        $snapshot = (new AcceptedProductionSnapshotMaterializer(
            new AcceptedProductionFormula,
            new ProductionAcceptanceRecognitionGrain,
        ))->materialize($scope, $query);

        $rows = DB::table('accepted_production_rows')
            ->where('organization_id', 3)
            ->where('snapshot_id', $snapshot->id)
            ->get();
        self::assertCount(1, $rows);
        self::assertSame('2026-07-30', (string) $rows->first()->recognized_on);
        self::assertSame('-2.500', (string) $rows->first()->accepted_quantity);
        self::assertSame('reversed', (string) $rows->first()->event_status);
        self::assertSame(0, DB::table('accepted_production_rows')->where('organization_id', 4)->count());
    }

    public function test_keyset_index_covers_daily_recognition_order(): void
    {
        $definition = DB::table('pg_indexes')
            ->where('tablename', 'accepted_production_rows')
            ->where('indexname', 'accepted_production_row_keyset')
            ->value('indexdef');

        self::assertIsString($definition);
        self::assertStringContainsString(
            '(organization_id, snapshot_id, project_id, recognized_on, unit_dimension, unit_code, work_id, row_key)',
            $definition,
        );
    }

    public function test_acceptance_events_are_append_only_in_postgresql(): void
    {
        $eventId = $this->insertEvent(
            organizationId: 3,
            projectId: 7,
            eventType: 'accepted',
            quantity: '2.500',
            recognizedAt: '2026-07-30T06:15:00Z',
        );

        $this->expectException(QueryException::class);
        DB::transaction(
            static fn (): int => DB::table('production_acceptance_events')
                ->where('id', $eventId)
                ->update(['accepted_quantity_delta' => '3.000']),
        );
    }

    private function insertEvent(
        int $organizationId,
        int $projectId,
        string $eventType,
        string $quantity,
        string $recognizedAt,
        ?int $reversesEventId = null,
        int $transitionVersion = 1,
    ): int {
        return (int) DB::table('production_acceptance_events')->insertGetId([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'contract_id' => 21,
            'performance_act_id' => 51,
            'source_line_type' => 'performance_act_line',
            'source_line_id' => 91,
            'work_id' => 77,
            'task_id' => 31,
            'wbs_code' => '1.2',
            'zone' => 'A',
            'contractor_id' => 19,
            'transition_version' => $transitionVersion,
            'event_type' => $eventType,
            'reverses_event_id' => $reversesEventId,
            'accepted_quantity_delta' => $quantity,
            'planned_quantity' => '10.000',
            'reported_quantity' => '7.500',
            'unit_dimension' => 'volume',
            'unit_code' => 'm3',
            'conversion_version' => 'unit_4',
            'approved_rate_minor' => 125_045,
            'currency' => 'RUB',
            'currency_source' => 'performance_act_line.unit_price@contract_performance_act.currency',
            'recognized_at' => $recognizedAt,
            'actor_id' => 5,
            'source_hash' => hash('sha256', implode(':', [
                $organizationId,
                $projectId,
                $eventType,
                $recognizedAt,
            ])),
            'evidence_refs' => json_encode([
                ['type' => 'performance_act', 'id' => 51, 'project_id' => $projectId],
                ['type' => 'performance_act_line', 'id' => 91, 'project_id' => $projectId],
                ['type' => 'completed_work', 'id' => 77, 'project_id' => $projectId],
            ], JSON_THROW_ON_ERROR),
        ]);
    }
}
