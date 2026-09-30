<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\DomainMetadata;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainDefinition;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\OneCExchangeRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\ReportTemplateRagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\VideoMonitoringRagSource;
use App\BusinessModules\Features\VideoMonitoring\Models\VideoCamera;
use App\BusinessModules\Features\VideoMonitoring\Models\VideoCameraEvent;
use App\Models\OneCExchangeRun;
use App\Models\ReportTemplate;

final class VideoMonitoringAssistantMetadata
{
    public static function fields(): array
    {
        return [
            'video_camera' => ['id', 'project_id', 'name', 'zone', 'source_type', 'status', 'is_enabled', 'last_checked_at', 'last_online_at'],
            'video_camera_event' => ['id', 'project_id', 'camera_id', 'event_type', 'severity', 'occurred_at'],
            'one_c_exchange_run' => ['id', 'direction', 'status', 'total_count', 'created_count', 'updated_count', 'skipped_count', 'error_count', 'started_at', 'finished_at'],
            'report_template' => ['id', 'name', 'report_type', 'is_default'],
        ];
    }

    public static function domainGates(): array
    {
        return [
            'video_monitoring' => ['video-monitoring', ['video-monitoring.view']],
            'one_c_exchange' => ['one-c-basic-exchange', ['one_c_exchange.history.view']],
            'report_templates' => ['report-templates', ['report_templates.view']],
        ];
    }

    public static function entityDefinitions(): array
    {
        return [
            'video_camera' => ['video_monitoring', VideoCamera::class, 'video_monitoring'],
            'video_camera_event' => ['video_monitoring', VideoCameraEvent::class, 'video_monitoring'],
            'one_c_exchange_run' => ['one_c_exchange', OneCExchangeRun::class, 'one_c_exchange'],
            'report_template' => ['report_templates', ReportTemplate::class, 'report_templates'],
        ];
    }

    public static function entityPermissions(): array
    {
        return ['video_camera_event' => ['video-monitoring.events.view']];
    }

    public static function sourcePermissions(): array
    {
        return [
            'video_monitoring' => ['video-monitoring.view'],
            'one_c_exchange' => ['one_c_exchange.history.view'],
            'report_templates' => ['report_templates.view'],
        ];
    }

    public static function parentColumns(): array
    {
        return ['video_camera_event' => ['camera_id' => ['type' => 'video_camera', 'nullable' => false]]];
    }

    public static function safeSelectColumns(): array
    {
        $result = [];
        foreach (self::fields() as $type => $fields) {
            $result[$type] = array_values(array_unique([...$fields, 'organization_id', ...($type === 'video_camera_event' ? [] : ['updated_at'])]));
        }
        return $result;
    }

    public static function sourceClasses(): array
    {
        return [VideoMonitoringRagSource::class, OneCExchangeRagSource::class, ReportTemplateRagSource::class];
    }

    public static function observerDefinitions(): array
    {
        $result = [];
        foreach (self::entityDefinitions() as $type => [$source, $class]) {
            $result[$class] = [$source, $type];
        }
        return $result;
    }

    public static function navigationTemplates(): array
    {
        return ['video_camera' => '/projects/{project_id}', 'video_camera_event' => '/projects/{project_id}',
            'one_c_exchange_run' => '/integrations/1c', 'report_template' => ''];
    }

    public static function structuredFields(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::fields()))));
    }

    public static function entityLabels(): array
    {
        return ['video_camera' => 'Камера', 'video_camera_event' => 'Событие камеры', 'one_c_exchange_run' => 'Обмен с 1С', 'report_template' => 'Шаблон отчёта'];
    }

    public static function factFieldGroups(): array
    {
        return ['date' => ['last_checked_at', 'last_online_at', 'occurred_at', 'started_at', 'finished_at'],
            'status' => ['status'], 'quantity' => ['total_count', 'created_count', 'updated_count', 'skipped_count', 'error_count']];
    }

    public static function fieldLabels(): array
    {
        return ['zone' => 'Зона', 'source_type' => 'Тип камеры', 'is_enabled' => 'Включена', 'last_checked_at' => 'Последняя проверка',
            'last_online_at' => 'Последнее подключение', 'event_type' => 'Тип события', 'occurred_at' => 'Время события',
            'direction' => 'Направление', 'total_count' => 'Всего записей', 'created_count' => 'Создано', 'updated_count' => 'Обновлено',
            'skipped_count' => 'Пропущено', 'error_count' => 'Ошибок', 'started_at' => 'Начало', 'finished_at' => 'Завершение',
            'report_type' => 'Тип отчёта', 'is_default' => 'По умолчанию'];
    }

    public static function domainDefinitions(): array
    {
        $result = [];
        foreach (self::domainGates() as $domain => [$module, $permissions]) {
            $types = array_keys(array_filter(self::entityDefinitions(), static fn (array $entry): bool => $entry[2] === $domain));
            $fields = array_values(array_unique(array_merge(...array_map(static fn (string $type): array => self::fields()[$type], $types))));
            $entityType = ['type' => 'string', 'enum' => $types];
            $fieldList = ['type' => ['array', 'null'], 'items' => ['type' => 'string', 'enum' => $fields]];
            $id = ['type' => 'integer', 'minimum' => 1];
            $schema = static fn (array $properties): array => ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
            $schemas = [
                'search' => $schema(['entity_type' => $entityType, 'query' => ['type' => 'string', 'maxLength' => 200], 'project_id' => ['type' => ['integer', 'null'], 'minimum' => 1], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20], 'fields' => $fieldList]),
                'read' => $schema(['entity_type' => $entityType, 'id' => $id, 'fields' => $fieldList]),
                'navigation' => $schema(['entity_type' => $entityType, 'id' => $id]),
            ];
            $entityPermissions = $domain === 'video_monitoring' ? ['video_camera_event' => 'video-monitoring.events.view'] : [];
            $result[] = new AssistantDomainDefinition($domain, $module, $types[0], $permissions, $fields, $schemas,
                array_keys($schemas), self::navigationTemplates()[$types[0]], $domain, $types, [], $entityPermissions);
        }
        return $result;
    }
}
