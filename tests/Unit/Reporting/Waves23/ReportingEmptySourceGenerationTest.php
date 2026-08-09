<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\Jobs\ReportingSourceBackfillJob;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ReportingEmptySourceGenerationTest extends TestCase
{
    #[Test]
    #[DataProvider('targetCursors')]
    public function only_a_generation_without_owner_rows_is_empty(array $cursor, bool $expected): void
    {
        $method = new ReflectionMethod(ReportingSourceBackfillJob::class, 'isEmptyTargetCursor');

        self::assertSame($expected, $method->invoke(null, $cursor));
    }

    public static function targetCursors(): array
    {
        return [
            'linear empty' => [['id' => 0], true],
            'linear non-empty' => [['id' => 1], false],
            'incident empty' => [[
                'incident_id' => 0,
                'violation_id' => 0,
                'action_id' => 0,
            ], true],
            'incident non-empty' => [[
                'incident_id' => 0,
                'violation_id' => 4,
                'action_id' => 0,
            ], false],
            'unknown shape' => [[], false],
        ];
    }
}
