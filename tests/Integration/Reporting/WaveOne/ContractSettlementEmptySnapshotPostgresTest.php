<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\WaveOne;

use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementExposureBuiltinPublishedReport;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementExposureOptionsService;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementOwnerTimestamp;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementProjectionService;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementQueryService;
use App\BusinessModules\Features\ContractManagement\Reporting\Models\ContractSettlementExposureSnapshot;
use App\BusinessModules\Features\ContractManagement\Reporting\Models\ContractSettlementOwnerHistoryCheckpoint;
use App\BusinessModules\Features\ContractManagement\Reporting\Models\ContractSettlementOwnerVersion;
use App\BusinessModules\Features\ContractManagement\Reporting\Models\ContractSettlementSourceFact;
use App\Models\Organization;
use App\Models\Project;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Support\Reporting\ReportExecutionContextBuilder;

#[Group('postgres')]
final class ContractSettlementEmptySnapshotPostgresTest extends TestCase
{
    #[Test]
    public function available_empty_owner_history_creates_a_repeatable_complete_empty_snapshot(): void
    {
        [$scope, $asOf] = $this->source(false);
        $options = $this->app->make(ContractSettlementExposureOptionsService::class)->options($scope, $asOf);
        self::assertTrue($options['available']);
        self::assertSame([], $options['projects']);
        $query = $this->query($scope, $asOf);
        $projection = $this->app->make(ContractSettlementProjectionService::class);

        $snapshot = $projection->materialize($scope, $query);
        $this->assertEmptySnapshot($snapshot->id);
        self::assertSame($snapshot->id, $projection->materialize($scope, $query)->id);
        self::assertSame(0, ContractSettlementSourceFact::query()->where('organization_id', $scope->organizationId)->count());
        $this->assertReadableEmptySnapshot($scope, $snapshot);
    }

    #[Test]
    public function aging_filter_excluding_all_valid_rows_creates_an_empty_snapshot(): void
    {
        [$scope, $asOf] = $this->source(true);
        $projection = $this->app->make(ContractSettlementProjectionService::class);
        $unfiltered = $projection->materialize($scope, $this->query($scope, $asOf));
        $unfilteredSnapshot = ContractSettlementExposureSnapshot::query()->findOrFail($unfiltered->id);
        self::assertSame(1, (int) $unfilteredSnapshot->row_count);
        self::assertSame(10000, $unfilteredSnapshot->totals['RUB']['effective_minor']);
        self::assertSame(10000, $unfilteredSnapshot->totals['RUB']['unperformed_exposure_minor']);
        self::assertSame(0, $unfilteredSnapshot->totals['RUB']['cash_minor']);

        $query = $this->query($scope, $asOf, ['aging_buckets' => ['over_90']]);
        $snapshot = $projection->materialize($scope, $query);
        $this->assertEmptySnapshot($snapshot->id);
        self::assertNotSame($unfiltered->id, $snapshot->id);
        self::assertSame($snapshot->id, $projection->materialize($scope, $query)->id);
        self::assertSame(1, ContractSettlementSourceFact::query()->where('organization_id', $scope->organizationId)->count());
        $this->assertReadableEmptySnapshot($scope, $snapshot);
    }

    #[Test]
    public function missing_checkpoint_still_rejects_materialization(): void
    {
        $scope = new ReportScope(799999981, [799999981], [], [], new DateTimeZone('UTC'));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('contract_settlement_owner_history_checkpoint_missing');

        $this->app->make(ContractSettlementProjectionService::class)->materialize(
            $scope,
            $this->query($scope, new DateTimeImmutable('2026-10-22T12:00:00Z')),
        );
    }

    #[Test]
    public function a_zero_checkpoint_without_any_contracts_is_a_ready_empty_source(): void
    {
        $organization = Organization::factory()->create();
        $checkpoint = ContractSettlementOwnerHistoryCheckpoint::query()->where('organization_id', $organization->id)->sole();
        $scope = new ReportScope((int) $organization->id, [(int) $organization->id], [], [], new DateTimeZone('UTC'));
        self::assertSame(0, array_sum($checkpoint->owner_counts));
        $asOf = $checkpoint->completed_at->addSeconds(2)->toDateTimeImmutable();
        self::assertTrue($this->app->make(ContractSettlementExposureOptionsService::class)->options($scope, $asOf)['available']);
        $snapshot = $this->app->make(ContractSettlementProjectionService::class)->materialize(
            $scope,
            $this->query($scope, $asOf),
        );
        $this->assertEmptySnapshot($snapshot->id);
        $this->assertReadableEmptySnapshot($scope, $snapshot);
    }

