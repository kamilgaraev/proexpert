<?php

declare(strict_types=1);

return [
    'task_resource_cost_limit_exceeded' => 'The planned material cost exceeds the allowed amount.',
    'task_resource_cost_reduce_quantity_or_price' => 'Reduce the material quantity or unit price.',
    'task_resource_task_missing' => 'The task for the resource assignment was not found',
    'task_resource_task_not_found' => 'Task not found',
    'task_resource_completed_task' => 'Resources cannot be assigned to a completed task',
    'task_resource_task_completed' => 'The task is already completed',
    'task_resource_end_before_start' => 'The assignment end date must be on or after its start date',
    'task_resource_start_before_task' => 'The assignment cannot start before the task start date',
    'task_resource_end_after_task' => 'The assignment cannot end after the task end date',
    'task_resource_dates_invalid' => 'The resource assignment dates are invalid',
    'task_resource_allocation_invalid' => 'Resource allocation must be between 0 and 100 percent',
    'task_resource_allocation_out_of_range' => 'Allocation must be between 0 and 100 percent',
];
