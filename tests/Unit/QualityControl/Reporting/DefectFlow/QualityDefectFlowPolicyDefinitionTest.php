<?php

declare(strict_types=1);

namespace Tests\Unit\QualityControl\Reporting\DefectFlow;

use App\BusinessModules\Features\QualityControl\Enums\QualityDefectStatusEnum;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\DTO\QualityDefectFlowPolicyDefinition;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Enums\QualityDefectFlowEventKind;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Enums\QualityDefectFlowTerminalReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QualityDefectFlowPolicyDefinitionTest extends TestCase
{
    #[DataProvider('allowedTransitionProvider')]
    public function test_v1_allows_every_canonical_owner_transition(
        QualityDefectFlowEventKind $eventKind,
        ?QualityDefectStatusEnum $fromStatus,
        QualityDefectStatusEnum $toStatus,
        ?QualityDefectFlowTerminalReason $terminalReason,
    ): void {
        self::assertTrue(QualityDefectFlowPolicyDefinition::v1()->allows(
            $eventKind,
            $fromStatus,
            $toStatus,
            $terminalReason,
        ));
    }

    public static function allowedTransitionProvider(): array
    {
        return [
            'created open' => [QualityDefectFlowEventKind::CREATED, null, QualityDefectStatusEnum::OPEN, null],
            'created assigned' => [QualityDefectFlowEventKind::CREATED, null, QualityDefectStatusEnum::ASSIGNED, null],
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

    public function test_v1_forbids_reopen_after_resolved(): void
    {
        self::assertFalse(QualityDefectFlowPolicyDefinition::v1()->allows(
            QualityDefectFlowEventKind::STARTED,
            QualityDefectStatusEnum::RESOLVED,
            QualityDefectStatusEnum::IN_PROGRESS,
            null,
        ));
    }

    public function test_v1_requires_typed_terminal_reason_only_for_cancellation(): void
    {
        $policy = QualityDefectFlowPolicyDefinition::v1();

        self::assertFalse($policy->allows(
            QualityDefectFlowEventKind::CANCELLED,
            QualityDefectStatusEnum::OPEN,
            QualityDefectStatusEnum::CANCELLED,
            null,
        ));
        self::assertFalse($policy->allows(
            QualityDefectFlowEventKind::STARTED,
            QualityDefectStatusEnum::OPEN,
            QualityDefectStatusEnum::IN_PROGRESS,
            QualityDefectFlowTerminalReason::CANCELLED_BY_USER,
        ));
    }

    public function test_v1_pins_calendar_clock_and_deterministic_order(): void
    {
        $policy = QualityDefectFlowPolicyDefinition::v1();

        self::assertSame('quality-defect-flow.v1', $policy->policyCode);
        self::assertSame(1, $policy->version);
        self::assertSame('calendar_days', $policy->canonicalPolicy()['ageing_clock']['basis']);
        self::assertSame('UTC', $policy->canonicalPolicy()['ageing_clock']['event_timezone']);
        self::assertSame(['occurred_at_utc', 'event_id'], $policy->canonicalPolicy()['deterministic_order']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $policy->hash());
    }
}
