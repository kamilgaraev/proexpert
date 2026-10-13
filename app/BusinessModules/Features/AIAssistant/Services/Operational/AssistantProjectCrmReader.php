<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantProjectCrmReader extends AssistantProjectOperationalReader
{
    public const TYPE = 'live_project_crm_readiness_projection';

    public static function matches(string $query): bool
    {
        return preg_match('/открыт[а-яё]*\s+(?:crm[-\s]*)?сделк|сделк[\s\S]*(?:ближайш[а-яё]*\s+действ|незаверш[а-яё]*\s+действ)/iu', $query) === 1
            && preg_match('/создай|удали|отправь|закрой|измени|оплат|бюджет|смет|журнал|охран|коммерческ|производствен|исполнительн|график|schedule|выигран|проигран|закрыт|истори|сделк[а-яё]*\s*[«"]|[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}/iu', $query) !== 1;
    }

    protected function domain(): string { return 'crm'; }

    protected function collect(User $actor, int $organizationId, int $projectId): array
    {
        $query = $this->projectQuery($actor, $organizationId, $projectId, 'crm_deal');
        if ($query === null) { throw new AuthorizationException; }
        $counts = self::statusCounts($query);
        $open = (clone $query)->where('status', 'open');
        $deals = (clone $open)->orderBy('next_activity_at')->orderBy('id')->limit(20)
            ->select(['id', 'title', 'status', 'owner_user_id', 'stage_code', 'expected_close_at', 'next_activity_at'])->with('owner:id,name')->get()
            ->map(static fn ($row): array => ['id' => (string) $row->id, 'title' => $row->title, 'stage' => $row->stage_code,
                'owner' => $row->owner?->name, 'expected_close_at' => $row->expected_close_at?->toDateString(), 'next_activity_at' => $row->next_activity_at?->toIso8601String()])->all();
        $activityQuery = $this->access->entityQuery($actor, $organizationId, 'crm_activity');
        $activity = null;
        if ($activityQuery !== null) {
            $activityQuery->whereIn('deal_id', (clone $open)->select('crm_deals.id'))->whereNotIn('status', ['done', 'cancelled']);
            $activity = ['total' => (clone $activityQuery)->count(), 'overdue' => (clone $activityQuery)->where('due_at', '<', now())->count(),
                'examples' => (clone $activityQuery)->orderBy('due_at')->orderBy('id')->limit(20)->select(['id', 'deal_id', 'subject', 'status', 'due_at', 'owner_user_id'])
                    ->with('owner:id,name')->get()->map(static fn ($row): array => ['id' => (string) $row->id, 'deal_id' => (string) $row->deal_id,
                        'subject' => $row->subject, 'status' => $row->status, 'due_at' => $row->due_at?->toIso8601String(), 'owner' => $row->owner?->name])->all()];
        }
        return ['total' => array_sum($counts), 'counts' => $counts, 'open' => $counts['open'] ?? 0, 'deals' => $deals, 'activities' => $activity];
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_operational.crm_scope', ['project' => self::text($data['project']['name']), 'date' => $data['as_of_date']]),
            trans_message('ai_assistant_operational.crm_counts', ['total' => $data['total'], 'open' => $data['open']])];
        foreach ($data['deals'] as $deal) {
            $lines[] = '- '.self::text($deal['id']).' '.self::text((string) $deal['title']).'; '.trans_message('ai_assistant_operational.crm_deal',
                ['owner' => self::text((string) ($deal['owner'] ?: trans_message('ai_assistant_operational.owner_unknown'))),
                    'stage' => self::text((string) $deal['stage']), 'close' => $deal['expected_close_at'] ?? trans_message('ai_assistant_operational.not_recorded'),
                    'next' => $deal['next_activity_at'] ?? trans_message('ai_assistant_operational.not_recorded')]);
        }
        if ($data['activities'] === null) { $lines[] = trans_message('ai_assistant_operational.crm_activity_unavailable'); }
        else {
            $lines[] = trans_message('ai_assistant_operational.crm_activity_counts', ['total' => $data['activities']['total'], 'overdue' => $data['activities']['overdue']]);
            foreach ($data['activities']['examples'] as $activity) {
                $lines[] = '- '.self::text($activity['id']).' '.self::text((string) $activity['subject']).'; '
                    .trans_message('ai_assistant_operational.crm_activity', ['deal' => self::text($activity['deal_id']),
                        'owner' => self::text((string) ($activity['owner'] ?: trans_message('ai_assistant_operational.owner_unknown'))),
                        'due' => $activity['due_at'] ?? trans_message('ai_assistant_operational.not_recorded')]);
            }
        }
        $lines[] = trans_message('ai_assistant_operational.crm_step');
        $lines[] = trans_message('ai_assistant_operational.crm_basis');
        $lines[] = trans_message('ai_assistant_operational.examples_basis');
        return implode("\n", $lines);
    }
}
