<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\BusinessModules\Features\Notifications\Services\LandingNotificationPreferencesService;
use App\Models\User;

final readonly class AssistantNotificationPreferencesReader extends AssistantOrganizationOperationalReader
{
    public const TYPE = 'live_personal_notification_preferences_projection';

    public static function hasIntent(string $query): bool
    {
        return preg_match('/уведомлен/iu', $query) === 1
            && preg_match('/канал|получаю|предпочтен|включен[а-яё]*|настро[йе]/iu', $query) === 1;
    }

    public static function matches(string $query): bool
    {
        return self::hasIntent($query) && preg_match('/включи|отключи|измени|отправь|удали|пометь|непрочитан|последн|сообщени[а-яё]*\s+из|сотрудник|всей\s+организац|прав[а-яё]*|сч[её]т|бюджет/iu', $query) !== 1;
    }

    protected function collect(User $actor, int $organizationId): array
    {
        $native = app(LandingNotificationPreferencesService::class)->read($actor, $organizationId);

        return ['available_channels' => $native['available_channels'],
            'items' => array_map(static fn (array $row): array => array_intersect_key($row, array_flip(['notification_type', 'name', 'mandatory', 'user_customizable', 'enabled_channels'])), $native['items'])];
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_operational.notification_preferences_scope', ['date' => $data['as_of_date']])];
        foreach ($data['items'] as $row) {
            $channels = array_map(static fn (string $channel): string => trans_message('ai_assistant_operational.notification_channel_'.(in_array($channel, ['email', 'telegram', 'in_app', 'websocket', 'push'], true) ? $channel : 'unknown')), $row['enabled_channels']);
            $lines[] = '- '.self::text($row['name']).': '.($channels === [] ? trans_message('ai_assistant_operational.notification_channels_none') : implode(', ', $channels)).'; '
                .trans_message('ai_assistant_operational.notification_preference_'.($row['user_customizable'] ? 'customizable' : 'fixed'));
        }
        $lines[] = trans_message('ai_assistant_operational.notification_preferences_basis');

        return implode("\n", $lines);
    }
}
