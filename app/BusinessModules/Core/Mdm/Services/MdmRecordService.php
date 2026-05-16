<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Services;

use App\BusinessModules\Core\Mdm\Models\MdmRecord;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class MdmRecordService
{
    public function __construct(
        private readonly MdmEntityRegistry $registry,
        private readonly MdmQualityService $qualityService,
        private readonly MdmChangeLogService $changeLogService
    ) {
    }

    public function syncModel(Model $model, ?string $entityType = null, ?int $userId = null): MdmRecord
    {
        $entityType ??= $this->registry->inferEntityType($model);

        if ($entityType === null) {
            throw new InvalidArgumentException('Unknown MDM entity model: ' . $model::class);
        }

        $organizationId = (int) $model->getAttribute('organization_id');
        $entityId = (int) $model->getKey();
        $attributes = $model->getAttributes();
        $quality = $this->qualityService->evaluate($entityType, $attributes);

        $record = MdmRecord::query()->firstOrNew([
            'organization_id' => $organizationId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);

        $before = $record->exists ? $record->getAttributes() : null;
        $record->fill([
            'display_name' => $this->registry->displayName($model, $entityType),
            'normalized_key' => $quality['normalized_values']['normalized_key'] ?? null,
            'quality_score' => $quality['score'],
            'quality_issues' => $quality['issues'],
            'normalized_values' => $quality['normalized_values'],
            'status' => $record->status ?: 'active',
            'last_synced_at' => now(),
        ]);

        if ($record->exists && $record->isDirty()) {
            $record->version = ((int) $record->version) + 1;
        }

        $record->save();

        $this->changeLogService->log(
            $organizationId,
            $entityType,
            $entityId,
            $before === null ? 'synced_created' : 'synced_updated',
            $before,
            $record->getAttributes(),
            $userId,
            ['source' => 'mdm_sync'],
            $record
        );

        return $record;
    }

    public function syncOrganization(int $organizationId, ?string $entityType = null, ?int $userId = null): array
    {
        $entities = $entityType === null ? array_keys($this->registry->all()) : [$entityType];
        $synced = 0;

        foreach ($entities as $type) {
            $this->registry
                ->query($type, $organizationId)
                ->orderBy('id')
                ->chunkById(200, function ($models) use (&$synced, $type, $userId): void {
                    foreach ($models as $model) {
                        $this->syncModel($model, $type, $userId);
                        $synced++;
                    }
                });
        }

        return ['records_synced' => $synced];
    }

    public function archive(string $entityType, int $entityId, int $organizationId, ?int $userId, ?string $reason): MdmRecord
    {
        $record = MdmRecord::query()
            ->where('organization_id', $organizationId)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->firstOrFail();

        $before = $record->getAttributes();
        $record->fill([
            'status' => 'archived',
            'archived_at' => now(),
            'archived_by_user_id' => $userId,
            'archive_reason' => $reason,
            'version' => ((int) $record->version) + 1,
        ]);
        $record->save();

        $this->changeLogService->log(
            $organizationId,
            $entityType,
            $entityId,
            'archived',
            $before,
            $record->getAttributes(),
            $userId,
            ['reason' => $reason],
            $record
        );

        return $record;
    }

    public function summary(int $organizationId): array
    {
        return MdmRecord::query()
            ->selectRaw('entity_type, count(*) as total, avg(quality_score) as avg_quality')
            ->where('organization_id', $organizationId)
            ->groupBy('entity_type')
            ->orderBy('entity_type')
            ->get()
            ->map(static fn (MdmRecord $record): array => [
                'type' => $record->entity_type,
                'total' => (int) $record->getAttribute('total'),
                'avg_quality' => round((float) $record->getAttribute('avg_quality'), 2),
            ])
            ->values()
            ->all();
    }
}
