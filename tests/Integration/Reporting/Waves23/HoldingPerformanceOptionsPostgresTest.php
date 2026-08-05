<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAcceptedWorkEventVersion;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingPaymentTransactionEventVersion;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\AcceptedWorkHoldingFactProducer;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingPaymentEventFactProducer;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingPerformanceImmutableEventSource;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingPerformanceOptionDimensionQuery;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingPerformanceOptionsService;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('postgres')]
final class HoldingPerformanceOptionsPostgresTest extends TestCase
{
    #[Test]
    public function outermost_options_read_uses_a_valid_read_only_repeatable_read_transaction(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        self::assertSame(0, DB::transactionLevel());
        $scope = new ReportScope(
            799999991,
            [799999991],
            [799999992],
            [],
            new DateTimeZone('Europe/Moscow'),
        );
        $options = $this->app->make(HoldingPerformanceOptionsService::class)->options(
            $scope,
            now()->toImmutable(),
        );

        self::assertFalse($options['available']);
        self::assertSame(0, DB::transactionLevel());
    }

    #[Test]
    public function options_include_late_event_dimensions_and_fail_closed_on_projection_gap(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        DB::beginTransaction();

        try {
            $organization = Organization::withoutEvents(
                static fn (): Organization => Organization::factory()->create(['name' => 'Холдинг для options']),
            );
            $project = Project::withoutEvents(
                static fn (): Project => Project::factory()->for($organization)->create(['name' => 'Поздний проект']),
            );
            $contractor = Contractor::withoutEvents(static fn (): Contractor => Contractor::create([
                'organization_id' => $organization->id,
                'name' => 'Поздний подрядчик',
                'contractor_type' => Contractor::TYPE_MANUAL,
            ]));
            $contractId = random_int(600000000, 699999999);
            $allocationId = $contractId + 1;
            $contextStartedAt = DB::table('holding_reporting_context_coverage')
                ->whereIn('source_code', [
                    'contract_dimensions',
                    'organization_hierarchy',
                    'allocation_dimensions',
                    'allocation_amount_dimensions',
                ])
                ->max('coverage_started_at');
            self::assertIsString($contextStartedAt);
            $checkpointAt = CarbonImmutable::parse($contextStartedAt)
                ->addSeconds(random_int(1000, 2000))
                ->setMicrosecond(random_int(1, 999999));
            DB::table('holding_payment_event_coverage_checkpoints')->insert([
                'started_at' => $checkpointAt,
                'source_max_transaction_id' => 0,
                'source_count' => 0,
                'captured_count' => 0,
                'gap_count' => 0,
                'content_hash' => hash('sha256', ''),
            ]);

            $timezone = new DateTimeZone('Europe/Moscow');
            $coverageStartedAt = (new HoldingPerformanceImmutableEventSource)->coverageStartedAt(
                CarbonImmutable::parse($contextStartedAt),
                $timezone,
            );
            $lateObservedAt = CarbonImmutable::instance($coverageStartedAt)->addHours(2);
            $recognizedAt = $lateObservedAt->addHour();
            $asOf = $recognizedAt->addHour();
            CarbonImmutable::setTestNow($asOf->addDay());

            DB::table('holding_organization_hierarchy_events')->insert([
                'organization_id' => $organization->id,
                'parent_organization_id' => null,
                'is_active' => true,
                'hierarchy_level' => 0,
                'hierarchy_path' => (string) $organization->id,
                'observed_at' => $checkpointAt,
                'is_deleted' => false,
                'evidence_hash' => hash('sha256', 'options-hierarchy-'.$organization->id),
            ]);
            DB::table('holding_contract_dimension_events')->insert([
                'contract_id' => $contractId,
                'organization_id' => $organization->id,
                'contractor_id' => $contractor->id,
                'counterparty_organization_id' => null,
                'contract_status' => 'active',
                'work_type_category' => null,
                'total_amount' => '1000.00',
                'currency' => 'RUB',
                'observed_at' => $lateObservedAt,
                'is_deleted' => false,
                'evidence_hash' => hash('sha256', 'options-contract-'.$contractId),
            ]);
            DB::table('holding_allocation_context_events')->insert([
                'allocation_id' => $allocationId,
                'contract_id' => $contractId,
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'allocation_type' => 'percentage',
                'allocated_amount' => null,
                'allocated_percentage' => '100.0000',
                'is_resolvable' => true,
                'is_active' => true,
                'observed_at' => $lateObservedAt,
                'is_deleted' => false,
                'evidence_hash' => hash('sha256', 'options-allocation-'.$allocationId),
            ]);
            DB::table('holding_accepted_work_event_versions')->insert([
                'event_key' => 'options-late-event-'.str()->uuid(),
                'performance_act_id' => $contractId + 2,
                'contract_id' => $contractId,
                'project_id' => $project->id,
                'organization_id' => $organization->id,
                'active' => true,
                'amount' => '125.50',
                'status' => 'approved',
                'occurred_at' => $recognizedAt,
                'recorded_at' => $recognizedAt->addMinute(),
                'history_complete' => true,
                'source_hash' => hash('sha256', 'options-late-event-'.$contractId),
            ]);
            DB::table('holding_payment_transaction_event_versions')->insert([
                'transaction_id' => $contractId + 4,
                'payment_document_id' => $contractId + 5,
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'contract_id' => $contractId,
                'document_organization_id' => $organization->id,
                'document_project_id' => $project->id,
                'contract_organization_id' => $organization->id,
                'contract_project_id' => $project->id,
                'amount' => '75.25',
                'currency' => 'RUB',
                'status' => 'completed',
                'active' => true,
                'recognized_at' => $recognizedAt,
                'occurred_at' => $recognizedAt,
                'recorded_at' => $recognizedAt->addMinutes(3),
                'history_complete' => true,
                'source_hash' => hash('sha256', 'options-payment-event-'.$contractId),
            ]);

            $scope = new ReportScope(
                $organization->id,
                [$organization->id],
                [$project->id],
                [],
                $timezone,
            );
            $period = CarbonImmutable::instance($coverageStartedAt)
                ->setTimezone($timezone)
                ->toDateString();
            $factCount = DB::table('holding_allocation_fact_versions')
                ->where('organization_id', $organization->id)
                ->count();
            $gapCount = DB::table('holding_allocation_projection_gaps')
                ->where('organization_id', $organization->id)
                ->count();
            $service = $this->app->make(HoldingPerformanceOptionsService::class);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $options = $service->options($scope, $asOf->toDateTimeImmutable(), $period, $period);
            $singleEventQueryCount = count(DB::getQueryLog());
            DB::disableQueryLog();

            self::assertTrue($options['available']);
            self::assertSame([['id' => $organization->id, 'name' => 'Холдинг для options']], $options['organizations']);
            self::assertSame([['id' => $project->id, 'name' => 'Поздний проект']], $options['projects']);
            self::assertSame([['id' => $contractor->id, 'name' => 'Поздний подрядчик']], $options['contractors']);
            self::assertSame(['active'], array_column($options['contract_statuses'], 'id'));
            self::assertSame(['RUB'], array_column($options['currencies'], 'id'));
            self::assertSame($factCount, DB::table('holding_allocation_fact_versions')
                ->where('organization_id', $organization->id)
                ->count());
            self::assertSame($gapCount, DB::table('holding_allocation_projection_gaps')
                ->where('organization_id', $organization->id)
                ->count());

            $additionalEvents = [];
            foreach (range(1, 24) as $offset) {
                $additionalEvents[] = [
                    'event_key' => 'options-scale-event-'.$offset.'-'.str()->uuid(),
                    'performance_act_id' => $contractId + 10 + $offset,
                    'contract_id' => $contractId,
                    'project_id' => $project->id,
                    'organization_id' => $organization->id,
                    'active' => true,
                    'amount' => '1.00',
                    'status' => 'approved',
                    'occurred_at' => $recognizedAt,
                    'recorded_at' => $recognizedAt->addMinutes(10 + $offset),
                    'history_complete' => true,
                    'source_hash' => hash('sha256', 'options-scale-event-'.$contractId.'-'.$offset),
                ];
            }
            DB::table('holding_accepted_work_event_versions')->insert($additionalEvents);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $scaledOptions = $service->options($scope, $asOf->toDateTimeImmutable(), $period, $period);
            $manyEventQueryCount = count(DB::getQueryLog());
            DB::disableQueryLog();

            self::assertTrue($scaledOptions['available']);
            self::assertSame($singleEventQueryCount, $manyEventQueryCount);

            $inactiveContractor = Contractor::withoutEvents(static fn (): Contractor => Contractor::create([
                'organization_id' => $organization->id,
                'name' => 'Подрядчик неактивной версии',
                'contractor_type' => Contractor::TYPE_MANUAL,
            ]));
            $inactiveContractId = $contractId + 1000;
            $inactiveAllocationId = $inactiveContractId + 1;
            DB::table('holding_contract_dimension_events')->insert([
                'contract_id' => $inactiveContractId,
                'organization_id' => $organization->id,
                'contractor_id' => $inactiveContractor->id,
                'counterparty_organization_id' => null,
                'contract_status' => 'completed',
                'work_type_category' => null,
                'total_amount' => '500.00',
                'currency' => 'USD',
                'observed_at' => $lateObservedAt,
                'is_deleted' => false,
                'evidence_hash' => hash('sha256', 'inactive-contract-'.$inactiveContractId),
            ]);
            DB::table('holding_allocation_context_events')->insert([
                'allocation_id' => $inactiveAllocationId,
                'contract_id' => $inactiveContractId,
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'allocation_type' => 'percentage',
                'allocated_amount' => null,
                'allocated_percentage' => '100.0000',
                'is_resolvable' => true,
                'is_active' => true,
                'observed_at' => $lateObservedAt,
                'is_deleted' => false,
                'evidence_hash' => hash('sha256', 'inactive-allocation-'.$inactiveAllocationId),
            ]);

            $tieRecordedAt = $recognizedAt->addMinutes(40);
            $inactiveActId = $inactiveContractId + 2;
            $activeActVersionId = DB::table('holding_accepted_work_event_versions')->insertGetId([
                'event_key' => 'options-active-tie-'.str()->uuid(),
                'performance_act_id' => $inactiveActId,
                'contract_id' => $inactiveContractId,
                'project_id' => $project->id,
                'organization_id' => $organization->id,
                'active' => true,
                'amount' => '50.00',
                'status' => 'approved',
                'occurred_at' => $recognizedAt,
                'recorded_at' => $tieRecordedAt,
                'history_complete' => true,
                'source_hash' => hash('sha256', 'options-active-tie-'.$inactiveActId),
            ]);
            $inactiveActVersionId = DB::table('holding_accepted_work_event_versions')->insertGetId([
                'event_key' => 'options-inactive-tie-'.str()->uuid(),
                'performance_act_id' => $inactiveActId,
                'contract_id' => $inactiveContractId,
                'project_id' => $project->id,
                'organization_id' => $organization->id,
                'active' => false,
                'amount' => '50.00',
                'status' => 'approved',
                'occurred_at' => $recognizedAt,
                'recorded_at' => $tieRecordedAt,
                'history_complete' => true,
                'source_hash' => hash('sha256', 'options-inactive-tie-'.$inactiveActId),
            ]);

            $inactiveTransactionId = $inactiveContractId + 3;
            $activePaymentVersionId = DB::table('holding_payment_transaction_event_versions')->insertGetId([
                'transaction_id' => $inactiveTransactionId,
                'payment_document_id' => $inactiveContractId + 4,
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'contract_id' => $inactiveContractId,
                'document_organization_id' => $organization->id,
                'document_project_id' => $project->id,
                'contract_organization_id' => $organization->id,
                'contract_project_id' => $project->id,
                'amount' => '20.00',
                'currency' => 'USD',
                'status' => 'completed',
                'active' => true,
                'recognized_at' => $recognizedAt,
                'occurred_at' => $recognizedAt,
                'recorded_at' => $tieRecordedAt,
                'history_complete' => true,
                'source_hash' => hash('sha256', 'options-active-payment-tie-'.$inactiveTransactionId),
            ]);
            $inactivePaymentVersionId = DB::table('holding_payment_transaction_event_versions')->insertGetId([
                'transaction_id' => $inactiveTransactionId,
                'payment_document_id' => $inactiveContractId + 4,
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'contract_id' => $inactiveContractId,
                'document_organization_id' => $organization->id,
                'document_project_id' => $project->id,
                'contract_organization_id' => $organization->id,
                'contract_project_id' => $project->id,
                'amount' => '20.00',
                'currency' => 'USD',
                'status' => 'completed',
                'active' => false,
                'recognized_at' => $recognizedAt,
                'occurred_at' => $recognizedAt,
                'recorded_at' => $tieRecordedAt,
                'history_complete' => true,
                'source_hash' => hash('sha256', 'options-inactive-payment-tie-'.$inactiveTransactionId),
            ]);

            self::assertGreaterThan($activeActVersionId, $inactiveActVersionId);
            self::assertGreaterThan($activePaymentVersionId, $inactivePaymentVersionId);
            $recordedCutoff = now()->toImmutable();
            $events = $this->app->make(HoldingPerformanceImmutableEventSource::class);
            $acceptedLatest = $events->acceptedWorkVersions(
                [$organization->id],
                [$project->id],
                $coverageStartedAt,
                $asOf,
                $recordedCutoff,
            )->first(static fn (mixed $event): bool => $event instanceof HoldingAcceptedWorkEventVersion
                && (int) $event->performance_act_id === $inactiveActId);
            $paymentLatest = $events->paymentVersions(
                [$organization->id],
                [$project->id],
                $coverageStartedAt,
                $asOf,
                $recordedCutoff,
            )->first(static fn (mixed $event): bool => $event instanceof HoldingPaymentTransactionEventVersion
                && (int) $event->transaction_id === $inactiveTransactionId);

            self::assertInstanceOf(HoldingAcceptedWorkEventVersion::class, $acceptedLatest);
            self::assertSame($inactiveActVersionId, (int) $acceptedLatest->getKey());
            self::assertFalse((bool) $acceptedLatest->active);
            $acceptedProducer = $this->app->make(AcceptedWorkHoldingFactProducer::class);
            self::assertTrue($acceptedProducer->canProjectEvent($acceptedLatest, $organization->id));
            self::assertNull($acceptedProducer->previewEvent($acceptedLatest));

            self::assertInstanceOf(HoldingPaymentTransactionEventVersion::class, $paymentLatest);
            self::assertSame($inactivePaymentVersionId, (int) $paymentLatest->getKey());
            self::assertFalse((bool) $paymentLatest->active);
            $paymentProducer = $this->app->make(HoldingPaymentEventFactProducer::class);
            self::assertTrue($paymentProducer->canProject($paymentLatest, $organization->id));
            self::assertNull($paymentProducer->previewEvent($paymentLatest));

            $batchDimensions = $this->app->make(HoldingPerformanceOptionDimensionQuery::class)->resolve(
                $organization->id,
                [$organization->id],
                [$project->id],
                $coverageStartedAt,
                $asOf,
                $recordedCutoff,
            );
            self::assertTrue($batchDimensions['complete']);
            self::assertContains(
                $contractor->id,
                array_column($batchDimensions['dimensions'], 'contractor_id'),
            );
            self::assertContains('active', array_column($batchDimensions['dimensions'], 'contract_status'));
            self::assertContains('RUB', array_column($batchDimensions['dimensions'], 'currency'));
            self::assertNotContains(
                $inactiveContractor->id,
                array_column($batchDimensions['dimensions'], 'contractor_id'),
            );
            self::assertNotContains(
                'completed',
                array_column($batchDimensions['dimensions'], 'contract_status'),
            );
            self::assertNotContains('USD', array_column($batchDimensions['dimensions'], 'currency'));

            DB::table('holding_accepted_work_event_versions')->insert([
                'event_key' => 'options-gap-event-'.str()->uuid(),
                'performance_act_id' => $contractId + 3,
                'contract_id' => $contractId,
                'project_id' => $project->id,
                'organization_id' => $organization->id,
                'active' => true,
                'amount' => '10.00',
                'status' => 'approved',
                'occurred_at' => $recognizedAt,
                'recorded_at' => $recognizedAt->addMinutes(2),
                'history_complete' => false,
                'source_hash' => hash('sha256', 'options-gap-event-'.$contractId),
            ]);
            $unavailable = $service->options($scope, $asOf->toDateTimeImmutable(), $period, $period);

            self::assertFalse($unavailable['available']);
            self::assertSame('source_incomplete', $unavailable['reason']);
            self::assertSame([], $unavailable['organizations']);
            self::assertSame($factCount, DB::table('holding_allocation_fact_versions')
                ->where('organization_id', $organization->id)
                ->count());
            self::assertSame($gapCount, DB::table('holding_allocation_projection_gaps')
                ->where('organization_id', $organization->id)
                ->count());
        } finally {
            CarbonImmutable::setTestNow();
            DB::rollBack();
        }
    }
}
