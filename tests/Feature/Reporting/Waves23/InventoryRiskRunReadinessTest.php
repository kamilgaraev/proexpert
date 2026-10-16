<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\InventoryRiskBuiltinPublishedReport;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Providers\InventoryRiskReportProvider;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Services\WarehouseInventoryEventRecorder;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Reporting\ReportExecutionContextBuilder;
use Tests\TestCase;

#[Group('postgres-contract')]
final class InventoryRiskRunReadinessTest extends TestCase
{
    #[DataProvider('openingEvidence')]
    public function test_real_inventory_binding_checks_opening_before_persisting_a_run(bool $hasOpening, bool $outsidePeriod): void
    {
        $organization = Organization::factory()->verified()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $eventAt = new DateTimeImmutable('2026-08-09T08:00:00Z');
        $warehouseId = DB::table('organization_warehouses')->insertGetId([
            'organization_id' => $organization->id, 'name' => 'Readiness', 'code' => 'READINESS-'.$organization->id,
            'warehouse_type' => 'central', 'is_main' => true, 'is_active' => true, 'created_at' => $eventAt, 'updated_at' => $eventAt,
        ]);
        $materialId = DB::table('materials')->insertGetId([
            'organization_id' => $organization->id, 'name' => 'Readiness material', 'is_active' => true,
            'created_at' => $eventAt, 'updated_at' => $eventAt,
        ]);
        $movement = WarehouseMovement::query()->create([
            'organization_id' => $organization->id, 'warehouse_id' => $warehouseId, 'material_id' => $materialId,
            'project_id' => $project->id, 'movement_type' => 'receipt', 'quantity' => '10.000', 'price' => '2.00',
            'movement_date' => $eventAt,
            'metadata' => [
                'reporting_source_version' => 1, 'reporting_inventory_project_id' => (int) $project->id,
                'unit_dimension' => 'mass', 'unit_code' => 'kg', 'unit_conversion_version' => 'kg:v1',
                'currency' => 'RUB', 'currency_source' => 'test_receipt',
                ...($hasOpening ? ['reporting_opening_basis' => 'verified_zero'] : []),
            ],
        ]);
        $event = app(WarehouseInventoryEventRecorder::class)->record($movement, 'receipt', null);
        self::assertSame($hasOpening ? 'verified_zero' : null, $event->opening_basis);
        $timezone = new DateTimeZone('UTC');
        $scope = new ReportScope((int) $organization->id, [(int) $organization->id], [(int) $project->id], [], $timezone);
        $context = (new ReportExecutionContextBuilder)
            ->actor(new ReportActor((int) $actor->id, 'active', ['reports.view', 'reports.run']))
            ->scope($scope)
            ->authorization(new AuthorizationDecisionContext('queue', (int) $organization->id, [(int) $organization->id], [(int) $project->id], [], $timezone, 'inventory-readiness-test', null))
            ->build();
        $definition = app(InventoryRiskBuiltinPublishedReport::class)->definition()->payload();
        $query = new ReportQuery($definition, $scope, new ReportFilterSet([
            'period_start' => '2026-08-09', 'period_end' => '2026-08-09',
        ]), [], new DateTimeImmutable('2026-08-10T00:00:00Z'), 'ru-RU');
        $before = DB::table('report_runs')->count();
        $auditBefore = DB::table('report_audit_intents')->count();
        $dispatchBefore = DB::table('report_dispatch_intents')->count();
        $store = app(ReportRunStore::class);
        $key = new IdempotencyKey('inventory-readiness-'.Str::uuid());

        try {
            $run = $store->createOrReuse($context, $query, null, $key);
            self::assertTrue($hasOpening, 'Missing opening evidence must prevent persistence.');
            self::assertSame(ReportRunStatus::QUEUED, $run->status);
            self::assertSame($before + 1, DB::table('report_runs')->count());
        } catch (ReportContractException $exception) {
            self::assertFalse($hasOpening);
            self::assertSame(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE, $exception->errorCode);
            self::assertSame([
                'missing_opening_count' => 1, 'unknown_unit_or_valuation_count' => 0,
                'invalid_transfer_pair_count' => 0, 'invalid_version_count' => 0, 'invalid_hash_count' => 0,
            ], $exception->sourceIssues?->toArray());
            self::assertSame($before, DB::table('report_runs')->count());
            self::assertSame($auditBefore, DB::table('report_audit_intents')->count());
            self::assertSame($dispatchBefore, DB::table('report_dispatch_intents')->count());

            return;
        }

        $auditAfter = DB::table('report_audit_intents')->count();
        $dispatchAfter = DB::table('report_dispatch_intents')->count();
        $provider = app(InventoryRiskReportProvider::class);
        $baseline = $outsidePeriod ? $provider->materialize($context, $query, new ReportProgress(0)) : null;
        if ($baseline !== null) {
            self::assertSame(1, $provider->result($context, $baseline)->metadata->rowCount);
        }
        $lateMovement = $movement->replicate();
        if ($outsidePeriod) {
            $lateMovement->movement_date = new DateTimeImmutable('2026-08-10T00:00:00Z');
        }
        $lateMovement->metadata = array_replace($movement->metadata, [
            'unit_conversion_version' => 'kg:v2', 'reporting_opening_basis' => null,
        ]);
        $lateMovement->save();
        app(WarehouseInventoryEventRecorder::class)->record($lateMovement, 'receipt', null);

        self::assertSame($run->id, $store->createOrReuse($context, $query, null, $key)->id);
        $differentQuery = new ReportQuery($definition, $scope, new ReportFilterSet([
            'period_start' => '2026-08-08', 'period_end' => '2026-08-09',
        ]), [], $query->asOf, 'ru-RU');
        try {
            $store->createOrReuse($context, $differentQuery, null, $key);
            self::fail('A different payload must conflict before readiness inspection.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_IDEMPOTENCY_CONFLICT, $exception->errorCode);
        }
        try {
            $newRun = $store->createOrReuse($context, $query, null, new IdempotencyKey('inventory-new-'.Str::uuid()));
            self::assertTrue($outsidePeriod, 'A new request must inspect incomplete events within its period.');
            self::assertSame(ReportRunStatus::QUEUED, $newRun->status);
        } catch (ReportContractException $exception) {
            self::assertFalse($outsidePeriod, 'An incomplete event after the reporting period must not block it.');
            self::assertSame(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE, $exception->errorCode);
            self::assertSame(1, $exception->sourceIssues?->missingOpeningCount);
        }
        self::assertSame($before + 1 + (int) $outsidePeriod, DB::table('report_runs')->count());
        self::assertSame($auditAfter + (int) $outsidePeriod, DB::table('report_audit_intents')->count());
        self::assertSame($dispatchAfter + (int) $outsidePeriod, DB::table('report_dispatch_intents')->count());
        if ($outsidePeriod) {
            self::assertNotNull($baseline);
            $replay = $provider->materialize($context, $query, new ReportProgress(0));
            self::assertSame($baseline->materializedSourceHash->value, $replay->materializedSourceHash->value);
            self::assertSame($baseline->id, $replay->id);
            self::assertSame(1, $provider->result($context, $replay)->metadata->rowCount);
            $planningGrain = [
                'organization_id' => $organization->id, 'warehouse_id' => $warehouseId,
                'project_id' => $project->id, 'material_id' => $materialId,
                'unit_dimension' => 'mass', 'unit_code' => 'kg', 'conversion_version' => 'kg:v1',
                'effective_from' => $query->asOf,
            ];
            foreach ([
                ['inventory_demand_snapshots', [
                    'horizon_days' => 7, 'approved_quantity' => '100.000000',
                    'source_type' => 'test_demand', 'source_id' => $materialId, 'source_version' => 1,
                    'effective_from' => $eventAt, 'approved_at' => $query->asOf,
                ]],
                ['inventory_demand_snapshots', [
                    'horizon_days' => 7, 'approved_quantity' => '100.000000',
                    'source_type' => 'test_demand', 'source_id' => $materialId, 'source_version' => 2,
                    'approved_at' => $eventAt,
                ]],
                ['inventory_reorder_policy_versions', [
                    'policy_version' => 1, 'minimum_quantity' => '1.000000',
                    'reorder_point_quantity' => '20.000000', 'target_quantity' => '100.000000',
                    'lead_time_days' => 2, 'safety_stock_quantity' => '3.000000',
                    'freshness_ttl_seconds' => 86400,
                ]],
            ] as [$table, $planning]) {
                DB::table($table)->insert([
                    ...$planningGrain, ...$planning,
                    'source_hash' => hash('sha256', $table.'-'.$organization->id.'-'.($planning['source_version'] ?? $planning['policy_version'])),
                ]);
                $replay = $provider->materialize($context, $query, new ReportProgress(0));
                self::assertSame($baseline->materializedSourceHash->value, $replay->materializedSourceHash->value, $table);
                self::assertSame($baseline->id, $replay->id, $table);
                self::assertSame(1, $provider->result($context, $replay)->metadata->rowCount, $table);
            }
        }
    }

    public static function openingEvidence(): array
    {
        return ['missing_opening' => [false, false], 'complete_opening' => [true, false], 'historical_excludes_later_event' => [true, true]];
    }
}
