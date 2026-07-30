<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\Models\Organization;
use App\Models\Project;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceEvent;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionFormula;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionLifecycleCompleteness;
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

    public function test_approval_after_as_of_is_not_part_of_candidate_universe(): void
    {
        [$scope] = $this->insertLifecycleOwner(
            approvalDate: '2026-08-02',
            rejectedAt: null,
        );

        $gaps = (new AcceptedProductionLifecycleCompleteness)->inspect(
            $scope,
            new DateTimeImmutable('2026-07-30T23:59:59Z'),
            collect(),
        );

        self::assertSame([], $gaps);
    }

    public function test_rejection_before_as_of_without_reversal_event_is_an_explicit_gap(): void
    {
        [$scope, $actId, $lineId, $projectId, $organizationId] = $this->insertLifecycleOwner(
            approvalDate: '2026-07-20',
            rejectedAt: '2026-07-29T12:00:00Z',
        );
        $this->insertEvent(
            organizationId: $organizationId,
            projectId: $projectId,
            eventType: 'accepted',
            quantity: '2.500',
            recognizedAt: '2026-07-20T00:00:00Z',
            performanceActId: $actId,
            sourceLineId: $lineId,
        );

        $gaps = (new AcceptedProductionLifecycleCompleteness)->inspect(
            $scope,
            new DateTimeImmutable('2026-07-30T23:59:59Z'),
            ProductionAcceptanceEvent::query()->orderBy('transition_version')->get(),
        );

        self::assertCount(1, $gaps);
        self::assertSame('accepted_production_owner_history_unproven', $gaps[0]['reason']);
    }

    public function test_mutable_current_status_does_not_remove_legacy_candidate_from_gap_detection(): void
    {
        [$scope, $actId] = $this->insertLifecycleOwner(
            approvalDate: '2026-07-20',
            rejectedAt: null,
            currentStatus: 'draft',
        );

        $gaps = (new AcceptedProductionLifecycleCompleteness)->inspect(
            $scope,
            new DateTimeImmutable('2026-07-30T23:59:59Z'),
            collect(),
        );

        self::assertCount(1, $gaps);
        self::assertSame($actId, $gaps[0]['performance_act_id']);
        self::assertSame('accepted_production_owner_history_unproven', $gaps[0]['reason']);
    }

    private function insertEvent(
        int $organizationId,
        int $projectId,
        string $eventType,
        string $quantity,
        string $recognizedAt,
        ?int $reversesEventId = null,
        int $transitionVersion = 1,
        int $performanceActId = 51,
        int $sourceLineId = 91,
    ): int {
        return (int) DB::table('production_acceptance_events')->insertGetId([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'contract_id' => 21,
            'performance_act_id' => $performanceActId,
            'source_line_type' => 'performance_act_line',
            'source_line_id' => $sourceLineId,
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

    private function insertLifecycleOwner(
        string $approvalDate,
        ?string $rejectedAt,
        ?string $currentStatus = null,
    ): array {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $contractorId = (int) DB::table('contractors')->insertGetId([
            'organization_id' => $organization->id,
            'name' => 'Lifecycle contractor',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $contractId = (int) DB::table('contracts')->insertGetId([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractorId,
            'number' => 'LIFECYCLE-'.$project->id,
            'date' => '2026-07-01',
            'total_amount' => 1000,
            'status' => 'active',
            'created_at' => '2026-07-01T00:00:00Z',
            'updated_at' => '2026-07-01T00:00:00Z',
        ]);
        $actId = (int) DB::table('contract_performance_acts')->insertGetId([
            'contract_id' => $contractId,
            'project_id' => $project->id,
            'act_document_number' => 'ACT-'.$project->id,
            'act_date' => '2026-07-20',
            'amount' => 1000,
            'status' => $currentStatus ?? ($rejectedAt === null ? 'approved' : 'rejected'),
            'is_approved' => $currentStatus === null && $rejectedAt === null,
            'approval_date' => $approvalDate,
            'rejected_at' => $rejectedAt,
            'created_at' => '2026-07-10T00:00:00Z',
            'updated_at' => '2026-07-10T00:00:00Z',
        ]);
        $lineId = (int) DB::table('performance_act_lines')->insertGetId([
            'performance_act_id' => $actId,
            'line_type' => 'manual',
            'title' => 'Lifecycle line',
            'quantity' => 1,
            'unit_price' => 1000,
            'amount' => 1000,
            'created_at' => '2026-07-10T00:00:00Z',
            'updated_at' => '2026-07-10T00:00:00Z',
        ]);

        return [
            new ReportScope(
                (int) $organization->id,
                [(int) $organization->id],
                [(int) $project->id],
                [],
                new DateTimeZone('UTC'),
            ),
            $actId,
            $lineId,
            (int) $project->id,
            (int) $organization->id,
        ];
    }
}
