<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\HrEvent;
use Illuminate\Support\Facades\DB;

final class AssignmentHistoryService
{
    public function forEmployee(int $organizationId, int $employeeId): array
    {
        $records = DB::table('workforce_employee_assignments')->where('organization_id', $organizationId)->where('employee_id', $employeeId)->get();
        $events = HrEvent::query()->where('organization_id', $organizationId)->where('employee_id', $employeeId)
            ->whereIn('event_type', ['assignment_created', 'assignment_changed'])->orderByDesc('id')->get();
        $states = $records->map(static fn (object $record): array => (array) $record)->all();
        foreach ($events as $event) {
            foreach ([$event->before_state, $event->after_state] as $state) {
                if (is_array($state)) {
                    $states[] = $state;
                }
            }
        }
        $labels = [];
        foreach (['department_id' => ['workforce_departments', 'name'], 'position_id' => ['workforce_positions', 'name'],
            'project_id' => ['projects', 'name'], 'work_schedule_id' => ['workforce_work_schedules', 'name']] as $field => [$table, $label]) {
            $ids = array_values(array_unique(array_filter(array_column($states, $field))));
            $labels[$field] = $ids === [] ? [] : DB::table($table)->where('organization_id', $organizationId)->whereIn('id', $ids)->pluck($label, 'id')->all();
        }
        $history = [];
        $represented = [];
        foreach ($events as $event) {
            if (! is_array($event->after_state)) {
                continue;
            }
            $after = $this->state($event->after_state, $labels);
            $represented[$this->fingerprint($after)] = true;
            $history[] = $after + ['history_id' => 'event-'.$event->id, 'source' => 'history_event', 'event_id' => (int) $event->id,
                'event_type' => $event->event_type, 'effective_date' => $event->effective_date->toDateString(),
                'recorded_at' => $event->recorded_at->toIso8601String(), 'basis_reference' => $event->basis_reference,
                'before_state' => is_array($event->before_state) ? $this->state($event->before_state, $labels) : null];
        }
        foreach ($records as $record) {
            $state = $this->state((array) $record, $labels);
            if (! isset($represented[$this->fingerprint($state)])) {
                $history[] = $state + ['history_id' => 'record-'.$record->id, 'source' => 'assignment_record', 'event_id' => null,
                    'event_type' => null, 'effective_date' => $record->valid_from, 'recorded_at' => $record->updated_at,
                    'basis_reference' => null, 'before_state' => null];
            }
        }
        usort($history, static fn (array $left, array $right): int => strcmp((string) $right['valid_from'], (string) $left['valid_from'])
            ?: strcmp((string) $right['recorded_at'], (string) $left['recorded_at']));
        return $history;
    }

    private function state(array $state, array $labels): array
    {
        return ['id' => (int) ($state['id'] ?? 0), 'department_id' => isset($state['department_id']) ? (int) $state['department_id'] : null,
            'position_id' => isset($state['position_id']) ? (int) $state['position_id'] : null, 'project_id' => isset($state['project_id']) ? (int) $state['project_id'] : null,
            'work_schedule_id' => isset($state['work_schedule_id']) ? (int) $state['work_schedule_id'] : null,
            'department_label' => $labels['department_id'][$state['department_id'] ?? 0] ?? null,
            'position_label' => $labels['position_id'][$state['position_id'] ?? 0] ?? null,
            'project_label' => $labels['project_id'][$state['project_id'] ?? 0] ?? null,
            'schedule_label' => $labels['work_schedule_id'][$state['work_schedule_id'] ?? 0] ?? null,
            'rate' => isset($state['rate']) ? (float) $state['rate'] : null, 'valid_from' => $state['valid_from'] ?? null,
            'valid_to' => $state['valid_to'] ?? null, 'status' => $state['status'] ?? null];
    }

    private function fingerprint(array $state): string
    {
        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }
}