    #[Test]
    public function a_checkpoint_with_missing_declared_history_is_rejected(): void
    {
        $scope = new ReportScope(799999982, [799999982], [], [], new DateTimeZone('UTC'));
        $asOf = new DateTimeImmutable('2026-10-22T12:00:00Z');
        ContractSettlementOwnerHistoryCheckpoint::query()->create([
            'organization_id' => $scope->organizationId, 'completed_at' => $asOf->modify('-1 second'),
            'owner_counts' => ['contract' => 1, 'contract_allocation' => 1], 'source_hash' => str_repeat('a', 64),
        ]);
        $this->version($scope->organizationId, 'contract', 101, 'delete', [], $asOf);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('contract_settlement_owner_history_missing');

        $this->app->make(ContractSettlementProjectionService::class)->materialize($scope, $this->query($scope, $asOf));
    }

    private function assertReadableEmptySnapshot(ReportScope $scope, ReportSnapshotRef $snapshot): void
    {
        $context = (new ReportExecutionContextBuilder)->scope($scope)->authorization(new AuthorizationDecisionContext(
            'http', $scope->organizationId, $scope->holdingOrganizationIds, $scope->projectIds,
            $scope->resources, $scope->timezone, 'settlement-empty-test', null,
        ))->build();
        $service = $this->app->make(ContractSettlementQueryService::class);
        $result = $service->result($context, $snapshot);
        self::assertSame(0, $result->metadata->rowCount);
        self::assertSame([], $result->totals);
        self::assertCount(1, $result->provenance->sourceRefs);
        self::assertSame(0, $result->provenance->sourceRefs[0]->rowCount);
        $page = $service->page($context, $snapshot, new ReportWindowSort('contract_id', ReportSortDirection::ASC), null, 20);
        self::assertSame([], $page->rows);
        self::assertFalse($page->hasMore);
    }

    private function assertEmptySnapshot(string $id): void
    {
        $snapshot = ContractSettlementExposureSnapshot::query()->findOrFail($id);
        self::assertSame(0, (int) $snapshot->row_count);
        self::assertSame([], $snapshot->totals);
        self::assertSame(0, (int) $snapshot->coverage_numerator);
        self::assertSame(0, (int) $snapshot->coverage_denominator);
        self::assertSame('complete', $snapshot->quality_status);
        self::assertSame(0, $snapshot->rows()->count());
    }

    private function query(ReportScope $scope, DateTimeImmutable $asOf, array $filters = []): ReportQuery
    {
        return new ReportQuery(
            $this->app->make(ContractSettlementExposureBuiltinPublishedReport::class)->definition()->payload(),
            $scope,
            new ReportFilterSet($filters),
            [],
            $asOf,
            'ru-RU',
        );
    }

    private function source(bool $withContract): array
    {
        $organization = Organization::factory()->create();
        $checkpoint = ContractSettlementOwnerHistoryCheckpoint::query()->where('organization_id', $organization->id)->sole();
        $at = $checkpoint->completed_at->addSecond()->toDateTimeImmutable();
        $organizationId = (int) $organization->id;
        $contract = [
            'id' => 101, 'organization_id' => $organizationId, 'number' => 'PG-EMPTY-SNAPSHOT',
            'total_amount' => '100.00', 'currency' => 'RUB', 'contract_side_type' => 'general_contract', 'status' => 'active',
        ];
        $this->version($organizationId, 'contract', 101, $withContract ? 'upsert' : 'delete', $contract, $at);
        if ($withContract) {
            $project = Project::factory()->create(['organization_id' => $organizationId]);
            $this->version($organizationId, 'contract_allocation', 201, 'upsert', [
                'id' => 201, 'contract_id' => 101, 'project_id' => $project->id,
                'allocation_type' => 'fixed', 'allocated_amount' => '100.00', 'allocated_percentage' => null, 'is_active' => true,
            ], $at);
        }

        return [
            new ReportScope($organizationId, [$organizationId], [], [], new DateTimeZone('UTC')),
            $checkpoint->completed_at->addSeconds(2)->toDateTimeImmutable(),
        ];
    }

    private function version(int $organizationId, string $type, int $id, string $operation, array $payload, DateTimeImmutable $at): void
    {
        ContractSettlementOwnerVersion::query()->create([
            'organization_id' => $organizationId, 'owner_type' => $type, 'owner_id' => (string) $id,
            'version' => 1, 'operation' => $operation, 'occurred_at' => ContractSettlementOwnerTimestamp::database($at),
            'payload' => $payload, 'owner_hash' => hash('sha256', CanonicalJson::encode($payload)),
        ]);
    }
}
