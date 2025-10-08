<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\HR;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class EmployeeAttendanceWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::EMPLOYEE_ATTENDANCE;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $from = $request->from ?? Carbon::now()->subMonth();
        $to = $request->to ?? Carbon::now();

        if (!DB::getSchemaBuilder()->hasTable('time_entries')) {
            return ['attendance' => [], 'message' => 'Time tracking not available'];
        }

        $attendance = DB::table('time_entries')
            ->join('users', 'time_entries.user_id', '=', 'users.id')
            ->join('projects', 'time_entries.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $request->organizationId)
            ->whereBetween('time_entries.date', [$from, $to])
            ->select(
                'users.id as user_id',
                'users.name as user_name',
                DB::raw('COUNT(DISTINCT time_entries.date) as days_worked'),
                DB::raw('SUM(time_entries.hours) as total_hours')
            )
            ->groupBy('users.id', 'users.name')
            ->get();

        return [
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'attendance' => $attendance->map(fn($a) => [
                'user_id' => $a->user_id,
                'user_name' => $a->user_name,
                'days_worked' => $a->days_worked,
                'total_hours' => (float)$a->total_hours,
            ])->toArray(),
        ];
    }
}

