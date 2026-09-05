<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement;

use App\BusinessModules\Features\Procurement\Services\SupplierProposalDeliveryAssessmentService;
use PHPUnit\Framework\TestCase;

final class SupplierProposalDeliveryAssessmentServiceTest extends TestCase
{
    public function test_explicit_delivery_after_need_is_late(): void
    {
        $result = (new SupplierProposalDeliveryAssessmentService)->evaluate('2026-09-15', '2026-09-20', 1, '2026-09-05');
        self::assertSame('2026-09-20', $result['expected_date']);
        self::assertTrue($result['is_late']);
        self::assertSame(5, $result['days_late']);
    }

    public function test_lead_time_starts_at_selection_and_can_meet_deadline_exactly(): void
    {
        $result = (new SupplierProposalDeliveryAssessmentService)->evaluate('2026-09-15', null, 10, '2026-09-05');
        self::assertSame('2026-09-15', $result['expected_date']);
        self::assertFalse($result['is_late']);
    }

    public function test_missing_or_invalid_promise_is_not_treated_as_on_time(): void
    {
        foreach ([null, '2026-02-30', 'not-a-date'] as $date) {
            $result = (new SupplierProposalDeliveryAssessmentService)->evaluate('2026-09-15', $date, null, '2026-09-05');
            self::assertNull($result['is_late']);
            self::assertNull($result['expected_date']);
        }
    }
}
