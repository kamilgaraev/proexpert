<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class AssistantProjectStaffReader extends AssistantProjectOperationalReader
{
    public const TYPE = 'live_project_staff_roster_projection';

    public static function matches(string $query): bool
    {
        if (AssistantAccessRecertificationReader::hasIntent($query)) { return false; }
        return preg_match('/сотрудник|персонал|состав[а-яё]*\s+(?:объект|проект)|кто.*(?:числится|назначен)/iu', $query) === 1
            && preg_match('/создай|удали|назначь|уволь|измени|рол[ьи]|прав[а-яё]*|администратор|уч[её]тн|пользовател|зарплат|оклад|оплат|выплат|табел|отработ|работал|смен|телефон|почт|допуск|паспорт|график|отсутст|отпуск|больнич|командиров|зависимост|критическ[а-яё]*\s+путь|задач[а-яё]*.*просроч|за\s+(?:прошл|сентябр|октябр)|на\s+(?:завтра|следующ)/iu', $query) !== 1;
    }

    protected function domain(): string
    {
        return 'workforce';
    }

    protected function collect(User $actor, int $organizationId, int $projectId): array
    {
        $date = today()->toDateString();
        $query = DB::table('workforce_employee_assignments as assignment')
            ->join('workforce_employees as employee', 'employee.id', '=', 'assignment.employee_id')
            ->join('workforce_departments as department', 'department.id', '=', 'assignment.department_id')
            ->join('workforce_positions as position', 'position.id', '=', 'assignment.position_id')
            ->where('assignment.organization_id', $organizationId)->where('assignment.project_id', $projectId)
            ->where('employee.organization_id', $organizationId)->where('department.organization_id', $organizationId)->where('position.organization_id', $organizationId)
            ->where('assignment.status', 'active')->whereNull('assignment.deleted_at')->whereNull('employee.deleted_at')
            ->whereDate('assignment.valid_from', '<=', $date)
            ->where(static fn (Builder $q) => $q->whereNull('assignment.valid_to')->orWhereDate('assignment.valid_to', '>=', $date));
        $total = (clone $query)->distinct()->count('employee.id');
        $assignments = (clone $query)->count();
        $examples = (clone $query)->orderBy('employee.last_name')->orderBy('employee.first_name')->orderBy('assignment.id')->limit(20)
            ->select(['assignment.id', 'assignment.employee_id', 'assignment.valid_from', 'assignment.valid_to',
                'employee.last_name', 'employee.first_name', 'employee.middle_name', 'department.name as department', 'position.name as position'])
            ->get()->map(static fn ($row): array => ['id' => (int) $row->id, 'employee_id' => (int) $row->employee_id,
                'name' => trim(implode(' ', array_filter([$row->last_name, $row->first_name, $row->middle_name]))),
                'department' => $row->department, 'position' => $row->position, 'from' => $row->valid_from, 'to' => $row->valid_to])->all();

        return ['employees' => $total, 'assignments' => $assignments, 'examples' => $examples];
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_operational.staff_scope', ['project' => self::text($data['project']['name']), 'date' => $data['as_of_date']]),
            trans_message('ai_assistant_operational.staff_counts', ['employees' => $data['employees'], 'assignments' => $data['assignments']])];
        foreach ($data['examples'] as $row) {
            $lines[] = '- '.self::text($row['name']).'; '.self::text($row['position']).'; '.self::text($row['department']).'; '
                .trans_message('ai_assistant_operational.staff_dates', ['from' => $row['from'], 'to' => $row['to'] ?? trans_message('ai_assistant_operational.staff_open_end')]);
        }
        $lines[] = trans_message('ai_assistant_operational.staff_basis');
        $lines[] = trans_message('ai_assistant_operational.examples_basis');

        return implode("\n", $lines);
    }
}
