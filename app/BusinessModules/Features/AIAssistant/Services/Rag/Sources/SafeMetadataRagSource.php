<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\VideoMonitoringAssistantMetadata;
use Illuminate\Database\Eloquent\Builder;

abstract class SafeMetadataRagSource extends ModelDomainRagSource
{
    public function entities(): array
    {
        $result = [];
        foreach (VideoMonitoringAssistantMetadata::entityDefinitions() as $type => [$source, $class]) {
            if ($source === $this->sourceType()) {
                $result[$type] = ['model' => $class, 'fields' => VideoMonitoringAssistantMetadata::fields()[$type]];
            }
        }
        return $result;
    }

    protected function query(string $class, int $organizationId, ?int $projectId): Builder
    {
        $type = array_search($class, array_column(VideoMonitoringAssistantMetadata::entityDefinitions(), 1), true);
        $entries = array_keys(VideoMonitoringAssistantMetadata::entityDefinitions());
        $entityType = $type === false ? null : $entries[$type];
        $columns = $entityType === null ? ['id'] : VideoMonitoringAssistantMetadata::safeSelectColumns()[$entityType];
        $query = parent::query($class, $organizationId, $projectId)->select($columns);
        if ($entityType === 'video_camera_event') {
            $query->whereExists(static function ($camera) use ($organizationId): void {
                $camera->selectRaw('1')->from('video_cameras')->whereColumn('video_cameras.id', 'video_camera_events.camera_id')
                    ->where('video_cameras.organization_id', $organizationId)->whereNull('video_cameras.deleted_at')
                    ->whereRaw('video_cameras.project_id IS NOT DISTINCT FROM video_camera_events.project_id');
            });
        }
        return $query;
    }
}
