<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use App\BusinessModules\Core\MultiOrganization\Reporting\HoldingPerformanceBuiltinPublishedReport;
use App\BusinessModules\Core\MultiOrganization\Reporting\HoldingPerformanceCandidateContract;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAcceptedWorkEventVersion;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingPaymentTransactionEventVersion;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\AcceptedWorkHoldingFactProducer;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingAllocationCheckpointSourceAssembler;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingAllocationFactProjector;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingPerformanceImmutableEventSource;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingPerformanceFormula;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingPerformanceProjectionCoverageInspector;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingPaymentEventFactProducer;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\Support\Reporting\PostgresProcessRaceHarness;
use Tests\TestCase;

#[Group('postgres')]
final class HoldingPerformanceProjectionPostgresTest extends TestCase
{
    #[Test]
    public function event_fact_previews_are_behaviorally_read_only(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        DB::beginTransaction();

        try {
            $identity = random_int(500000000, 599999999);
            $organizationId = $identity;
            $projectId = $identity + 1;
            $contractId = $identity + 2;
            $allocationId = $identity + 3;
            $coverageStartedAt = DB::table('holding_reporting_context_coverage')
                ->whereIn('source_code', [
                    'contract_dimensions',
                    'organization_hierarchy',
                    'allocation_dimensions',
                ])
                ->max('coverage_started_at');
            self::assertIsString($coverageStartedAt);
            $observedAt = CarbonImmutable::parse($coverageStartedAt);
            $recognizedAt = $observedAt->addDay()->setTime(12, 0);
            DB::table('holding_organization_hierarchy_events')->insert([
                'organization_id' => $organizationId,
                'parent_organization_id' => null,
                'is_active' => true,
                'hierarchy_level' => 0,
                'hierarchy_path' => (string) $organizationId,
                'observed_at' => $observedAt,
                'is_deleted' => false,
                'evidence_hash' => hash('sha256', 'preview-hierarchy-'.$identity),
            ]);
            DB::table('holding_contract_dimension_events')->insert([
                'contract_id' => $contractId,
                'organization_id' => $organizationId,
                'contractor_id' => null,
                'counterparty_organization_id' => null,
                'contract_status' => 'active',
                'work_type_category' => null,
                'total_amount' => '1000.00',
                'currency' => 'RUB',
                'observed_at' => $observedAt,
                'is_deleted' => false,
                'evidence_hash' => hash('sha256', 'preview-contract-'.$identity),
            ]);
            DB::table('holding_allocation_context_events')->insert([
                'allocation_id' => $allocationId,
                'contract_id' => $contractId,
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'allocation_type' => 'percentage',
                'allocated_amount' => null,
                'allocated_percentage' => '50.0000',
                'is_resolvable' => true,
                'is_active' => true,
                'observed_at' => $observedAt,
                'is_deleted' => false,
                'evidence_hash' => hash('sha256', 'preview-allocation-'.$identity),
            ]);

            $acceptedActiveId = DB::table('holding_accepted_work_event_versions')->insertGetId([
                'event_key' => 'preview-accepted-active-'.str()->uuid(),
                'performance_act_id' => $identity + 10,
                'contract_id' => $contractId,
                'project_id' => $projectId,
                'organization_id' => $organizationId,
                'active' => true,
                'amount' => '125.50',
                'status' => 'approved',
                'occurred_at' => $recognizedAt,
                'recorded_at' => $recognizedAt->addSecond(),
                'history_complete' => true,
                'source_hash' => hash('sha256', 'preview-accepted-active-'.$identity),
            ]);
            $acceptedInactiveId = DB::table('holding_accepted_work_event_versions')->insertGetId([
                'event_key' => 'preview-accepted-inactive-'.str()->uuid(),
                'performance_act_id' => $identity + 11,
                'contract_id' => $contractId,
                'project_id' => $projectId,
                'organization_id' => $organizationId,
                'active' => false,
                'amount' => '125.50',
                'status' => 'draft',
                'occurred_at' => $recognizedAt,
                'recorded_at' => $recognizedAt->addSeconds(2),
                'history_complete' => true,
                'source_hash' => hash('sha256', 'preview-accepted-inactive-'.$identity),
            ]);
            $acceptedMissingId = DB::table('holding_accepted_work_event_versions')->insertGetId([
                'event_key' => 'preview-accepted-missing-'.str()->uuid(),
                'performance_act_id' => $identity + 12,
                'contract_id' => $contractId,
                'project_id' => $projectId,
                'organization_id' => $organizationId,
                'active' => true,
                'amount' => '125.50',
                'status' => 'approved',
                'occurred_at' => $recognizedAt,
                'recorded_at' => $recognizedAt->addSeconds(3),
                'history_complete' => false,
                'source_hash' => hash('sha256', 'preview-accepted-missing-'.$identity),
            ]);
            $paymentActiveId = DB::table('holding_payment_transaction_event_versions')->insertGetId([
                'transaction_id' => $identity + 20,
                'payment_document_id' => $identity + 21,
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'contract_id' => $contractId,
                'document_organization_id' => $organizationId,
                'document_project_id' => $projectId,
                'contract_organization_id' => $organizationId,
                'contract_project_id' => $projectId,
                'amount' => '200.00',
                'currency' => 'RUB',
                'status' => 'completed',
                'active' => true,
                'recognized_at' => $recognizedAt,
                'occurred_at' => $recognizedAt,
                'recorded_at' => $recognizedAt->addSecond(),
                'history_complete' => true,
                'source_hash' => hash('sha256', 'preview-payment-active-'.$identity),
            ]);
            $paymentInactiveId = DB::table('holding_payment_transaction_event_versions')->insertGetId([
                'transaction_id' => $identity + 22,
                'payment_document_id' => $identity + 23,
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'contract_id' => $contractId,
                'document_organization_id' => $organizationId,
                'document_project_id' => $projectId,
                'contract_organization_id' => $organizationId,
                'contract_project_id' => $projectId,
                'amount' => null,
                'currency' => null,
                'status' => 'cancelled',
                'active' => false,
                'recognized_at' => $recognizedAt,
                'occurred_at' => $recognizedAt,
                'recorded_at' => $recognizedAt->addSeconds(2),
                'history_complete' => true,
                'source_hash' => hash('sha256', 'preview-payment-inactive-'.$identity),
            ]);
            $paymentMissingId = DB::table('holding_payment_transaction_event_versions')->insertGetId([
                'transaction_id' => $identity + 24,
                'payment_document_id' => $identity + 25,
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'contract_id' => $contractId,
                'document_organization_id' => $organizationId,
                'document_project_id' => $projectId,
                'contract_organization_id' => $organizationId,
                'contract_project_id' => $projectId,
                'amount' => '200.00',
                'currency' => 'RUB',
                'status' => 'completed',
                'active' => true,
                'recognized_at' => $recognizedAt,
                'occurred_at' => $recognizedAt,
                'recorded_at' => $recognizedAt->addSeconds(3),
                'history_complete' => false,
                'source_hash' => hash('sha256', 'preview-payment-missing-'.$identity),
            ]);

            $accepted = $this->app->make(AcceptedWorkHoldingFactProducer::class);
            $payments = $this->app->make(HoldingPaymentEventFactProducer::class);
            $factCount = DB::table('holding_allocation_fact_versions')
                ->where('organization_id', $organizationId)
                ->count();
            $gapCount = DB::table('holding_allocation_projection_gaps')
                ->where('organization_id', $organizationId)
                ->count();
            $acceptedFact = $accepted->previewEvent(HoldingAcceptedWorkEventVersion::query()->findOrFail($acceptedActiveId));
            $paymentFact = $payments->previewEvent(HoldingPaymentTransactionEventVersion::query()->findOrFail($paymentActiveId));

            self::assertNotNull($acceptedFact);
            self::assertSame(12_550, $acceptedFact->amountMinor);
            self::assertSame('accepted_accrual', $acceptedFact->monetaryBasis);
            self::assertNotNull($paymentFact);
            self::assertSame(10_000, $paymentFact->amountMinor);
            self::assertSame('cash', $paymentFact->monetaryBasis);
            self::assertNull($accepted->previewEvent(HoldingAcceptedWorkEventVersion::query()->findOrFail($acceptedInactiveId)));
            self::assertNull($accepted->previewEvent(HoldingAcceptedWorkEventVersion::query()->findOrFail($acceptedMissingId)));
            self::assertNull($payments->previewEvent(HoldingPaymentTransactionEventVersion::query()->findOrFail($paymentInactiveId)));
            self::assertNull($payments->previewEvent(HoldingPaymentTransactionEventVersion::query()->findOrFail($paymentMissingId)));
            self::assertSame($factCount, DB::table('holding_allocation_fact_versions')
                ->where('organization_id', $organizationId)
                ->count());
            self::assertSame($gapCount, DB::table('holding_allocation_projection_gaps')
                ->where('organization_id', $organizationId)
                ->count());
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function checkpoint_day_is_rejected_until_the_first_complete_business_day(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        DB::beginTransaction();

        try {
            $identity = random_int(800000000, 899999999);
            $checkpointAt = '2026-08-05 15:00:00+00';
            $sourceHash = hash('sha256', 'checkpoint-day-payment-'.$identity);
            DB::table('holding_payment_transaction_event_versions')->insert([
                'transaction_id' => $identity,
                'payment_document_id' => $identity + 1,
                'organization_id' => $identity + 2,
                'project_id' => $identity + 3,
                'contract_id' => $identity + 4,
                'document_organization_id' => $identity + 2,
                'document_project_id' => $identity + 3,
                'contract_organization_id' => $identity + 2,
                'contract_project_id' => $identity + 3,
                'amount' => '100.00',
                'currency' => 'RUB',
                'status' => 'completed',
                'active' => true,
                'recognized_at' => '2026-08-05 00:00:00+00',
                'occurred_at' => $checkpointAt,
                'recorded_at' => $checkpointAt,
                'history_complete' => true,
                'source_hash' => $sourceHash,
            ]);
            DB::table('holding_payment_event_coverage_checkpoints')->insert([
                'started_at' => $checkpointAt,
                'source_max_transaction_id' => $identity,
                'source_count' => 1,
                'captured_count' => 1,
                'gap_count' => 0,
                'content_hash' => hash('sha256', $sourceHash),
            ]);

            $source = new HoldingPerformanceImmutableEventSource;
            $timezone = new DateTimeZone('Europe/Moscow');
            $coverageStartedAt = $source->coverageStartedAt(
                new DateTimeImmutable('2026-08-01 00:00:00+00'),
                $timezone,
            );

            self::assertSame('2026-08-06 00:00:00+03:00', $coverageStartedAt->format('Y-m-d H:i:sP'));
            self::assertTrue($source->paymentVersions(
                [$identity + 2],
                [$identity + 3],
                $coverageStartedAt,
                new DateTimeImmutable('2026-08-06 23:59:59+03:00'),
                new DateTimeImmutable('2026-08-07 00:00:00+03:00'),
            )->isEmpty());

            try {
                $source->assertPeriodCovered(
                    ['period_from' => '2026-08-05', 'period_to' => '2026-08-05'],
                    $coverageStartedAt,
                    $timezone,
                );
                self::fail('Checkpoint day must not be treated as a complete business day.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('holding_performance_period_outside_coverage', $exception->getMessage());
            }
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function inactive_lifecycle_versions_are_accounted_for_without_contributing_facts(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        DB::beginTransaction();

        try {
            $identity = random_int(900000000, 949999999);
            DB::table('holding_accepted_work_event_versions')->insert([
                'event_key' => 'test-event-'.str()->uuid(),
                'performance_act_id' => $identity,
                'contract_id' => $identity + 1,
                'project_id' => $identity + 2,
                'organization_id' => $identity + 3,
                'active' => true,
                'amount' => '100.00',
                'status' => 'approved',
                'occurred_at' => '2026-08-06 09:00:00+00',
                'recorded_at' => '2026-08-06 10:00:00+00',
                'history_complete' => true,
                'source_hash' => hash('sha256', 'accepted-active-'.$identity),
            ]);
            $acceptedTombstoneId = DB::table('holding_accepted_work_event_versions')->insertGetId([
                'event_key' => 'test-event-'.str()->uuid(),
                'performance_act_id' => $identity,
                'contract_id' => $identity + 1,
                'project_id' => $identity + 2,
                'organization_id' => $identity + 3,
                'active' => false,
                'amount' => '100.00',
                'status' => 'draft',
                'occurred_at' => '2026-08-06 09:00:00+00',
                'recorded_at' => '2026-08-06 11:00:00+00',
                'history_complete' => true,
                'source_hash' => hash('sha256', 'accepted-tombstone-'.$identity),
            ]);
            DB::table('holding_payment_transaction_event_versions')->insert([
                'transaction_id' => $identity + 10,
                'payment_document_id' => $identity + 11,
                'organization_id' => $identity + 3,
                'project_id' => $identity + 2,
                'contract_id' => $identity + 1,
                'document_organization_id' => $identity + 3,
                'document_project_id' => $identity + 2,
                'contract_organization_id' => $identity + 3,
                'contract_project_id' => $identity + 2,
                'amount' => '50.00',
                'currency' => 'RUB',
                'status' => 'completed',
                'active' => true,
                'recognized_at' => '2026-08-06 00:00:00+00',
                'occurred_at' => '2026-08-06 10:00:00+00',
                'recorded_at' => '2026-08-06 10:00:01+00',
                'history_complete' => true,
                'source_hash' => hash('sha256', 'payment-active-'.$identity),
            ]);
            $paymentTombstoneId = DB::table('holding_payment_transaction_event_versions')->insertGetId([
                'transaction_id' => $identity + 10,
                'payment_document_id' => $identity + 11,
                'organization_id' => $identity + 3,
                'project_id' => $identity + 2,
                'contract_id' => $identity + 1,
                'document_organization_id' => $identity + 3,
                'document_project_id' => $identity + 2,
                'contract_organization_id' => $identity + 3,
                'contract_project_id' => $identity + 2,
                'amount' => null,
                'currency' => null,
                'status' => 'cancelled',
                'active' => false,
                'recognized_at' => '2026-08-06 00:00:00+00',
                'occurred_at' => '2026-08-06 11:00:00+00',
                'recorded_at' => '2026-08-06 11:00:01+00',
                'history_complete' => true,
                'source_hash' => hash('sha256', 'payment-tombstone-'.$identity),
            ]);

            $coverage = $this->app->make(HoldingPerformanceProjectionCoverageInspector::class)->inspect(
                $identity + 3,
                [$identity + 3],
                [$identity + 2],
                new DateTimeImmutable('2026-08-06 00:00:00+00'),
                new DateTimeImmutable('2026-08-06 23:59:59+00'),
                new DateTimeImmutable('2026-08-07 00:00:00+00'),
                requirePersisted: false,
            );

            self::assertSame([$acceptedTombstoneId], $coverage->eligibleActVersionIds);
            self::assertSame([$acceptedTombstoneId], $coverage->projectedActVersionIds);
            self::assertSame([], $coverage->contributingActVersionIds);
            self::assertSame([$paymentTombstoneId], $coverage->eligiblePaymentVersionIds);
            self::assertSame([$paymentTombstoneId], $coverage->projectedPaymentVersionIds);
            self::assertSame([], $coverage->contributingPaymentVersionIds);
            self::assertTrue($coverage->complete());
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function sealed_contract_checkpoint_contributes_on_the_first_allowed_period_day(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        DB::beginTransaction();

        try {
            $identity = random_int(950000000, 969999999);
            $holdingId = $identity;
            $projectId = $identity + 1;
            $contractId = $identity + 2;
            $allocationId = $identity + 3;
            $coverageRows = DB::table('holding_reporting_context_coverage')
                ->whereIn('source_code', [
                    'contract_dimensions',
                    'organization_hierarchy',
                    'allocation_dimensions',
                    'allocation_amount_dimensions',
                ])
                ->get(['coverage_started_at']);
            self::assertCount(4, $coverageRows);
            $checkpointAt = $coverageRows
                ->map(static fn (object $row): CarbonImmutable => CarbonImmutable::parse(
                    (string) $row->coverage_started_at,
                ))
                ->sortByDesc(static fn (CarbonImmutable $value): int => $value->getTimestamp())
                ->first();
            self::assertInstanceOf(CarbonImmutable::class, $checkpointAt);
            $timezone = new DateTimeZone('Europe/Moscow');
            $openingStateAt = (new HoldingPerformanceImmutableEventSource)->firstCompleteBusinessDay(
                $checkpointAt,
                $timezone,
            );
            $periodDate = $openingStateAt->toDateString();
            $laterAt = CarbonImmutable::instance($openingStateAt)->addMonth()->setTime(12, 0);
            DB::table('holding_organization_hierarchy_events')->insert([
                'organization_id' => $holdingId,
                'parent_organization_id' => null,
                'is_active' => true,
                'hierarchy_level' => 0,
                'hierarchy_path' => (string) $holdingId,
                'observed_at' => $checkpointAt,
                'is_deleted' => false,
                'evidence_hash' => hash('sha256', 'hierarchy-'.$identity),
            ]);
            DB::table('holding_contract_dimension_events')->insert([
                'contract_id' => $contractId,
                'organization_id' => $holdingId,
                'contractor_id' => null,
                'counterparty_organization_id' => null,
                'contract_status' => 'active',
                'work_type_category' => null,
                'total_amount' => '100.00',
                'currency' => 'RUB',
                'observed_at' => $checkpointAt,
                'is_deleted' => false,
                'evidence_hash' => hash('sha256', 'contract-'.$identity),
            ]);
            DB::table('holding_allocation_context_events')->insert([
                'allocation_id' => $allocationId,
                'contract_id' => $contractId,
                'organization_id' => $holdingId,
                'project_id' => $projectId,
                'allocation_type' => 'fixed',
                'allocated_amount' => '100.00',
                'allocated_percentage' => null,
                'is_resolvable' => true,
                'is_active' => true,
                'observed_at' => $checkpointAt,
                'is_deleted' => false,
                'evidence_hash' => hash('sha256', 'allocation-'.$identity),
            ]);
            DB::table('holding_contract_dimension_events')->insert([
                'contract_id' => $contractId,
                'organization_id' => $holdingId,
                'contractor_id' => null,
                'counterparty_organization_id' => null,
                'contract_status' => 'active',
                'work_type_category' => null,
                'total_amount' => '250.00',
                'currency' => 'RUB',
                'observed_at' => $laterAt,
                'is_deleted' => false,
                'evidence_hash' => hash('sha256', 'later-contract-'.$identity),
            ]);
            DB::table('holding_allocation_context_events')->insert([
                'allocation_id' => $allocationId,
                'contract_id' => $contractId,
                'organization_id' => $holdingId,
                'project_id' => $projectId,
                'allocation_type' => 'fixed',
                'allocated_amount' => '250.00',
                'allocated_percentage' => null,
                'is_resolvable' => true,
                'is_active' => true,
                'observed_at' => $laterAt,
                'is_deleted' => false,
                'evidence_hash' => hash('sha256', 'later-allocation-'.$identity),
            ]);

            $definition = (new HoldingPerformanceBuiltinPublishedReport(new HoldingPerformanceCandidateContract))
                ->definition()
                ->payload();
            $scope = new ReportScope(
                $holdingId,
                [$holdingId],
                [$projectId],
                [],
                $timezone,
            );
            $query = new ReportQuery(
                $definition,
                $scope,
                new ReportFilterSet([
                    'period_from' => $periodDate,
                    'period_to' => $laterAt->toDateString(),
                ]),
                [],
                $laterAt->endOfDay(),
                'ru-RU',
            );

            $assembler = $this->app->make(HoldingAllocationCheckpointSourceAssembler::class);
            self::assertSame($openingStateAt->getTimestamp(), $assembler->openingBoundary($query)->getTimestamp());
            $periodToOnly = new ReportQuery(
                $definition,
                $scope,
                new ReportFilterSet(['period_to' => $laterAt->toDateString()]),
                [],
                $laterAt->addMonth(),
                'ru-RU',
            );
            self::assertSame(
                $laterAt->toDateString(),
                $assembler->openingBoundary($periodToOnly)->format('Y-m-d'),
            );
            $batch = $assembler->assembleOpeningState(
                $scope,
                $query,
                $openingStateAt,
            );

            self::assertCount(1, $batch->sources);
            self::assertSame(
                $checkpointAt->setTimezone($timezone)->toDateString(),
                $batch->sources[0]->fact->recognizedOn,
            );
            self::assertSame(10_000, $batch->sources[0]->fact->amountMinor);
            self::assertSame(
                $checkpointAt->getTimestamp(),
                $batch->sources[0]->evidence['business_effective_at']->getTimestamp(),
            );
            self::assertSame(
                substr($periodDate, 0, 7).'-01',
                (new HoldingPerformanceFormula)->row(
                    $batch->sources[0]->fact,
                    substr($periodDate, 0, 7).'-01',
                )->periodStart,
            );
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function persisted_projection_coverage_rejects_a_fact_attributed_to_another_holding(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        DB::beginTransaction();

        try {
            $identity = random_int(970000000, 989999999);
            $organizationId = $identity;
            $projectId = $identity + 1;
            $sourceVersion = $identity + 2;
            $foreignHoldingId = $identity + 3;
            $fact = $this->app->make(HoldingAllocationFactProjector::class)->project([
                'organization_id' => $organizationId,
                'holding_id' => $foreignHoldingId,
                'hierarchy_version' => hash('sha256', 'foreign-hierarchy-'.$identity),
                'hierarchy_organization_ids' => [$organizationId, $foreignHoldingId],
                'contributor_organization_id' => $organizationId,
                'project_id' => $projectId,
                'contract_id' => $identity + 4,
                'contractor_id' => null,
                'contract_status' => 'active',
                'work_type_category' => null,
                'contract_dimension_hash' => hash('sha256', 'dimension-'.$identity),
                'allocation_id' => $identity + 5,
                'source_type' => 'performance_act',
                'source_id' => $identity + 6,
                'source_version' => $sourceVersion,
                'monetary_basis' => 'accepted_accrual',
                'allocated_amount_minor' => 10_000,
                'allocated_percentage' => null,
                'contract_amount_minor' => 10_000,
                'currency' => 'RUB',
                'currency_source' => 'contract_dimension_checkpoint',
                'tax_basis' => 'contract_total',
                'recognized_on' => '2026-08-06',
                'business_effective_at' => new DateTimeImmutable('2026-08-06 09:00:00+00:00'),
                'source_refs' => [],
            ]);
            $this->app->make(HoldingAllocationFactProjector::class)->persist($fact, [
                'business_effective_at' => new DateTimeImmutable('2026-08-06 09:00:00+00:00'),
            ]);

            $method = new ReflectionMethod(HoldingPerformanceProjectionCoverageInspector::class, 'persistedVersionIds');
            $method->setAccessible(true);
            $inspector = $this->app->make(HoldingPerformanceProjectionCoverageInspector::class);
            $arguments = [
                [$organizationId],
                [$projectId],
                new DateTimeImmutable('2026-08-06 00:00:00+00:00'),
                new DateTimeImmutable('2026-08-06 23:59:59+00:00'),
                now()->addMinute()->toImmutable(),
                'performance_act',
                'accepted_accrual',
                [$sourceVersion],
            ];

            self::assertSame([], $method->invoke($inspector, $identity + 7, ...$arguments));
            self::assertSame([$sourceVersion], $method->invoke($inspector, $foreignHoldingId, ...$arguments));
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function backdated_accepted_work_correction_wins_by_capture_version(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        $identity = random_int(700000000, 799999999);
        DB::table('holding_accepted_work_event_versions')->insert([
            'event_key' => 'test-event-'.str()->uuid(),
            'performance_act_id' => $identity,
            'contract_id' => $identity + 1,
            'project_id' => $identity + 2,
            'organization_id' => $identity + 3,
            'active' => true,
            'amount' => '100.00',
            'status' => 'approved',
            'occurred_at' => '2026-08-05 09:00:00+00',
            'recorded_at' => '2026-08-05 10:00:00+00',
            'history_complete' => true,
            'source_hash' => hash('sha256', 'accepted-work-original-'.$identity),
        ]);
        $correctionId = DB::table('holding_accepted_work_event_versions')->insertGetId([
            'event_key' => 'test-event-'.str()->uuid(),
            'performance_act_id' => $identity,
            'contract_id' => $identity + 1,
            'project_id' => $identity + 2,
            'organization_id' => $identity + 3,
            'active' => true,
            'amount' => '125.00',
            'status' => 'approved',
            'occurred_at' => '2026-08-01 09:00:00+00',
            'recorded_at' => '2026-08-05 11:00:00+00',
            'history_complete' => true,
            'source_hash' => hash('sha256', 'accepted-work-correction-'.$identity),
        ]);

        $source = new HoldingPerformanceImmutableEventSource;
        $versions = $source->acceptedWorkVersions(
            [$identity + 3],
            [$identity + 2],
            new DateTimeImmutable('2026-08-01 00:00:00+00'),
            new DateTimeImmutable('2026-08-06 00:00:00+00'),
            new DateTimeImmutable('2026-08-06 00:00:00+00'),
        );

        self::assertCount(1, $versions);
        self::assertSame($correctionId, (int) $versions->first()->getKey());
        self::assertSame('125.00', (string) $versions->first()->amount);
        self::assertTrue($source->acceptedWorkVersions(
            [$identity + 3],
            [$identity + 2],
            new DateTimeImmutable('2026-08-03 00:00:00+00'),
            new DateTimeImmutable('2026-08-06 00:00:00+00'),
            new DateTimeImmutable('2026-08-06 00:00:00+00'),
        )->isEmpty());
    }

    #[Test]
    public function immutable_contract_version_evidence_rejects_mutation(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        $id = DB::table('holding_contract_version_evidence')->insertGetId([
            'allocation_history_id' => 991001,
            'contract_id' => 881001,
            'organization_id' => 771001,
            'total_amount' => '1234.56',
            'contractor_id' => null,
            'counterparty_organization_id' => null,
            'recorded_at' => '2026-07-30 10:00:00+00',
            'source_hash' => hash('sha256', 'immutable-contract-version'),
        ]);

        $this->expectException(QueryException::class);
        DB::table('holding_contract_version_evidence')
            ->where('id', $id)
            ->update(['total_amount' => '9999.99']);
    }

    #[Test]
    public function accepted_work_event_version_rejects_mutation(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        $id = DB::table('holding_accepted_work_event_versions')->insertGetId([
            'event_key' => 'test-event-'.str()->uuid(),
            'performance_act_id' => 991002,
            'contract_id' => 881002,
            'project_id' => 771002,
            'organization_id' => 661002,
            'active' => true,
            'amount' => '100.00',
            'status' => 'approved',
            'occurred_at' => '2026-07-30 10:00:00+00',
            'recorded_at' => '2026-07-30 10:00:01+00',
            'source_hash' => hash('sha256', 'immutable-accepted-work-event'),
        ]);

        $this->expectException(QueryException::class);
        DB::table('holding_accepted_work_event_versions')
            ->where('id', $id)
            ->update(['active' => false]);
    }

    #[Test]
    public function payment_event_version_rejects_mutation(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        $id = DB::table('holding_payment_transaction_event_versions')->insertGetId([
            'transaction_id' => 991005,
            'payment_document_id' => 881005,
            'organization_id' => 771005,
            'project_id' => 661005,
            'contract_id' => 551005,
            'document_organization_id' => 771005,
            'document_project_id' => 661005,
            'contract_organization_id' => 771005,
            'contract_project_id' => 661005,
            'amount' => '-125.50',
            'currency' => 'RUB',
            'status' => 'completed',
            'active' => true,
            'recognized_at' => '2026-08-05 10:00:00+00',
            'occurred_at' => '2026-08-05 10:01:00+00',
            'recorded_at' => '2026-08-05 10:01:01+00',
            'history_complete' => true,
            'source_hash' => hash('sha256', 'immutable-payment-event'),
        ]);

        $this->expectException(QueryException::class);
        DB::table('holding_payment_transaction_event_versions')
            ->where('id', $id)
            ->update(['amount' => '0.00']);
    }

    #[Test]
    public function payment_capture_fails_closed_on_scope_mismatch_and_closes_lost_eligibility(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        DB::beginTransaction();

        try {
            $organization = Organization::withoutEvents(
                static fn (): Organization => Organization::factory()->create(),
            );
            $project = Project::withoutEvents(
                static fn (): Project => Project::factory()->for($organization)->create(),
            );
            $foreignOrganization = Organization::withoutEvents(
                static fn (): Organization => Organization::factory()->create(),
            );
            $foreignProject = Project::withoutEvents(
                static fn (): Project => Project::factory()->for($foreignOrganization)->create(),
            );
            $contractor = Contractor::withoutEvents(static fn (): Contractor => Contractor::create([
                'organization_id' => $foreignOrganization->id,
                'name' => 'Payment lifecycle '.str()->uuid(),
                'contractor_type' => Contractor::TYPE_MANUAL,
            ]));
            $contract = Contract::withoutEvents(static fn (): Contract => Contract::create([
                'organization_id' => $foreignOrganization->id,
                'project_id' => $foreignProject->id,
                'contractor_id' => $contractor->id,
                'number' => 'PAY-LIFECYCLE-'.str()->uuid(),
                'date' => '2026-08-05',
                'total_amount' => '100.00',
                'status' => 'active',
            ]));
            $now = now();
            $documentId = DB::table('payment_documents')->insertGetId([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'document_type' => 'payment_order',
                'document_number' => 'PAY-DOC-'.str()->uuid(),
                'document_date' => '2026-08-05',
                'direction' => 'outgoing',
                'invoiceable_type' => Contract::class,
                'invoiceable_id' => $contract->id,
                'amount' => '100.00',
                'currency' => 'RUB',
                'vat_amount' => '0.00',
                'vat_rate' => '0.00',
                'amount_without_vat' => '100.00',
                'paid_amount' => '100.00',
                'remaining_amount' => '0.00',
                'status' => 'paid',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $transactionId = DB::table('payment_transactions')->insertGetId([
                'payment_document_id' => $documentId,
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'amount' => '100.00',
                'currency' => 'RUB',
                'payment_method' => 'bank_transfer',
                'reference_number' => 'PAY-TX-'.str()->uuid(),
                'transaction_date' => '2026-08-05',
                'value_date' => '2026-08-05',
                'status' => 'completed',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            self::assertFalse((bool) DB::table('holding_payment_transaction_event_versions')
                ->where('transaction_id', $transactionId)
                ->latest('id')
                ->value('history_complete'));

            DB::table('contracts')->where('id', $contract->id)->update([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'updated_at' => $now->copy()->addSecond(),
            ]);

            $contractScopeEvents = DB::table('holding_payment_transaction_event_versions')
                ->where('transaction_id', $transactionId)
                ->latest('id')
                ->limit(2)
                ->get(['active', 'history_complete']);
            self::assertSame([true, false], $contractScopeEvents->pluck('active')->map(
                static fn (mixed $value): bool => (bool) $value,
            )->all());
            self::assertTrue((bool) $contractScopeEvents->first()->history_complete);

            $refundId = DB::table('payment_transactions')->insertGetId([
                'payment_document_id' => $documentId,
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'amount' => '-25.00',
                'currency' => 'RUB',
                'payment_method' => 'bank_transfer',
                'reference_number' => 'PAY-REFUND-'.str()->uuid(),
                'transaction_date' => '2026-08-05',
                'value_date' => '2026-08-05',
                'status' => 'completed',
                'created_at' => $now,
                'updated_at' => $now->copy()->addSeconds(2),
            ]);
            $latestIds = DB::table('holding_payment_transaction_event_versions')
                ->selectRaw('MAX(id)')
                ->whereIn('transaction_id', [$transactionId, $refundId])
                ->groupBy('transaction_id');
            self::assertSame('75.00', number_format((float) DB::table('holding_payment_transaction_event_versions')
                ->whereIn('id', $latestIds)
                ->where('active', true)
                ->where('history_complete', true)
                ->sum('amount'), 2, '.', ''));

            DB::table('payment_transactions')->where('id', $transactionId)->update([
                'project_id' => null,
                'updated_at' => $now->copy()->addSeconds(3),
            ]);

            $lostScopeEvents = DB::table('holding_payment_transaction_event_versions')
                ->where('transaction_id', $transactionId)
                ->latest('id')
                ->limit(2)
                ->get(['active', 'history_complete']);
            self::assertSame([true, false], $lostScopeEvents->pluck('active')->map(
                static fn (mixed $value): bool => (bool) $value,
            )->all());
            self::assertFalse((bool) $lostScopeEvents->first()->history_complete);

            DB::table('payment_documents')->where('id', $documentId)->update([
                'invoiceable_type' => null,
                'invoiceable_id' => null,
                'updated_at' => $now->copy()->addSeconds(4),
            ]);

            self::assertFalse((bool) DB::table('holding_payment_transaction_event_versions')
                ->where('transaction_id', $refundId)
                ->latest('id')
                ->value('active'));
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function accepted_work_event_identity_converges_under_process_race(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        $suffix = bin2hex(random_bytes(6));
        $eventKey = 'race-event-'.$suffix;
        $harness = new PostgresProcessRaceHarness(
            sys_get_temp_dir().DIRECTORY_SEPARATOR.'accepted-work-event-race-'.$suffix,
        );
        $children = [];

        try {
            foreach ([1, 2] as $worker) {
                $children[] = $harness->spawn($worker, static function () use ($eventKey): array {
                    $contract = new \App\Models\Contract;
                    $contract->setRawAttributes(['id' => 881003, 'organization_id' => 661003], true);
                    $act = new \App\Models\ContractPerformanceAct;
                    $act->setRawAttributes([
                        'id' => 991003,
                        'contract_id' => 881003,
                        'project_id' => 771003,
                        'is_approved' => true,
                        'amount' => '100.00',
                        'status' => \App\Models\ContractPerformanceAct::STATUS_APPROVED,
                    ], true);
                    $act->setRelation('contract', $contract);
                    $record = \App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAcceptedWorkEventVersion::record(
                        $act,
                        true,
                        new \DateTimeImmutable('2026-07-30T10:00:00+00:00'),
                        $eventKey,
                        true,
                    );

                    return ['id' => (int) $record->getKey()];
                });
            }
            $harness->release(1);
            $harness->release(2);
            $harness->waitForChildren($children);
            $children = [];

            self::assertSame($harness->result(1)['id'], $harness->result(2)['id']);
            self::assertSame(
                1,
                DB::table('holding_accepted_work_event_versions')->where('event_key', $eventKey)->count(),
            );
        } finally {
            $harness->terminateAndReap($children);
            $harness->cleanup();
        }
    }

    #[Test]
    public function public_accepted_work_record_detects_conflicting_payload_under_process_race(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        $suffix = bin2hex(random_bytes(6));
        $eventKey = 'race-conflict-'.$suffix;
        $harness = new PostgresProcessRaceHarness(
            sys_get_temp_dir().DIRECTORY_SEPARATOR.'accepted-work-conflict-'.$suffix,
        );
        $children = [];

        try {
            foreach ([1 => '100.00', 2 => '200.00'] as $worker => $amount) {
                $children[] = $harness->spawn($worker, static function () use ($eventKey, $amount): array {
                    $contract = new \App\Models\Contract;
                    $contract->setRawAttributes(['id' => 881004, 'organization_id' => 661004], true);
                    $act = new \App\Models\ContractPerformanceAct;
                    $act->setRawAttributes([
                        'id' => 991004,
                        'contract_id' => 881004,
                        'project_id' => 771004,
                        'is_approved' => true,
                        'amount' => $amount,
                        'status' => \App\Models\ContractPerformanceAct::STATUS_APPROVED,
                    ], true);
                    $act->setRelation('contract', $contract);
                    try {
                        \App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAcceptedWorkEventVersion::record(
                            $act,
                            true,
                            new \DateTimeImmutable('2026-07-30T10:00:00+00:00'),
                            $eventKey,
                            true,
                        );

                        return ['created' => true, 'conflict' => false];
                    } catch (\InvalidArgumentException $exception) {
                        return ['created' => false, 'conflict' => $exception->getMessage() === 'accepted_work_event_conflict'];
                    }
                });
            }
            $harness->release(1);
            $harness->release(2);
            $harness->waitForChildren($children);
            $children = [];

            $results = [$harness->result(1), $harness->result(2)];
            self::assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['created'])));
            self::assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['conflict'])));
        } finally {
            $harness->terminateAndReap($children);
            $harness->cleanup();
        }
    }
}
