<?php

declare(strict_types=1);

namespace Tests\Unit\BasicWarehouse;

use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseTask;
use App\Services\Mobile\MobileWarehouseTaskService;
use Tests\TestCase;

class MobileWarehouseTaskServiceTest extends TestCase
{
    public function test_serialize_task_exposes_labels_and_available_transitions(): void
    {
        $task = new WarehouseTask();
        $task->forceFill([
            'id' => 15,
            'warehouse_id' => 3,
            'task_number' => 'WH-15',
            'title' => 'Переместить цемент',
            'task_type' => WarehouseTask::TYPE_TRANSFER,
            'status' => WarehouseTask::STATUS_QUEUED,
            'priority' => WarehouseTask::PRIORITY_HIGH,
            'metadata' => [],
        ]);

        $payload = app(MobileWarehouseTaskService::class)->serializeTask($task);

        $this->assertSame('Перемещение', $payload['task_type_label']);
        $this->assertSame('В очереди', $payload['status_label']);
        $this->assertSame('Высокий', $payload['priority_label']);
        $this->assertSame([
            ['status' => WarehouseTask::STATUS_IN_PROGRESS, 'name' => 'Взять в работу'],
            ['status' => WarehouseTask::STATUS_BLOCKED, 'name' => 'Заблокировать'],
            ['status' => WarehouseTask::STATUS_CANCELLED, 'name' => 'Отменить'],
        ], $payload['available_transitions']);
    }

    public function test_serialize_blocked_task_exposes_the_status_to_resume(): void
    {
        $task = new WarehouseTask();
        $task->forceFill([
            'id' => 16,
            'warehouse_id' => 3,
            'task_number' => 'WH-16',
            'title' => 'Проверить паллету',
            'task_type' => WarehouseTask::TYPE_INSPECTION,
            'status' => WarehouseTask::STATUS_BLOCKED,
            'blocked_from_status' => WarehouseTask::STATUS_IN_PROGRESS,
            'priority' => WarehouseTask::PRIORITY_NORMAL,
            'metadata' => [],
        ]);

        $payload = app(MobileWarehouseTaskService::class)->serializeTask($task);

        $this->assertSame(WarehouseTask::STATUS_IN_PROGRESS, $payload['resume_status']);
        $this->assertTrue($payload['can_edit']);
        $this->assertSame([
            ['status' => WarehouseTask::STATUS_QUEUED, 'name' => 'Вернуть в очередь'],
            ['status' => WarehouseTask::STATUS_IN_PROGRESS, 'name' => 'Взять в работу'],
            ['status' => WarehouseTask::STATUS_CANCELLED, 'name' => 'Отменить'],
        ], $payload['available_transitions']);
    }
}
