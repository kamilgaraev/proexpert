<?php

declare(strict_types=1);

namespace Tests\Unit\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Models\BimConstructionProgressGroup;
use App\BusinessModules\Features\DesignManagement\Services\BimConstructionProgressService;
use App\Models\CompletedWork;
use App\Models\ScheduleTask;
use Illuminate\Support\Collection;
use Tests\TestCase;

final class BimConstructionProgressServiceTest extends TestCase
{
    public function test_actual_uses_confirmed_quantity_and_the_first_date_reaching_planned_volume(): void
    {
        $group = new BimConstructionProgressGroup(['schedule_task_id' => 17]);
        $group->setRelation('task', new ScheduleTask(['quantity' => '10.0000']));
        $actual = $this->actual($group, collect([
            new CompletedWork(['completed_quantity' => '10.0000', 'completion_date' => '2026-01-01']),
            new CompletedWork(['completed_quantity' => '1.0000', 'completion_date' => '2026-02-01']),
        ]));

        self::assertSame(11.0, $actual['confirmed_quantity']);
        self::assertSame(100, $actual['progress_percent']);
        self::assertTrue($actual['completed']);
        self::assertSame('2026-01-01', $actual['completed_at']);
    }

    public function test_actual_does_not_invent_completed_quantity_or_completion_date(): void
    {
        $group = new BimConstructionProgressGroup(['schedule_task_id' => 17]);
        $group->setRelation('task', new ScheduleTask(['quantity' => '10.0000']));
        $actual = $this->actual($group, collect([
            new CompletedWork(['quantity' => '10.0000', 'completed_quantity' => null, 'completion_date' => '2026-01-01']),
        ]));

        self::assertSame(0.0, $actual['confirmed_quantity']);
        self::assertFalse($actual['completed']);
        self::assertNull($actual['completed_at']);
    }

    public function test_actual_does_not_complete_when_one_decimal4_step_is_missing(): void
    {
        $group = new BimConstructionProgressGroup(['schedule_task_id' => 17]);
        $group->setRelation('task', new ScheduleTask(['quantity' => '10.0000']));
        $actual = $this->actual($group, collect([
            new CompletedWork(['completed_quantity' => '9.9999', 'completion_date' => '2026-01-01']),
        ]));

        self::assertFalse($actual['completed']);
        self::assertNull($actual['completed_at']);
    }

    /** @return array<string, mixed> */
    private function actual(BimConstructionProgressGroup $group, Collection $works): array
    {
        $service = (new \ReflectionClass(BimConstructionProgressService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($service, 'actual');
        $method->setAccessible(true);

        return $method->invoke($service, $group, $works);
    }

}
