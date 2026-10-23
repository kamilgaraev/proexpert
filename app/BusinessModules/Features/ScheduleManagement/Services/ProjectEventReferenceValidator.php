<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Services;

use App\BusinessModules\Features\ScheduleManagement\Models\ProjectEvent;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ProjectEventReferenceValidator
{
    public function validate(array $changedFields, ProjectEvent $event): void
    {
        $organizationId = (int) $event->organization_id;
        $projectId = (int) $event->project_id;
        $values = [];
        $rules = [];
        if (array_intersect($changedFields, ['schedule_id', 'related_task_id'])) {
            $values['schedule_id'] = $event->schedule_id;
            $values['related_task_id'] = $event->related_task_id;
            $rules['schedule_id'] = ['nullable', 'integer', Rule::exists('project_schedules', 'id')
                ->where('organization_id', $organizationId)->where('project_id', $projectId)->whereNull('deleted_at')];
            $task = Rule::exists('schedule_tasks', 'id')->where('organization_id', $organizationId)
                ->whereNull('deleted_at')->whereIn('schedule_id', function ($query) use ($organizationId, $projectId): void {
                    $query->select('id')->from('project_schedules')->where('organization_id', $organizationId)
                        ->where('project_id', $projectId)->whereNull('deleted_at');
                });
            if ($event->schedule_id !== null) {
                $task->where('schedule_id', $event->schedule_id);
            }
            $rules['related_task_id'] = ['nullable', 'integer', $task];
        }
        foreach (['participants', 'responsible_users'] as $field) {
            if (!in_array($field, $changedFields, true)) {
                continue;
            }
            $values[$field] = $event->getAttribute($field);
            $rules[$field] = ['nullable', 'array'];
            $rules[$field.'.*'] = ['integer', Rule::exists('users', 'id')->whereNull('deleted_at')
                ->whereIn('id', function ($query) use ($organizationId): void {
                    $query->select('user_id')->from('organization_user')->where('organization_id', $organizationId)->where('is_active', true);
                })];
        }
        Validator::make($values, $rules)->validate();
    }
}
