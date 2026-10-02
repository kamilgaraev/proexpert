<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\VideoMonitoringAssistantMetadata;
use PHPUnit\Framework\TestCase;

final class VideoMonitoringAssistantMetadataTest extends TestCase
{
    public function test_fields_sql_projection_and_schemas_exclude_credentials_and_exchange_payloads(): void
    {
        $forbidden = ['source_url', 'playback_url', 'username', 'password', 'host', 'port', 'stream_path', 'settings',
            'status_message', 'message', 'payload', 'errors', 'summary', 'columns_config'];
        foreach (VideoMonitoringAssistantMetadata::safeSelectColumns() as $columns) {
            self::assertSame([], array_intersect($forbidden, $columns));
            self::assertContains('id', $columns);
            self::assertContains('organization_id', $columns);
        }
        foreach (VideoMonitoringAssistantMetadata::domainDefinitions() as $definition) {
            self::assertSame([], array_intersect($forbidden, $definition->fields));
            self::assertSame(['search', 'read', 'navigation'], $definition->operations);
            self::assertFalse($definition->schemas['read']['additionalProperties']);
        }
        self::assertSame(['video-monitoring.events.view'], VideoMonitoringAssistantMetadata::entityPermissions()['video_camera_event']);
        self::assertFalse(VideoMonitoringAssistantMetadata::parentColumns()['video_camera_event']['camera_id']['nullable']);
    }
}
