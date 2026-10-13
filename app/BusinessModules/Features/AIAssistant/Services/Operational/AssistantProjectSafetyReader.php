<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\Models\User;

final readonly class AssistantProjectSafetyReader extends AssistantProjectOperationalReader
{
    public const TYPE = 'live_project_safety_projection';

    public static function matches(string $query): bool
    {
        return preg_match('/охран[а-яё]*\s+труд|безопасност|происшеств|нарушен[а-яё]*.*(?:объект|проект)/iu', $query) === 1
            && preg_match('/создай|измени|удали|закрой|назначь|отправь|согласуй|оплат|оплач|плат[её]ж|сч[её]т|бюджет|смет|стоим|выручк|затрат|договор|при[её]мк|журнал|табел|комплект|критическ[а-яё]*\s+путь/iu', $query) !== 1;
    }

    protected function domain(): string { return 'safety'; }

    protected function collect(User $actor, int $organizationId, int $projectId): array
    {
        $sections = [];
        foreach (['safety_incident' => 'incident', 'safety_violation' => 'violation', 'safety_inspection_finding' => 'finding', 'safety_corrective_action' => 'action'] as $type => $label) {
            $query = $this->projectQuery($actor, $organizationId, $projectId, $type);
            if ($query === null) { $sections[$label] = ['available' => false]; continue; }
            if ($type === 'safety_incident') { $query->whereNotIn('status', ['closed', 'cancelled']); }
            elseif ($type === 'safety_corrective_action') { $query->whereIn('status', ['open', 'resolved']); }
            else { $query->where('status', 'open'); }
            $counts = self::statusCounts($query);
            $total = array_sum($counts);
            $severe = (clone $query)->whereIn('severity', ['high', 'critical'])->count();
            $overdue = $type === 'safety_incident' ? null : (clone $query)->whereDate('due_date', '<', today()->toDateString())->count();
            $columns = ['id', 'title', 'status', 'severity', 'assigned_to_user_id', $type === 'safety_incident' ? 'occurred_at' : 'due_date'];
            $examplesQuery = (clone $query)->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END");
            if ($type !== 'safety_incident') { $examplesQuery->orderByRaw('due_date IS NULL')->orderBy('due_date'); }
            $owners = [];
            $examples = $examplesQuery->orderBy('id')->limit(3)->select($columns)->with('assignedUser:id,name')->get()
                ->map(static function ($row) use (&$owners): array {
                    if ($row->assignedUser instanceof User) { $owners[(int) $row->assignedUser->id] = (string) $row->assignedUser->name; }
                    return $row->getAttributes();
                })->all();
            $sections[$label] = ['available' => true, 'total' => $total, 'severe' => $severe, 'overdue' => $overdue, 'examples' => $examples,
                'owners' => $owners];
        }
        $permits = $this->projectQuery($actor, $organizationId, $projectId, 'safety_work_permit');
        $permitCounts = $permits === null ? null : [
            'active' => (clone $permits)->where('status', 'active')->count(),
            'suspended' => (clone $permits)->where('status', 'suspended')->count(),
            'expiring' => (clone $permits)->whereIn('status', ['approved', 'active', 'suspended'])->whereBetween('valid_until', [today()->toDateString(), today()->addDays(7)->toDateString()])->count(),
        ];
        return ['sections' => $sections, 'permits' => $permitCounts];
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_operational.safety_scope', ['project' => self::text($data['project']['name']), 'date' => $data['as_of_date']])];
        foreach ($data['sections'] as $kind => $section) {
            if (! $section['available']) { $lines[] = trans_message('ai_assistant_operational.safety_unavailable', ['section' => trans_message('ai_assistant_operational.safety_'.$kind)]); continue; }
            $lines[] = trans_message('ai_assistant_operational.safety_counts', ['section' => trans_message('ai_assistant_operational.safety_'.$kind), 'total' => $section['total'], 'critical' => $section['severe'],
                'overdue' => $section['overdue'] ?? trans_message('ai_assistant_operational.not_applicable')]);
            foreach ($section['examples'] as $row) {
                $owner = $section['owners'][$row['assigned_to_user_id']] ?? ($row['assigned_to_user_id'] === null ? trans_message('ai_assistant_operational.owner_unknown')
                    : trans_message('ai_assistant_operational.owner_id', ['id' => $row['assigned_to_user_id']]));
                $severity = in_array($row['severity'], ['low', 'minor', 'medium', 'major', 'high', 'critical'], true) ? $row['severity'] : 'unknown';
                $date = $row['due_date'] ?? $row['occurred_at'] ?? null;
                $lines[] = '- #'.$row['id'].' '.self::text($row['title']).'; '.trans_message('ai_assistant_operational.severity_'.$severity).'; '
                    .trans_message('ai_assistant_operational.safety_owner_date', ['owner' => self::text($owner), 'date' => $date === null ? trans_message('ai_assistant_operational.not_recorded') : substr((string) $date, 0, 10)]);
                $lines[] = trans_message('ai_assistant_operational.safety_step_'.$kind);
            }
        }
        if ($data['permits'] !== null) {
            $lines[] = trans_message('ai_assistant_operational.safety_permits', $data['permits']);
        }
        $lines[] = trans_message('ai_assistant_operational.safety_priority');
        $lines[] = trans_message('ai_assistant_operational.safety_basis');
        $lines[] = trans_message('ai_assistant_operational.examples_basis');
        return implode("\n", $lines);
    }
}
