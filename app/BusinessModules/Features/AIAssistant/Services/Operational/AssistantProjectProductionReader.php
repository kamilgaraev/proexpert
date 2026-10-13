<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantProjectProductionReader extends AssistantProjectOperationalReader
{
    public const TYPE = 'live_project_production_readiness_projection';

    public static function matches(string $query): bool
    {
        return preg_match('/производствен[а-яё]*\s+наряд|наряд[а-яё]*.*(?:принят|при[её]мк|закрыти|представлен)/iu', $query) === 1
            && preg_match('/создай|удали|отправь|прими|закрой|измени|оплат|бюджет|смет|журнал|охран|коммерческ|исполнительн|проектн[а-яё]*\s+комплект/iu', $query) !== 1;
    }

    protected function domain(): string { return 'production_labor'; }

    protected function collect(User $actor, int $organizationId, int $projectId): array
    {
        $query = $this->projectQuery($actor, $organizationId, $projectId, 'production_labor_work_order');
        if ($query === null) { throw new AuthorizationException; }
        $counts = self::statusCounts($query);
        $examples = (clone $query)->where('status', 'submitted')->orderBy('planned_finish_date')->orderBy('id')->limit(20)
            ->select(['id', 'order_number', 'title', 'status', 'assignee_type', 'assignee_name', 'planned_finish_date', 'submitted_at'])
            ->withCount('lines')->get()->map(static fn ($row): array => ['id' => (int) $row->id, 'number' => $row->order_number, 'title' => $row->title,
                'assignee_type' => $row->assignee_type, 'assignee_name' => $row->assignee_name, 'finish_date' => $row->planned_finish_date?->toDateString(),
                'submitted_at' => $row->submitted_at?->toIso8601String(), 'lines' => (int) $row->lines_count])->all();
        return ['counts' => $counts, 'total' => array_sum($counts), 'submitted' => $counts['submitted'] ?? 0, 'examples' => $examples];
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_operational.production_scope', ['project' => self::text($data['project']['name']), 'date' => $data['as_of_date']]),
            trans_message('ai_assistant_operational.production_counts', ['total' => $data['total'], 'submitted' => $data['submitted'],
                'progress' => $data['counts']['in_progress'] ?? 0, 'accepted' => $data['counts']['accepted'] ?? 0, 'closed' => $data['counts']['closed'] ?? 0])];
        foreach ($data['examples'] as $row) {
            $lines[] = '- #'.$row['id'].' '.self::text((string) $row['number']).' '.self::text((string) $row['title']).'; '
                .trans_message('ai_assistant_operational.production_entry', ['owner' => self::text((string) ($row['assignee_name'] ?: trans_message('ai_assistant_operational.owner_unknown'))),
                    'date' => $row['finish_date'] ?? trans_message('ai_assistant_operational.not_recorded'), 'lines' => $row['lines']]);
        }
        $lines[] = trans_message('ai_assistant_operational.production_step');
        $lines[] = trans_message('ai_assistant_operational.production_basis');
        $lines[] = trans_message('ai_assistant_operational.examples_basis');
        return implode("\n", $lines);
    }
}
