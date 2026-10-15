<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

readonly class AssistantProjectSiteRequestReader extends AssistantProjectOperationalReader
{
    public const TYPE = 'live_project_site_request_queue_projection';

    public static function matches(string $query): bool
    {
        return preg_match('/заявк/iu', $query) === 1 && preg_match('/объект|проект|площадк/iu', $query) === 1
            && preg_match('/просроч|ответственн|исполнител/iu', $query) === 1
            && preg_match('/создай|удали|назначь|измени|одобри|согласуй|оплат|плат[её]ж|сч[её]т|бюджет|тендер|сотрудник|заказчик/iu', $query) !== 1;
    }

    protected function domain(): string { return 'site_requests'; }
    protected function requestTypes(): ?array { return null; }

    protected function collect(User $actor, int $organizationId, int $projectId): array
    {
        $query = $this->projectQuery($actor, $organizationId, $projectId, 'site_request');
        if ($query === null) { throw new AuthorizationException; }
        if ($this->requestTypes() !== null) { $query->whereIn('request_type', $this->requestTypes()); }
        $active = (clone $query)->whereNotIn('status', ['completed', 'cancelled', 'rejected']);
        $date = today()->toDateString();
        $overdue = (clone $active)->whereNotNull('required_date')->whereDate('required_date', '<', $date)->count();
        $unassigned = (clone $active)->whereNull('assigned_to')->count();
        $attention = (clone $active)->where(static fn (Builder $q) => $q->whereNull('assigned_to')
            ->orWhere(static fn (Builder $q) => $q->whereNotNull('required_date')->whereDate('required_date', '<', $date)));
        $total = (clone $attention)->count();
        $examples = $attention->orderByRaw('required_date IS NULL')->orderBy('required_date')->orderBy('id')->limit(20)
            ->select(['id', 'title', 'status', 'required_date', 'assigned_to'])->with('assignedUser:id,name')->get()
            ->map(static fn ($row): array => ['id' => (int) $row->id, 'title' => $row->title, 'status' => $row->getRawOriginal('status'),
                'date' => $row->required_date?->toDateString(), 'owner' => $row->assignedUser?->name,
                'unassigned' => $row->assigned_to === null, 'overdue' => $row->required_date !== null && $row->required_date->toDateString() < $date])->all();
        return ['active' => (clone $active)->count(), 'overdue' => $overdue, 'unassigned' => $unassigned, 'attention' => $total, 'examples' => $examples];
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_operational.site_scope', ['project' => self::text($data['project']['name']), 'date' => $data['as_of_date']]),
            trans_message('ai_assistant_operational.site_counts', ['active' => $data['active'], 'overdue' => $data['overdue'], 'unassigned' => $data['unassigned'], 'attention' => $data['attention']])];
        if ($this->requestTypes() !== null) { array_splice($lines, 1, 0, [trans_message('ai_assistant_operational.site_material_scope')]); }
        foreach ($data['examples'] as $row) {
            $owner = $row['unassigned'] ? trans_message('ai_assistant_operational.owner_unknown') : ($row['owner'] ?? trans_message('ai_assistant_operational.not_recorded'));
            $lines[] = '- #'.$row['id'].' '.self::text($row['title']).'; '.trans_message('ai_assistant_operational.site_entry', [
                'owner' => self::text($owner), 'date' => $row['date'] ?? trans_message('ai_assistant_operational.not_recorded'),
                'status' => trans_message('ai_assistant_operational.site_status_'.$row['status'])]);
        }
        $lines[] = trans_message('ai_assistant_operational.site_step');
        $lines[] = trans_message('ai_assistant_operational.site_basis');
        $lines[] = trans_message('ai_assistant_operational.examples_basis');
        return implode("\n", $lines);
    }
}
