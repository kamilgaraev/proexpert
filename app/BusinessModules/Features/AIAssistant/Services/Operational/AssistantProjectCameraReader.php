<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantProjectCameraReader extends AssistantProjectOperationalReader
{
    public const TYPE = 'live_project_camera_roster_projection';
    public const INTENT_PATTERN = '/камер|видеонаблюден/iu';

    public static function matches(string $query): bool
    {
        return preg_match(self::INTENT_PATTERN, $query) === 1
            && preg_match('/создай|удали|измени|подключи|отключи|проверь\s+соединение|обнови|парол|логин|адрес\s+потока|rtsp|оплат|бюджет|сотрудник|договор|поставк|график|задач|критическ[а-яё]*\s+путь|зависимост|расход|прибыл|бим|ifc/iu', $query) !== 1;
    }

    protected function domain(): string { return 'video_monitoring'; }

    protected function collect(User $actor, int $organizationId, int $projectId): array
    {
        $query = $this->projectQuery($actor, $organizationId, $projectId, 'video_camera');
        if ($query === null) { throw new AuthorizationException; }
        $examples = (clone $query)->orderBy('id')->limit(20)->get(['id', 'name', 'status', 'is_enabled', 'last_checked_at'])
            ->map(static fn ($row): array => ['id' => (int) $row->id, 'name' => $row->name, 'status' => $row->status,
                'enabled' => (bool) $row->is_enabled, 'checked_at' => $row->last_checked_at?->utc()->toIso8601String()])->all();

        return ['total' => (clone $query)->count(), 'online' => (clone $query)->where('status', 'online')->count(),
            'offline' => (clone $query)->where('status', 'offline')->count(), 'disabled' => (clone $query)->where('is_enabled', false)->count(),
            'unchecked' => (clone $query)->whereNull('last_checked_at')->count(), 'examples' => $examples];
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_operational.camera_scope', ['project' => self::text($data['project']['name']), 'date' => $data['as_of_date']]),
            trans_message('ai_assistant_operational.camera_counts', array_intersect_key($data, array_flip(['total', 'online', 'offline', 'disabled', 'unchecked'])))];
        foreach ($data['examples'] as $row) {
            $lines[] = '- #'.$row['id'].' '.self::text($row['name']).'; '.trans_message('video_monitoring.status.'.$row['status']).'; '
                .trans_message('ai_assistant_operational.camera_state_'.($row['enabled'] ? 'enabled' : 'disabled')).'; '
                .trans_message('ai_assistant_operational.camera_checked', ['date' => $row['checked_at'] ?? trans_message('ai_assistant_operational.not_recorded')]);
        }
        $lines[] = trans_message('ai_assistant_operational.camera_basis');
        $lines[] = trans_message('ai_assistant_operational.examples_basis');

        return implode("\n", $lines);
    }
}
