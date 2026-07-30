<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Backfill\SafetyExposureBackfill;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Services\SafetyExposureProjector;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SafetyExposureBackfillTest extends TestCase
{
    #[Test]
    public function late_correction_replaces_previous_employee_state_for_the_day(): void
    {
        $backfill = new SafetyExposureBackfill(new SafetyExposureProjector);
        $corrections = collect([
            (object) ['id' => 1, 'employee_id' => 7, 'status' => 'at_work', 'hours' => '8.00'],
            (object) ['id' => 2, 'employee_id' => 8, 'status' => 'at_work', 'hours' => '6.00'],
            (object) ['id' => 3, 'employee_id' => 7, 'status' => 'absent', 'hours' => null],
        ]);

        $latest = $backfill->latestDailyCorrections($corrections);

        self::assertCount(1, $latest);
        self::assertSame(8, $latest->first()->employee_id);
        self::assertSame('6.00', $latest->first()->hours);
    }

    #[Test]
    public function synchronization_advances_by_source_cursor_across_all_pages(): void
    {
        $backfill = new class(new SafetyExposureProjector) extends SafetyExposureBackfill {
            public array $cursors = [];

            public function nextBatch(int $organizationId, int $afterId, int $limit = 500): Collection
            {
                $this->cursors[] = $afterId;

                return match ($afterId) {
                    0 => collect([(object) ['id' => 1], (object) ['id' => 2]]),
                    2 => collect([(object) ['id' => 3]]),
                    default => collect(),
                };
            }

            public function apply(int $organizationId, Collection $batch): array
            {
                return [
                    'source_count' => $batch->count(),
                    'projected_count' => $batch->count(),
                    'gap_count' => 0,
                ];
            }
        };

        $result = $backfill->synchronize(9, 2);

        self::assertSame([0, 2], $backfill->cursors);
        self::assertSame(3, $result['source_count']);
        self::assertSame(3, $result['projected_count']);
        self::assertSame(0, $result['gap_count']);
    }

    #[Test]
    public function unmapped_owner_correction_is_a_gap_and_is_not_projected(): void
    {
        $backfill = new class(new SafetyExposureProjector) extends SafetyExposureBackfill {
            protected function siteIdsForCorrection(int $organizationId, object $correction): Collection
            {
                return collect();
            }
        };
        $batch = collect([(object) [
            'id' => 11,
            'project_id' => 2,
            'employee_id' => 3,
            'work_date' => '2026-07-01',
            'status' => 'at_work',
            'hours' => '8.00',
            'updated_at' => '2026-07-01 18:00:00',
        ]]);

        $result = $backfill->apply(1, $batch);

        self::assertSame(1, $result['source_count']);
        self::assertSame(0, $result['projected_count']);
        self::assertSame(1, $result['gap_count']);
    }
}
