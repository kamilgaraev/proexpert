<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use App\BusinessModules\Features\Notifications\Services\NotificationQueryService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantOrganizationNotificationsReader extends AssistantOrganizationOperationalReader
{
    public const TYPE = 'live_personal_notification_queue_projection';
    public function __construct(AssistantDataAccessPolicy $access, private NotificationQueryService $notifications) { parent::__construct($access); }

    public static function hasIntent(string $query): bool { return preg_match('/уведомлен/iu', $query) === 1; }

    public static function matches(string $query): bool
    {
        return self::hasIntent($query) && preg_match('/непрочитан/iu', $query) === 1 && preg_match('/у\s+меня|мо[ийеё][а-яё]*|мне/iu', $query) === 1
            && preg_match('/создай|удали|отправь|прочитай\s+все|пометь|измени|всей\s+организац|всем\s+сотрудник|поставщик|договор|тендер|заявк|смет|бюджет|график/iu', $query) !== 1;
    }

    protected function collect(User $actor, int $organizationId): array
    {
        if (! $this->access->canCurrentPermission($actor, $organizationId, 'admin.access')) { throw new AuthorizationException; }
        $query = $this->notifications->unreadFor($actor, NotificationInterface::Admin, $organizationId);
        $total = (clone $query)->count();
        $important = (clone $query)->whereIn('priority', ['high', 'urgent'])->count();
        $examples = (clone $query)->without('targets')->orderByDesc('created_at')->orderByDesc('id')->limit(20)
            ->select(['id', 'priority', 'created_at'])
            ->selectRaw("LEFT(COALESCE(NULLIF((CASE WHEN data IS JSON OBJECT THEN data::jsonb ELSE '{}'::jsonb END)->>'title', ''), NULLIF((CASE WHEN data IS JSON OBJECT THEN data::jsonb ELSE '{}'::jsonb END)->>'subject', ''), NULLIF((CASE WHEN data IS JSON OBJECT THEN data::jsonb ELSE '{}'::jsonb END)->>'name', ''), ''), 255) AS assistant_title")
            ->selectRaw("LEFT(COALESCE(NULLIF((CASE WHEN data IS JSON OBJECT THEN data::jsonb ELSE '{}'::jsonb END)->>'message', ''), NULLIF((CASE WHEN data IS JSON OBJECT THEN data::jsonb ELSE '{}'::jsonb END)->>'description', ''), NULLIF((CASE WHEN data IS JSON OBJECT THEN data::jsonb ELSE '{}'::jsonb END)->>'body', ''), NULLIF((CASE WHEN data IS JSON OBJECT THEN data::jsonb ELSE '{}'::jsonb END)->>'text', ''), ''), 512) AS assistant_message")
            ->get()->map(static fn ($row): array => ['id' => (string) $row->id, 'priority' => $row->priority,
                'date' => $row->created_at?->toIso8601String(), 'title' => $row->getAttribute('assistant_title'), 'message' => $row->getAttribute('assistant_message')])->all();
        return ['total' => $total, 'important' => $important, 'examples' => $examples];
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_operational.notification_scope', ['date' => $data['as_of_date']]),
            trans_message('ai_assistant_operational.notification_counts', ['total' => $data['total'], 'important' => $data['important']])];
        foreach ($data['examples'] as $row) {
            $priority = in_array($row['priority'], ['low', 'normal', 'high', 'urgent'], true) ? $row['priority'] : 'unknown';
            $lines[] = '- '.self::text($row['title'] ?: trans_message('ai_assistant_operational.notification_title')).'; '.self::text((string) $row['date']).'; '
                .trans_message('ai_assistant_operational.notification_priority_'.$priority);
            if ($row['message'] !== '') { $lines[] = self::text($row['message']); }
        }
        $lines[] = trans_message('ai_assistant_operational.notification_basis');
        $lines[] = trans_message('ai_assistant_operational.examples_basis');
        return implode("\n", $lines);
    }
}

