<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement\Reporting\Cycle;

use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementCyclePolicyDefinition;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums\ProcurementTerminalReason;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProcurementCyclePolicyDefinitionTest extends TestCase
{
    public function test_policy_hash_is_canonical_and_versions_are_pinned(): void
    {
        $left = $this->policy([
            1 => [['09:00', '18:00']],
            2 => [['09:00', '18:00']],
        ]);
        $right = $this->policy([
            2 => [['09:00', '18:00']],
            1 => [['09:00', '18:00']],
        ]);

        self::assertSame($left->canonicalHash(), $right->canonicalHash());
        self::assertSame('procurement-cycle.v1', $left->formulaVersion);
        self::assertSame('1.0.0', $left->sourceSchemaVersion);
        self::assertSame('procurement-process-events.v1', $left->eventSchemaVersion);
        self::assertSame('procurement-business-calendar.v1', $left->calendarVersion);
        self::assertSame($left->calendarHash(), $right->calendarHash());
    }

    public function test_policy_rejects_overlapping_work_windows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policy([
            1 => [['09:00', '13:00'], ['12:00', '18:00']],
        ]);
    }

    public function test_policy_rejects_missing_stage_sla(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProcurementCyclePolicyDefinition(
            organizationId: 10,
            projectId: null,
            timezone: 'Europe/Moscow',
            weeklyWindows: [1 => [['09:00', '18:00']]],
            exceptions: [],
            stageSlaSeconds: ['request_approval' => 3600],
            totalSlaSeconds: 86400,
            terminalCancellationPolicy: ['request_rejected'],
            effectiveFrom: new DateTimeImmutable('2026-08-01T00:00:00+00:00'),
        );
    }

    public function test_policy_accepts_stage_sla_in_any_input_key_order(): void
    {
        $policy = new ProcurementCyclePolicyDefinition(
            organizationId: 10,
            projectId: null,
            timezone: 'Europe/Moscow',
            weeklyWindows: [1 => [['09:00', '18:00']]],
            exceptions: [],
            stageSlaSeconds: [
                'full_receipt' => 700,
                'first_receipt' => 600,
                'order_dispatch' => 500,
                'award' => 400,
                'supplier_response' => 300,
                'solicitation' => 200,
                'request_approval' => 100,
            ],
            totalSlaSeconds: 3600,
            terminalCancellationPolicy: ['request_rejected'],
            effectiveFrom: new DateTimeImmutable('2026-08-01T00:00:00+00:00'),
        );

        self::assertSame(100, $policy->stageSlaSeconds['request_approval']);
    }

    public function test_policy_rejects_unknown_cancellation_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProcurementCyclePolicyDefinition(
            organizationId: 10,
            projectId: null,
            timezone: 'Europe/Moscow',
            weeklyWindows: [1 => [['09:00', '18:00']]],
            exceptions: [],
            stageSlaSeconds: $this->stageSla(),
            totalSlaSeconds: 3600,
            terminalCancellationPolicy: ['supplier_request_cancelled'],
            effectiveFrom: new DateTimeImmutable('2026-08-01T00:00:00+00:00'),
        );
    }

    public function test_policy_rejects_impossible_exception_date(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProcurementCyclePolicyDefinition(
            organizationId: 10,
            projectId: null,
            timezone: 'Europe/Moscow',
            weeklyWindows: [1 => [['09:00', '18:00']]],
            exceptions: ['2026-02-30' => []],
            stageSlaSeconds: $this->stageSla(),
            totalSlaSeconds: 3600,
            terminalCancellationPolicy: ['request_rejected'],
            effectiveFrom: new DateTimeImmutable('2026-08-01T00:00:00+00:00'),
        );
    }

    public function test_policy_applies_only_explicit_terminal_reasons(): void
    {
        $policy = $this->policy([1 => [['09:00', '18:00']]], ['request_rejected']);

        self::assertTrue($policy->allowsTerminalReason(ProcurementTerminalReason::REQUEST_REJECTED));
        self::assertFalse($policy->allowsTerminalReason(ProcurementTerminalReason::ORDER_CANCELLED));
    }

    private function policy(
        array $windows,
        array $terminalReasons = ['request_rejected', 'request_cancelled', 'order_cancelled'],
    ): ProcurementCyclePolicyDefinition
    {
        return new ProcurementCyclePolicyDefinition(
            organizationId: 10,
            projectId: null,
            timezone: 'Europe/Moscow',
            weeklyWindows: $windows,
            exceptions: ['2026-08-03' => []],
            stageSlaSeconds: $this->stageSla(),
            totalSlaSeconds: 86400,
            terminalCancellationPolicy: $terminalReasons,
            effectiveFrom: new DateTimeImmutable('2026-08-01T00:00:00+00:00'),
        );
    }

    private function stageSla(): array
    {
        return [
            'request_approval' => 3600,
            'solicitation' => 7200,
            'supplier_response' => 10800,
            'award' => 14400,
            'order_dispatch' => 18000,
            'first_receipt' => 21600,
            'full_receipt' => 25200,
        ];
    }
}
