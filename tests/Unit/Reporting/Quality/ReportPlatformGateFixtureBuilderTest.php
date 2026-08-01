<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Quality;

use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\Quality\ReportPlatformGateFixtureBuilder;

final class ReportPlatformGateFixtureBuilderTest extends TestCase
{
    public function test_derives_the_complete_ordered_fixture_from_the_tracked_catalog(): void
    {
        $builder = new ReportPlatformGateFixtureBuilder(dirname(__DIR__, 4));
        $fixture = $builder->build();

        self::assertSame('report_platform_gate_inputs', $fixture['artifact_id']);
        self::assertSame(array_map(static fn (int $number): string => sprintf('QG-%02d', $number), range(1, 14)), array_column($fixture['gates'], 'gate'));
        self::assertStringEndsWith("\n", $builder->bytes());
    }
}

