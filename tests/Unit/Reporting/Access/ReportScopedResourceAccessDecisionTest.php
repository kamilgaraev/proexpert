<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Access;

use App\BusinessModules\Core\Reporting\Application\Access\ReportScopedResourceAccessDecision;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReportScopedResourceAccessDecisionTest extends TestCase
{
    public function test_decision_validates_complete_resource_identity(): void
    {
        $decision = new ReportScopedResourceAccessDecision(1, 2, null, 'task', 3, true);
        self::assertTrue($decision->granted);

        $this->expectException(InvalidArgumentException::class);
        new ReportScopedResourceAccessDecision(0, 2, null, '*', 0, true);
    }
}
