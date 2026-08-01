<?php

declare(strict_types=1);

namespace Tests\Unit\QualityControl\Reporting\DefectFlow;

use App\BusinessModules\Features\QualityControl\Enums\QualityDefectStatusEnum;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Contracts\QualityDefectFlowStore;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Contracts\QualityDefectFlowTransactionBoundary;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\DTO\QualityDefectFlowEvent;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\DTO\QualityDefectFlowPolicyDefinition;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\DTO\QualityDefectFlowSnapshot;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Enums\QualityDefectFlowEventKind;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Services\QualityDefectFlowEventRecorder;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;

final class QualityDefectFlowRecorderTest extends TestCase
{
    public function test_records_inside_owner_transaction(): void
    {
        $store = new RecordingQualityDefectFlowStore;
        $event = $this->event();

        (new QualityDefectFlowEventRecorder($store, new FixedQualityDefectFlowTransactionBoundary(true)))
            ->record($event);

        self::assertSame([$event], $store->events);
    }

    public function test_rejects_recording_outside_owner_transaction(): void
    {
        $store = new RecordingQualityDefectFlowStore;
        $recorder = new QualityDefectFlowEventRecorder(
            $store,
            new FixedQualityDefectFlowTransactionBoundary(false),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('quality_defect_flow_owner_transaction_required');

        $recorder->record($this->event());
    }

    private function event(): QualityDefectFlowEvent
    {
        return new QualityDefectFlowEvent(
            eventKind: QualityDefectFlowEventKind::CREATED,
            fromStatus: null,
            toStatus: QualityDefectStatusEnum::OPEN,
            actorId: 14,
            occurredAt: new DateTimeImmutable('2026-08-01T09:30:00+00:00'),
            snapshot: QualityDefectFlowSnapshot::fromArray([
                'schema_version' => QualityDefectFlowSnapshot::SCHEMA_VERSION,
                'organization_id' => '10',
                'project_id' => '20',
                'quality_defect_id' => '30',
                'contractor_id' => null,
                'schedule_task_id' => null,
                'severity' => 'major',
                'due_date' => null,
                'has_due_date' => false,
                'inspection_required' => true,
                'assignee_id' => null,
                'source_link' => ['classification' => 'quality_defect'],
            ]),
            sourceIdentity: ['kind' => 'quality_defect_status_history', 'id' => '91'],
            policy: QualityDefectFlowPolicyDefinition::v1(),
        );
    }
}

final class RecordingQualityDefectFlowStore implements QualityDefectFlowStore
{
    public array $events = [];

    public function append(QualityDefectFlowEvent $event): string
    {
        $this->events[] = $event;

        return '018f6f5a-4ca2-7a11-bf61-0242ac120002';
    }
}

final readonly class FixedQualityDefectFlowTransactionBoundary implements QualityDefectFlowTransactionBoundary
{
    public function __construct(private bool $active) {}

    public function isActive(): bool
    {
        return $this->active;
    }
}
