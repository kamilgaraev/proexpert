<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\WorkforceManagement\Services\WorkforceProService;
use App\Models\User;

final readonly class AssistantProjectDailyStaffReader extends AssistantProjectOperationalReader
{
    public const TYPE = 'live_project_daily_staff_projection';

    public function __construct(AssistantDataAccessPolicy $access, private WorkforceProService $calendar)
    {
        parent::__construct($access);
    }

    public static function matches(string $query): bool
    {
        return preg_match('/отсутст|отпуск|больнич|командиров/iu', $query) === 1 && preg_match('/сегодня|сейчас/iu', $query) === 1
            && preg_match('/создай|удали|измени|назначь|оформи|одобри|оплат|зарплат|табел|поставк|задач|смет|бюджет|сч[её]т|график|зависимост|критическ/iu', $query) !== 1;
    }

    protected function domain(): string
    {
        return 'workforce';
    }

    protected function collect(User $actor, int $organizationId, int $projectId): array
    {
        $date = today()->toDateString();
        try {
            $calendar = $this->calendar->scheduleCalendar($organizationId, $date, $date, $projectId, 5000);
        } catch (\DomainException $e) {
            if ($e->getMessage() !== 'calendar_read_limit') {
                throw $e;
            }

return ['available' => false];
        }
        $employees = [];
        $absence = [];
        $trips = [];
        $examples = [];
        foreach ($calendar['employees'] as $row) {
            $id = (int) $row['employee_id'];
            $employees[$id] = true;
            $day = $row['days'][$date];
            if ($day['status'] === 'absence') {
                $absence[$id] = true;
            } elseif ($day['status'] === 'business_trip') {
                $trips[$id] = true;
            } else {
                continue;
            }
            if (count($examples) < 20 && ! isset($examples[$id])) {
                $examples[$id] = ['id' => $id, 'name' => $row['full_name'], 'assignment' => $row['assignment_label'], 'status' => $day['status_label']];
            }
        }

        return ['available' => true, 'employees' => count($employees), 'absent' => count($absence), 'trips' => count($trips), 'examples' => array_values($examples)];
    }

    protected function format(array $data): string
    {
        if (! $data['available']) {
            return trans_message('ai_assistant_operational.daily_staff_limit');
        }
        $lines = [trans_message('ai_assistant_operational.daily_staff_scope', ['project' => self::text($data['project']['name']), 'date' => $data['as_of_date']]),
            trans_message('ai_assistant_operational.daily_staff_counts', ['employees' => $data['employees'], 'absent' => $data['absent'], 'trips' => $data['trips']])];
        foreach ($data['examples'] as $row) {
            $lines[] = '- '.self::text($row['name']).'; '.self::text($row['assignment']).'; '.self::text($row['status']);
        }
        $lines[] = trans_message('ai_assistant_operational.daily_staff_basis');
        $lines[] = trans_message('ai_assistant_operational.examples_basis');

        return implode("\n", $lines);
    }
}
