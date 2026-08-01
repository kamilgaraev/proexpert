<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement\Reporting\Cycle;

use App\BusinessModules\Features\Procurement\Reporting\Cycle\Contracts\ProcurementProcessEventStore;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Contracts\ProcurementTransactionBoundary;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementProcessDimensionSnapshot;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementProcessTransition;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums\ProcurementProcessEventCode;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementProcessEventRecorder;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ProcurementProcessEventRecorderTest extends TestCase
{
    public function test_records_exact_transition_inside_owner_transaction(): void
    {
        $store = new RecordingStore();
        $recorder = new ProcurementProcessEventRecorder($store, new FixedTransactionBoundary(true));
        $transition = $this->transition();

        $recorder->record($transition);

        self::assertSame([$transition], $store->transitions);
    }

    public function test_rejects_recording_outside_owner_transaction(): void
    {
        $store = new RecordingStore();
        $recorder = new ProcurementProcessEventRecorder($store, new FixedTransactionBoundary(false));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('procurement_process_event_owner_transaction_required');

        $recorder->record($this->transition());
        self::assertSame([], $store->transitions);
    }

    private function transition(): ProcurementProcessTransition
    {
        return new ProcurementProcessTransition(
            eventCode: ProcurementProcessEventCode::REQUEST_APPROVED,
            organizationId: 10,
            projectId: 20,
            purchaseRequestId: 30,
            purchaseRequestLineId: 40,
            occurredAt: new DateTimeImmutable('2026-08-01T09:30:00+00:00'),
            sourceKind: 'purchase_request',
            sourceId: 30,
            actorId: 50,
            dimensionSnapshot: ProcurementProcessDimensionSnapshot::fromArray([
                'schema_version' => 'procurement-process-dimensions.v1',
                'organization_id' => 10,
                'project_id' => 20,
                'purchase_request_id' => 30,
                'purchase_request_line_id' => 40,
                'request_number' => 'PR-42',
                'material_name' => 'Steel',
                'quantity' => '2.500',
                'unit' => 'kg',
                'quality_status' => 'PARTIAL',
                'gap_codes' => ['missing_policy_version'],
            ]),
        );
    }
}

final class RecordingStore implements ProcurementProcessEventStore
{
    public array $transitions = [];

    public function append(ProcurementProcessTransition $transition): void
    {
        $this->transitions[] = $transition;
    }
}

final readonly class FixedTransactionBoundary implements ProcurementTransactionBoundary
{
    public function __construct(private bool $active)
    {
    }

    public function isActive(): bool
    {
        return $this->active;
    }
}
