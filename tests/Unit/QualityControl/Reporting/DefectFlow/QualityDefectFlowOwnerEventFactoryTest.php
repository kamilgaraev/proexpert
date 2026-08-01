<?php

declare(strict_types=1);

namespace Tests\Unit\QualityControl\Reporting\DefectFlow;

use App\BusinessModules\Features\QualityControl\Enums\QualityDefectStatusEnum;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\QualityControl\Models\QualityDefectStatusHistory;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Enums\QualityDefectFlowEventKind;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Enums\QualityDefectFlowTerminalReason;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Services\QualityDefectFlowOwnerEventFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QualityDefectFlowOwnerEventFactoryTest extends TestCase
{
    #[DataProvider('ownerTransitionProvider')]
    public function test_builds_all_canonical_owner_events(
        QualityDefectFlowEventKind $kind,
        ?QualityDefectStatusEnum $from,
        QualityDefectStatusEnum $to,
        ?QualityDefectFlowTerminalReason $reason,
    ): void {
        $defect = $this->defect($to);
        $history = $this->history($from, $to);

        $event = (new QualityDefectFlowOwnerEventFactory)->make($defect, $history, $kind, $reason);

        self::assertSame($kind, $event->eventKind);
        self::assertSame($from, $event->fromStatus);
        self::assertSame($to, $event->toStatus);
        self::assertSame('2026-08-01T09:30:00.123456Z', $event->occurredAtUtc());
        self::assertSame('91', $event->sourceIdentity['id']);
    }

    public static function ownerTransitionProvider(): array
    {
        return [
            'created' => [QualityDefectFlowEventKind::CREATED, null, QualityDefectStatusEnum::OPEN, null],
            'assigned' => [QualityDefectFlowEventKind::ASSIGNED, QualityDefectStatusEnum::OPEN, QualityDefectStatusEnum::ASSIGNED, null],
            'started' => [QualityDefectFlowEventKind::STARTED, QualityDefectStatusEnum::ASSIGNED, QualityDefectStatusEnum::IN_PROGRESS, null],
            'submitted' => [QualityDefectFlowEventKind::SUBMITTED_FOR_REVIEW, QualityDefectStatusEnum::IN_PROGRESS, QualityDefectStatusEnum::READY_FOR_REVIEW, null],
            'verified' => [QualityDefectFlowEventKind::VERIFIED_RESOLVED, QualityDefectStatusEnum::READY_FOR_REVIEW, QualityDefectStatusEnum::RESOLVED, null],
            'returned' => [QualityDefectFlowEventKind::RETURNED_FOR_REWORK, QualityDefectStatusEnum::READY_FOR_REVIEW, QualityDefectStatusEnum::REJECTED, null],
            'rejected' => [QualityDefectFlowEventKind::REJECTED, QualityDefectStatusEnum::IN_PROGRESS, QualityDefectStatusEnum::REJECTED, null],
            'cancelled' => [
                QualityDefectFlowEventKind::CANCELLED,
                QualityDefectStatusEnum::REJECTED,
                QualityDefectStatusEnum::CANCELLED,
                QualityDefectFlowTerminalReason::CANCELLED_BY_USER,
            ],
        ];
    }

    public function test_acceptance_source_snapshot_preserves_only_stable_lineage_ids(): void
    {
        $defect = $this->defect(QualityDefectStatusEnum::OPEN);
        $defect->metadata = [
            'source' => [
                'type' => 'acceptance_finding',
                'acceptance_scope_id' => 70,
                'acceptance_session_id' => 80,
                'description' => 'must not escape',
            ],
            'token' => 'must not escape',
        ];

        $event = (new QualityDefectFlowOwnerEventFactory)->make(
            $defect,
            $this->history(null, QualityDefectStatusEnum::OPEN),
            QualityDefectFlowEventKind::CREATED,
        );

        self::assertSame([
            'acceptance_scope_id' => '70',
            'acceptance_session_id' => '80',
            'classification' => 'acceptance_finding',
        ], $event->snapshot->canonical()['source_link']);
        self::assertStringNotContainsString('must not escape', json_encode(
            $event->snapshot->canonical(),
            JSON_THROW_ON_ERROR,
        ));
    }

    private function defect(QualityDefectStatusEnum $status): QualityDefect
    {
        $defect = new QualityDefect;
        $defect->setRawAttributes([
            'id' => 30,
            'organization_id' => 10,
            'project_id' => 20,
            'contractor_id' => 40,
            'assigned_to' => 60,
            'severity' => 'major',
            'status' => $status->value,
            'schedule_task_id' => 50,
            'due_date' => '2026-08-10',
            'inspection_required' => true,
            'metadata' => null,
        ], true);
        $defect->exists = true;

        return $defect;
    }

    private function history(
        ?QualityDefectStatusEnum $from,
        QualityDefectStatusEnum $to,
    ): QualityDefectStatusHistory {
        $history = new QualityDefectStatusHistory;
        $history->setRawAttributes([
            'id' => 91,
            'quality_defect_id' => 30,
            'organization_id' => 10,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'changed_by' => 14,
            'changed_at' => '2026-08-01 09:30:00.123456+00',
        ], true);
        $history->exists = true;

        return $history;
    }
}
