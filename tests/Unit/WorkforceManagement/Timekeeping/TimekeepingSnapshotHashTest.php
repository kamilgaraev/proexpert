<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement\Timekeeping;

use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services\TimekeepingSnapshotHash;
use PHPUnit\Framework\TestCase;

final class TimekeepingSnapshotHashTest extends TestCase
{
    public function test_jsonb_object_key_reordering_does_not_change_frozen_digest(): void
    {
        self::assertSame(TimekeepingSnapshotHash::calculate(['month' => '2026-09', 'rows' => [['employee_id' => 1, 'sources' => [['type' => 'time_entry', 'id' => 2]]]]]), TimekeepingSnapshotHash::calculate(['rows' => [['sources' => [['id' => 2, 'type' => 'time_entry']], 'employee_id' => 1]], 'month' => '2026-09']));
    }

    public function test_changing_fact_or_ordered_day_list_changes_digest(): void
    {
        $original = ['days' => [['work_date' => '2026-09-01', 'fact_microseconds' => 1], ['work_date' => '2026-09-02', 'fact_microseconds' => 2]]];
        self::assertNotSame(TimekeepingSnapshotHash::calculate($original), TimekeepingSnapshotHash::calculate(['days' => array_reverse($original['days'])]));
        $changed = $original;
        $changed['days'][0]['fact_microseconds'] = 2;
        self::assertNotSame(TimekeepingSnapshotHash::calculate($original), TimekeepingSnapshotHash::calculate($changed));
    }
}
