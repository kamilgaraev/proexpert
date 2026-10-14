<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services;

use App\BusinessModules\Features\TimeTracking\Sources\ApprovedWorkTimeSourceReader;
use App\BusinessModules\Features\WorkforceManagement\Domain\Scheduling\WorkforceWeekPattern;
use Illuminate\Support\Facades\DB;

final class TimekeepingSourceReader
{
    public function __construct(private readonly ApprovedWorkTimeSourceReader $facts) {}

    public function read(int $organizationId, string $month, ?int $projectId, int $periodId): array
    {
        $from = $month.'-01';
        $to = (new \DateTimeImmutable($from))->modify('last day of this month')->format('Y-m-d');
        $employees = DB::table('workforce_employees')->where('organization_id', $organizationId)->whereNull('deleted_at')->orderBy('id')->get();
        $assignments = DB::table('workforce_employee_assignments')->where('organization_id', $organizationId)->whereNull('deleted_at')->where('status', 'active')->where('valid_from', '<=', $to)->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $from))->get();
        $relations = DB::table('workforce_employment_contracts')->where('organization_id', $organizationId)->whereNull('deleted_at')->where('status', 'active')->where('start_date', '<=', $to)->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $from))->get()->groupBy('employee_id');
        $schedules = DB::table('workforce_work_schedules')->where('organization_id', $organizationId)->whereNull('deleted_at')->get()->keyBy('id');
        $scheduleDays = DB::table('workforce_work_schedule_days')->where('organization_id', $organizationId)->whereBetween('work_date', [$from, $to])->get()->keyBy(fn ($day) => $day->work_schedule_id.':'.$day->work_date);
        $absences = DB::table('workforce_absences as a')->join('workforce_absence_types as t', fn ($join) => $join->on('a.absence_type_id', '=', 't.id')->on('a.organization_id', '=', 't.organization_id'))->where('a.organization_id', $organizationId)->whereNull('a.deleted_at')->where('a.start_date', '<=', $to)->where('a.end_date', '>=', $from)->select(['a.*', 't.code'])->get()->groupBy('employee_id');
        $trips = DB::table('workforce_business_trips')->where('organization_id', $organizationId)->whereNull('deleted_at')->whereIn('status', ['draft', 'approved'])
            ->where('start_date', '<=', $to)->where('end_date', '>=', $from)
            ->when($projectId !== null, fn ($q) => $q->where(fn ($scope) => $scope->whereNull('project_id')->orWhere('project_id', $projectId)))->get()->groupBy('employee_id');
        $corrections = DB::table('workforce_attendance_corrections')->where('organization_id', $organizationId)->whereBetween('work_date', [$from, $to])->when($projectId !== null, fn ($q) => $q->where(fn ($scope) => $scope->whereNull('project_id')->orWhere('project_id', $projectId)))->orderByDesc('id')->get()->groupBy('employee_id');
        $presence = DB::table('workforce_attendance_scan_events')->where('organization_id', $organizationId)->where('result', 'confirmed')->whereBetween('work_date', [$from, $to])->when($projectId !== null, fn ($q) => $q->where(fn ($scope) => $scope->whereNull('project_id')->orWhere('project_id', $projectId)))->orderByDesc('id')->get()->groupBy('employee_id');
        $cells = DB::table('workforce_timekeeping_cells')->where('period_id', $periodId)->orderByDesc('revision')->get()->unique(fn ($c) => $c->employee_id.':'.($c->employment_contract_id ?? 0).':'.$c->work_date)->groupBy('employee_id');
        $result = [];
        foreach ($employees as $employee) {
            $employeeAssignments = $assignments->where('employee_id', $employee->id)->filter(fn ($a) => $projectId === null || $a->project_id === $projectId);
            if ($projectId !== null && $employeeAssignments->isEmpty()) {
                continue;
            }
            $employeeRelations = ($relations[$employee->id] ?? collect())->map(function ($r): array {
                $r = (array) $r;
                $r['metadata'] = json_decode($r['metadata'] ?? '{}', true) ?: [];

                return $r;
            })->all();
            if ($employeeRelations === []) { // Legacy cards are visible and explicitly require reconciliation.
                if (substr((string) $employee->hire_date, 0, 10) > $to) {
                    continue;
                }
                if ($employee->dismissal_date !== null && substr((string) $employee->dismissal_date, 0, 10) < $from) {
                    continue;
                }
            }
            $plans = [];
            foreach ($employeeAssignments as $assignment) {
                $schedule = $schedules[$assignment->work_schedule_id] ?? null;
                if ($schedule === null) {
                    continue;
                }
                $pattern = WorkforceWeekPattern::hoursByIsoWeekday($schedule->schedule_type, $schedule->week_pattern, $schedule->hours_per_day);
                for ($cursor = new \DateTimeImmutable($from); $cursor->format('Y-m-d') <= $to; $cursor = $cursor->modify('+1 day')) {
                    $date = $cursor->format('Y-m-d');
                    if ($date < $assignment->valid_from || ($assignment->valid_to !== null && $date > $assignment->valid_to)) {
                        continue;
                    }
                    $override = $scheduleDays[$schedule->id.':'.$date] ?? null;
                    $hours = $override !== null ? ($override->day_type === 'work' ? (float) $override->planned_hours : 0) : (float) ($pattern[$cursor->format('N')] ?? 0);
                    $plans[] = ['work_date' => $date, 'employment_contract_id' => $assignment->employment_contract_id ?? null, 'minutes' => (int) round($hours * 60), 'source' => ['type' => 'schedule', 'id' => $override?->id ?? $schedule->id, 'revision' => (string) ($override?->updated_at ?? $schedule->updated_at)]];
                }
            }
            $facts = $employee->user_id !== null ? $this->facts->read($organizationId, (int) $employee->user_id, $from, $to, $projectId) : [];
            // Presence/corrections without located intervals are evidence requiring reconciliation, never measured hours.
            foreach (($corrections[$employee->id] ?? collect())->unique('work_date') as $correction) {
                if ($correction->hours !== null && (float) $correction->hours > 0) {
                    $facts[] = ['source_type' => 'attendance_correction', 'source_id' => $correction->id, 'work_date' => $correction->work_date, 'hours_worked' => $correction->hours, 'requires_reconciliation' => true];
                }
            }
            $employeeCells = ($cells[$employee->id] ?? collect())->map(static fn ($c): array => array_merge(json_decode($c->payload, true), ['id' => $c->id, 'revision' => $c->revision, 'employment_contract_id' => $c->employment_contract_id, 'work_date' => $c->work_date]))->all();
            $employeeAbsences = ($absences[$employee->id] ?? collect())->map(static fn ($a): array => (array) $a)
                ->merge(($trips[$employee->id] ?? collect())->map(static fn ($trip): array => array_merge((array) $trip, ['code' => 'business_trip', 'source_type' => 'business_trip'])))->all();
            $plans = array_values(array_reduce($plans, static function (array $unique, array $plan): array {
                $unique[$plan['work_date'].':'.$plan['source']['id'].':'.($plan['employment_contract_id'] ?? 0)] = $plan;

                return $unique;
            }, []));
            $result[] = ['id' => (int) $employee->id, 'personnel_number' => $employee->personnel_number, 'full_name' => trim($employee->last_name.' '.$employee->first_name.' '.($employee->middle_name ?? '')), 'relations' => $employeeRelations, 'plans' => $plans, 'presence' => ($presence[$employee->id] ?? collect())->unique('work_date')->map(static fn ($scan): array => ['work_date' => $scan->work_date, 'status' => 'confirmed', 'source' => ['type' => 'attendance_presence', 'id' => $scan->id, 'revision' => $scan->updated_at]])->all(), 'presence_corrections' => ($corrections[$employee->id] ?? collect())->unique('work_date')->map(static fn ($c): array => ['work_date' => $c->work_date, 'status' => $c->status, 'source' => ['type' => 'attendance_correction', 'id' => $c->id, 'revision' => $c->updated_at]])->all(), 'facts' => $facts, 'cells' => $employeeCells, 'absences' => $employeeAbsences];
        }

        return $result;
    }
}
