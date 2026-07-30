<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\Jobs\ReportingSourceBackfillJob;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReportingSourceBackfillCursorTest extends TestCase
{
    #[Test]
    public function missing_or_ineligible_cutoff_target_terminates_with_an_explicit_gap(): void
    {
        $job = new ReportingSourceBackfillJob(
            7,
            ReportingSourceBackfillJob::WORKFORCE_ADMISSION,
        );
        $method = new \ReflectionMethod(ReportingSourceBackfillJob::class, 'linear');

        [$result, $cursor, $hasMore] = $method->invoke(
            $job,
            collect(),
            new EmptyBackfill,
            4,
            9,
        );

        self::assertSame(['id' => 9], $cursor);
        self::assertFalse($hasMore);
        self::assertSame(1, $result['gap_count']);
        self::assertSame(1, $result['unknown_count']);
        self::assertSame(
            ['safety_site_workforce_assignments:missing_target:9'],
            $result['unknown_owner_keys'],
        );
    }
}

final class EmptyBackfill
{
    public function apply(Collection $batch): array
    {
        return [
            'source_count' => $batch->count(),
            'projected_count' => 0,
            'gap_count' => 0,
            'unknown_count' => 0,
            'source_watermark' => null,
        ];
    }
}
