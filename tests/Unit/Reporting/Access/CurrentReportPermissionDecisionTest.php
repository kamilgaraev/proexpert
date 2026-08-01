<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Access;

use App\BusinessModules\Core\Reporting\Application\Access\CurrentReportPermissionDecision;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CurrentReportPermissionDecisionTest extends TestCase
{
    public function test_decision_is_closed_and_validates_identity(): void
    {
        $resource = new ReportScopedResource('task', 9, 4);
        $decision = new CurrentReportPermissionDecision(1, 'reports.view', 2, 4, $resource, true);
        self::assertTrue($decision->granted);

        $this->expectException(InvalidArgumentException::class);
        new CurrentReportPermissionDecision(1, 'reports view', 2, 3, $resource, true);
    }
}
