<?php

declare(strict_types=1);

namespace Tests\Unit\CompletedWork;

use App\Http\Resources\Api\V1\Admin\CompletedWork\CompletedWorkResource;
use App\Models\CompletedWork;
use App\Models\ScheduleTask;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CompletedWorkResourceQuantityTest extends TestCase
{
    public function test_fact_quantity_is_not_replaced_by_the_entire_task_progress(): void
    {
        $work = new CompletedWork;
        $work->setRawAttributes(['quantity' => '10.000', 'completed_quantity' => null]);
        $task = new ScheduleTask;
        $task->setRawAttributes(['completed_quantity' => '80.0000']);
        $resource = new CompletedWorkResource($work);

        self::assertSame(10.0, (new ReflectionMethod($resource, 'resolveCompletedQuantity'))->invoke($resource, $task, true));
    }

    public function test_zero_price_is_a_value_and_not_a_request_to_use_the_contract_price(): void
    {
        $work = new CompletedWork;
        $work->setRawAttributes(['price' => '0.00']);
        $resource = new CompletedWorkResource($work);

        self::assertSame(0.0, (new ReflectionMethod($resource, 'resolvePrice'))->invoke($resource, null));
    }
}
