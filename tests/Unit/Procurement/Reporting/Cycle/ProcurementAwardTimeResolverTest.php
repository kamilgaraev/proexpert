<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement\Reporting\Cycle;

use App\BusinessModules\Features\Procurement\Enums\SupplierProposalDecisionEnum;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementAwardTimeResolver;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ProcurementAwardTimeResolverTest extends TestCase
{
    public function test_riskless_award_uses_persisted_selection_time(): void
    {
        $selectedAt = new DateTimeImmutable('2026-08-01T10:15:30.123456+00:00');

        $actual = (new ProcurementAwardTimeResolver())->resolve(
            SupplierProposalDecisionEnum::SELECTED,
            $selectedAt,
            null,
        );

        self::assertSame($selectedAt->format('U.u'), $actual->format('U.u'));
    }

    public function test_approved_award_uses_final_approval_resolution_time(): void
    {
        $selectedAt = new DateTimeImmutable('2026-08-01T10:00:00+00:00');
        $resolvedAt = new DateTimeImmutable('2026-08-01T11:20:00.654321+00:00');

        $actual = (new ProcurementAwardTimeResolver())->resolve(
            SupplierProposalDecisionEnum::APPROVED,
            $selectedAt,
            $resolvedAt,
        );

        self::assertSame($resolvedAt->format('U.u'), $actual->format('U.u'));
    }

    public function test_non_final_decision_has_no_award_time(): void
    {
        $this->expectException(LogicException::class);

        (new ProcurementAwardTimeResolver())->resolve(
            SupplierProposalDecisionEnum::APPROVAL_REQUIRED,
            new DateTimeImmutable('2026-08-01T10:00:00+00:00'),
            null,
        );
    }
}
