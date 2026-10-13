<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestProjectScope;
use App\DTOs\Activity\ActivityEventFilters;
use App\Models\User;
use App\Services\Activity\ActivityEventQueryService;
use Illuminate\Http\Request;

final readonly class AssistantOrganizationActivityReader extends AssistantOrganizationOperationalReader
{
    public const TYPE = 'live_organization_activity_projection';
    public function __construct(AssistantDataAccessPolicy $access, private ActivityEventQueryService $events) { parent::__construct($access); }

    public static function hasIntent(string $query): bool
    {
        return preg_match('/журнал[а-яё]*\s+(?:событи|действи)|событи|кто.*(?:изменял|вносил)|действи[яй].*(?:выполняли|системе|семь|7)/iu', $query) === 1;
    }

    public static function matches(string $query): bool
    {
        return self::hasIntent($query) && AssistantRequestProjectScope::organizationWide($query)
            && preg_match('/последн[а-яё]*\s+(?:семь|7)\s+дн/iu', $query) === 1
            && preg_match('/создай|удали|измени|очисти|поставщик|сотрудник|акт(?:ы|ов)\b|тендер|заявк|уведомлен|оплат|расход|бюджет|смет|график/iu', $query) !== 1;
    }

    protected function collect(User $actor, int $organizationId): array
    {
        $from = today()->subDays(6)->toDateString(); $to = today()->toDateString();
        if (! $this->access->canCurrentPermission($actor, $organizationId, 'system-logs.activity-events.view')) {
            return ['available' => false, 'date_from' => $from, 'date_to' => $to];
        }
        $filters = ActivityEventFilters::fromRequest(Request::create('/', 'GET', ['date_from' => $from, 'date_to' => $to]));
        $summary = $this->events->summary($organizationId, $filters);
        $examples = $this->events->recentReadFacts($organizationId, $filters)->map(static fn ($row): array => [
            'id' => (int) $row->id, 'actor' => $row->actor_name, 'title' => $row->title, 'action' => $row->action,
            'result' => $row->result, 'occurred_at' => $row->occurred_at?->toIso8601String()])->all();
        return ['available' => true, 'date_from' => $from, 'date_to' => $to, 'total' => $summary['total'],
            'failed' => $summary['failed'], 'warnings' => $summary['warnings'], 'examples' => $examples];
    }

    protected function format(array $data): string
    {
        if (! $data['available']) { return trans_message('ai_assistant_operational.activity_unavailable'); }
        $lines = [trans_message('ai_assistant_operational.activity_scope', ['from' => $data['date_from'], 'to' => $data['date_to']]),
            trans_message('ai_assistant_operational.activity_counts', ['total' => $data['total'], 'failed' => $data['failed'], 'warnings' => $data['warnings']])];
        foreach ($data['examples'] as $row) {
            $action = in_array($row['action'], ['created', 'updated', 'deleted', 'viewed', 'approved', 'rejected', 'cancelled', 'assigned', 'revoked', 'exported', 'login', 'logout'], true) ? $row['action'] : 'unknown';
            $result = in_array($row['result'], ['success', 'failed', 'blocked'], true) ? $row['result'] : 'unknown';
            $lines[] = '- #'.$row['id'].' '.self::text($row['actor'] ?: trans_message('ai_assistant_operational.owner_unknown')).'; '
                .self::text((string) $row['occurred_at']).'; '.self::text((string) $row['title']).'; '
                .trans_message('ai_assistant_operational.activity_action_'.$action).'; '.trans_message('ai_assistant_operational.activity_result_'.$result);
        }
        $lines[] = trans_message('ai_assistant_operational.activity_basis');
        $lines[] = trans_message('ai_assistant_operational.examples_basis');
        return implode("\n", $lines);
    }
}
