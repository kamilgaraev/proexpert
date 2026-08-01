<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement\Reporting\Cycle;

use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementCycleEvent;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementCyclePolicyDefinition;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementCyclePolicySnapshot;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementProcessDimensionSnapshot;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementProcessTransition;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums\ProcurementCycleStage;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums\ProcurementProcessEventCode;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums\ProcurementTerminalReason;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementBusinessCalendar;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementCycleFormula;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ProcurementCycleFormulaTest extends TestCase
{
    public function test_complete_line_uses_business_seconds_and_exact_sla_boundary(): void
    {
        $policy = $this->policy(stageSlaSeconds: 1800, totalSlaSeconds: 12600);
        $result = $this->formula()->calculate(
            events: $this->completeEvents($policy),
            policy: $policy,
            asOf: new DateTimeImmutable('2026-08-03T13:00:00+00:00'),
        );

        self::assertSame('completed', $result->outcome);
        self::assertNull($result->currentStage);
        self::assertSame(12600, $result->totalCycleSeconds);
        self::assertTrue($result->totalSlaEligible);
        self::assertTrue($result->totalSlaMet);
        self::assertSame('2026-08-03', $result->startCohortDate);
        self::assertSame('2026-08-03', $result->outcomeCohortDate);
        self::assertSame('FULL', $result->qualityStatus);
        self::assertSame([], $result->gapCodes);

        foreach (ProcurementCycleStage::cases() as $stage) {
            $metric = $result->stageMetrics[$stage->value];
            self::assertSame(1800, $metric->durationSeconds);
            self::assertSame(1800, $metric->slaSeconds);
            self::assertTrue($metric->eligible);
            self::assertTrue($metric->slaMet);
            self::assertSame(1, $metric->numerator);
            self::assertSame(1, $metric->denominator);
        }

        self::assertSame('procurement-line:40', $result->row()['row_key']);
        self::assertCount(7, $result->stageDrillRows());
    }

    public function test_open_line_calculates_age_from_last_contiguous_boundary(): void
    {
        $policy = $this->policy();
        $result = $this->formula()->calculate(
            events: [
                $this->event(1, ProcurementProcessEventCode::REQUEST_CREATED, '2026-08-03T09:00:00+00:00', $policy),
                $this->event(2, ProcurementProcessEventCode::REQUEST_APPROVED, '2026-08-03T10:00:00+00:00', $policy),
            ],
            policy: $policy,
            asOf: new DateTimeImmutable('2026-08-03T12:30:00+00:00'),
        );

        self::assertSame('open', $result->outcome);
        self::assertSame(ProcurementCycleStage::SOLICITATION, $result->currentStage);
        self::assertSame(9000, $result->openAgeSeconds);
        self::assertSame([], $result->gapCodes);
        self::assertFalse($result->stageMetrics['solicitation']->eligible);
    }

    public function test_cancelled_line_excludes_unfinished_and_total_sla_denominators(): void
    {
        $policy = $this->policy();
        $result = $this->formula()->calculate(
            events: [
                $this->event(1, ProcurementProcessEventCode::REQUEST_CREATED, '2026-08-03T09:00:00+00:00', $policy),
                $this->event(2, ProcurementProcessEventCode::REQUEST_APPROVED, '2026-08-03T10:00:00+00:00', $policy),
                $this->event(
                    3,
                    ProcurementProcessEventCode::CANCELLED,
                    '2026-08-03T11:00:00+00:00',
                    $policy,
                    ProcurementTerminalReason::REQUEST_CANCELLED,
                ),
            ],
            policy: $policy,
            asOf: new DateTimeImmutable('2026-08-03T12:00:00+00:00'),
        );

        self::assertSame('cancelled', $result->outcome);
        self::assertNull($result->currentStage);
        self::assertSame(7200, $result->timeToCancellationSeconds);
        self::assertFalse($result->totalSlaEligible);
        self::assertNull($result->totalSlaMet);
        self::assertSame(0, $result->stageMetrics['solicitation']->denominator);
        self::assertNull($result->openAgeSeconds);
    }

    public function test_nonmonotonic_lifecycle_is_invalid_and_has_no_dependent_metrics(): void
    {
        $policy = $this->policy();
        $result = $this->formula()->calculate(
            events: [
                $this->event(1, ProcurementProcessEventCode::REQUEST_CREATED, '2026-08-03T10:00:00+00:00', $policy),
                $this->event(2, ProcurementProcessEventCode::REQUEST_APPROVED, '2026-08-03T09:00:00+00:00', $policy),
            ],
            policy: $policy,
            asOf: new DateTimeImmutable('2026-08-03T12:00:00+00:00'),
        );

        self::assertSame('invalid_source', $result->outcome);
        self::assertSame('INVALID_SOURCE', $result->qualityStatus);
        self::assertContains('nonmonotonic_event_sequence', $result->gapCodes);
        self::assertNull($result->openAgeSeconds);
        self::assertNull($result->totalCycleSeconds);
        foreach ($result->stageMetrics as $metric) {
            self::assertNull($metric->durationSeconds);
            self::assertFalse($metric->eligible);
        }
    }

    public function test_missing_intermediate_boundary_is_partial_without_inference(): void
    {
        $policy = $this->policy();
        $result = $this->formula()->calculate(
            events: [
                $this->event(1, ProcurementProcessEventCode::REQUEST_CREATED, '2026-08-03T09:00:00+00:00', $policy),
                $this->event(2, ProcurementProcessEventCode::SOLICITATION_SENT, '2026-08-03T11:00:00+00:00', $policy),
            ],
            policy: $policy,
            asOf: new DateTimeImmutable('2026-08-03T12:00:00+00:00'),
        );

        self::assertSame('open', $result->outcome);
        self::assertSame('PARTIAL', $result->qualityStatus);
        self::assertContains('missing_request_approved_event', $result->gapCodes);
        self::assertNull($result->stageMetrics['request_approval']->durationSeconds);
        self::assertNull($result->stageMetrics['solicitation']->durationSeconds);
        self::assertSame(10800, $result->openAgeSeconds);
    }

    public function test_line_without_request_created_is_explicitly_incomplete(): void
    {
        $policy = $this->policy();
        $result = $this->formula()->calculate(
            events: [
                $this->event(1, ProcurementProcessEventCode::REQUEST_APPROVED, '2026-08-03T09:00:00+00:00', $policy),
                $this->event(2, ProcurementProcessEventCode::FULLY_RECEIVED, '2026-08-03T12:00:00+00:00', $policy),
            ],
            policy: $policy,
            asOf: new DateTimeImmutable('2026-08-03T13:00:00+00:00'),
        );

        self::assertSame('incomplete', $result->outcome);
        self::assertSame('PARTIAL', $result->qualityStatus);
        self::assertContains('missing_request_created_event', $result->gapCodes);
        self::assertNull($result->totalCycleSeconds);
        self::assertFalse($result->totalSlaEligible);
        self::assertNull($result->currentStage);
    }

    public function test_business_calendar_excludes_closed_weekend_from_sla_duration(): void
    {
        $policy = $this->policy(stageSlaSeconds: 7200);
        $result = $this->formula()->calculate(
            events: [
                $this->event(1, ProcurementProcessEventCode::REQUEST_CREATED, '2026-08-07T16:00:00+00:00', $policy),
                $this->event(2, ProcurementProcessEventCode::REQUEST_APPROVED, '2026-08-10T10:00:00+00:00', $policy),
            ],
            policy: $policy,
            asOf: new DateTimeImmutable('2026-08-10T10:00:00+00:00'),
        );

        self::assertSame(7200, $result->stageMetrics['request_approval']->durationSeconds);
        self::assertTrue($result->stageMetrics['request_approval']->slaMet);
    }

    public function test_event_exposes_only_typed_audit_lineage(): void
    {
        $policy = $this->policy();
        $event = $this->event(
            9,
            ProcurementProcessEventCode::ORDER_SENT,
            '2026-08-03T12:00:00+00:00',
            $policy,
            supplierPartyId: 65,
            purchaseOrderId: 80,
            purchaseOrderItemId: 81,
        );

        self::assertSame([
            'event_id' => 9,
            'event_code' => 'order_sent',
            'occurred_at' => '2026-08-03T12:00:00.000000Z',
            'actor_id' => 50,
            'source_kind' => 'owner_workflow',
            'source_id' => 1009,
            'source_event_id' => null,
            'supplier_request_id' => null,
            'supplier_request_line_id' => null,
            'supplier_party_id' => 65,
            'supplier_proposal_id' => null,
            'supplier_proposal_version_id' => null,
            'supplier_proposal_decision_id' => null,
            'purchase_order_id' => 80,
            'purchase_order_item_id' => 81,
            'purchase_receipt_id' => null,
            'purchase_receipt_line_id' => null,
        ], $event->auditPayload());
    }

    private function formula(): ProcurementCycleFormula
    {
        return new ProcurementCycleFormula(new ProcurementBusinessCalendar);
    }

    private function policy(int $stageSlaSeconds = 3600, int $totalSlaSeconds = 86400): ProcurementCyclePolicySnapshot
    {
        $definition = new ProcurementCyclePolicyDefinition(
            organizationId: 10,
            projectId: 20,
            timezone: 'UTC',
            weeklyWindows: [
                1 => [['09:00', '17:00']],
                2 => [['09:00', '17:00']],
                3 => [['09:00', '17:00']],
                4 => [['09:00', '17:00']],
                5 => [['09:00', '17:00']],
            ],
            exceptions: [],
            stageSlaSeconds: array_fill_keys(
                array_map(static fn (ProcurementCycleStage $stage): string => $stage->value, ProcurementCycleStage::cases()),
                $stageSlaSeconds,
            ),
            totalSlaSeconds: $totalSlaSeconds,
            terminalCancellationPolicy: ['request_cancelled'],
            effectiveFrom: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );

        return new ProcurementCyclePolicySnapshot(versionId: 70, definition: $definition);
    }

    private function completeEvents(ProcurementCyclePolicySnapshot $policy): array
    {
        $events = [];
        foreach (ProcurementProcessEventCode::cases() as $index => $code) {
            if ($code === ProcurementProcessEventCode::CANCELLED) {
                continue;
            }
            $events[] = $this->event(
                $index + 1,
                $code,
                sprintf('2026-08-03T%02d:%02d:00+00:00', 9 + intdiv($index, 2), ($index % 2) * 30),
                $policy,
            );
        }

        return $events;
    }

    private function event(
        int $id,
        ProcurementProcessEventCode $code,
        string $occurredAt,
        ProcurementCyclePolicySnapshot $policy,
        ?ProcurementTerminalReason $terminalReason = null,
        ?int $supplierPartyId = null,
        ?int $purchaseOrderId = null,
        ?int $purchaseOrderItemId = null,
    ): ProcurementCycleEvent {
        $dimensions = [
            'schema_version' => ProcurementProcessDimensionSnapshot::SCHEMA_VERSION,
            'organization_id' => 10,
            'project_id' => 20,
            'purchase_request_id' => 30,
            'purchase_request_line_id' => 40,
            'request_number' => 'PR-42',
            'requester_id' => 51,
            'buyer_id' => 52,
            'material_id' => 60,
            'material_name' => 'Сталь',
            'material_category_id' => 61,
            'material_category_name' => 'Металл',
            'priority' => 'normal',
            'quantity' => '2.500',
            'unit' => 'кг',
            'policy_version_id' => $policy->versionId,
            'policy_hash' => $policy->canonicalHash,
            'calendar_version' => $policy->definition->calendarVersion,
            'calendar_hash' => $policy->definition->calendarHash(),
            'quality_status' => 'FULL',
            'gap_codes' => [],
        ];

        return new ProcurementCycleEvent(
            id: $id,
            transition: new ProcurementProcessTransition(
                eventCode: $code,
                organizationId: 10,
                projectId: 20,
                purchaseRequestId: 30,
                purchaseRequestLineId: 40,
                occurredAt: new DateTimeImmutable($occurredAt),
                sourceKind: 'owner_workflow',
                sourceId: 1000 + $id,
                dimensionSnapshot: ProcurementProcessDimensionSnapshot::fromArray($dimensions),
                actorId: 50,
                supplierPartyId: $supplierPartyId,
                purchaseOrderId: $purchaseOrderId,
                purchaseOrderItemId: $purchaseOrderItemId,
                policyVersionId: $policy->versionId,
                policyHash: $policy->canonicalHash,
                calendarVersion: $policy->definition->calendarVersion,
                calendarHash: $policy->definition->calendarHash(),
                terminalReason: $terminalReason,
            ),
        );
    }
}
