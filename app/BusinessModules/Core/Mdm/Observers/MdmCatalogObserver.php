<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Observers;

use App\BusinessModules\Core\Mdm\Models\MdmRecord;
use App\BusinessModules\Core\Mdm\Services\MdmEntityRegistry;
use App\BusinessModules\Core\Mdm\Services\MdmRecordService;
use Illuminate\Database\Eloquent\Model;

class MdmCatalogObserver
{
    private bool $syncing = false;

    public function saved(Model $model): void
    {
        if ($this->syncing) {
            return;
        }

        $entityType = app(MdmEntityRegistry::class)->inferEntityType($model);

        if ($entityType === null || $model->getAttribute('organization_id') === null) {
            return;
        }

        $this->syncing = true;
        try {
            app(MdmRecordService::class)->syncModel($model, $entityType);
        } finally {
            $this->syncing = false;
        }
    }

    public function deleted(Model $model): void
    {
        $entityType = app(MdmEntityRegistry::class)->inferEntityType($model);

        if ($entityType === null) {
            return;
        }

        MdmRecord::query()
            ->where('organization_id', $model->getAttribute('organization_id'))
            ->where('entity_type', $entityType)
            ->where('entity_id', $model->getKey())
            ->update([
                'status' => 'archived',
                'archived_at' => now(),
                'archive_reason' => 'catalog_record_deleted',
            ]);
    }

    public function restored(Model $model): void
    {
        $this->saved($model);
    }
}
