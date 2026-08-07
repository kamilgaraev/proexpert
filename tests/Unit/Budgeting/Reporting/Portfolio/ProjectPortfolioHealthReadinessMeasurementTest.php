<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthReadinessMeasurement;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthSourceGap;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProjectPortfolioHealthReadinessMeasurementTest extends TestCase
{
    #[Test]
    public function it_counts_each_required_owner_source_once(): void
    {
        $measurement = new ProjectPortfolioHealthReadinessMeasurement([
            new ProjectPortfolioHealthSourceGap('source_projection_gap', 'project_margin'),
            new ProjectPortfolioHealthSourceGap('source_integrity_gap', 'project_margin'),
        ]);

        self::assertCount(4, $measurement->eligible());
        self::assertCount(3, $measurement->projected());
        self::assertSame(1, $measurement->gapCount());
    }
}
