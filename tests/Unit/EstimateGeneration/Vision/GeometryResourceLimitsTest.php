<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\GeometryResourceLimits;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GeometryResourceLimitsTest extends TestCase
{
    #[Test]
    public function invalid_resource_limits_are_rejected_before_process_start(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('geometry_resource_limits_invalid');
        new GeometryResourceLimits(memoryLimitKiB: 0, cpuLimitSeconds: 0, fileSizeLimitBytes: -1, openFileLimit: 2);
    }

    #[Test]
    public function configured_limits_convert_to_sandbox_arguments(): void
    {
        $limits = new GeometryResourceLimits(262_144, 3, 65_536, 64);

        self::assertSame(['262144', '3', '128', '64'], $limits->sandboxArguments());
    }
}
