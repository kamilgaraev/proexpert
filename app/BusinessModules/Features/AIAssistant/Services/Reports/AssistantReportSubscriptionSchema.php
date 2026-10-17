<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Core\Reporting\Application\Subscriptions\ReportSubscriptionPeriodResolver;

final class AssistantReportSubscriptionSchema
{
    public static function create(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => [
            'report_code' => ['type' => 'string', 'maxLength' => 64, 'description' => 'Код из get_report_subscription_catalog.'],
            'name' => ['type' => 'string', 'maxLength' => 120, 'description' => 'Понятное название личной настройки отчёта.'],
            'scope' => ['type' => 'string', 'enum' => ['project', 'organization']],
            'project_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Обязателен для scope=project. Подтверждённый проект текущей организации.'],
            ...self::configuration(), ...self::schedule(),
        ], 'required' => ['report_code', 'name', 'scope', 'columns', 'frequency', 'local_time', 'timezone', 'period_mode', 'format']];
    }

    public static function update(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => [
            'subscription_id' => ['type' => 'string', 'maxLength' => 26, 'description' => 'ULID своей подписки из get_own_report_subscriptions.'],
            'operation' => ['type' => 'string', 'enum' => ['update', 'pause', 'resume']],
            'name' => ['type' => 'string', 'maxLength' => 120],
            'project_id' => ['type' => 'integer', 'minimum' => 1],
            'clear_project_scope' => ['type' => 'boolean', 'enum' => [true], 'description' => 'Явное переключение на всю организацию, если отчёт поддерживает такой охват.'],
            ...self::configuration(), ...self::schedule(),
        ], 'required' => ['subscription_id', 'operation']];
    }

    private static function configuration(): array
    {
        return [
            'columns' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 60, 'items' => ['type' => 'string', 'maxLength' => 64], 'description' => 'Идентификаторы доступных колонок из каталога.'],
            'filters_json' => ['type' => 'string', 'maxLength' => 16000, 'description' => 'JSON-объект фильтров по схеме выбранного отчёта. Для относительного периода даты вычислит сервер. Не передавайте organization_id или project_id через фильтры.'],
            'sort' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                'field' => ['type' => 'string', 'maxLength' => 64], 'direction' => ['type' => 'string', 'enum' => ['asc', 'desc']],
            ], 'required' => ['field', 'direction']],
        ];
    }

    private static function schedule(): array
    {
        return [
            'frequency' => ['type' => 'string', 'enum' => ['daily', 'weekly', 'monthly']],
            'weekday' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 7, 'description' => 'Для weekly: понедельник=1, воскресенье=7.'],
            'day_of_month' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 31],
            'local_time' => ['type' => 'string', 'maxLength' => 5, 'description' => 'Время HH:MM в указанном часовом поясе.'],
            'timezone' => ['type' => 'string', 'maxLength' => 64, 'description' => 'Подтверждённый часовой пояс IANA, например Europe/Moscow.'],
            'period_mode' => ['type' => 'string', 'enum' => ReportSubscriptionPeriodResolver::MODES],
            'format' => ['type' => 'string', 'enum' => ['csv', 'xlsx', 'pdf']],
        ];
    }
}
