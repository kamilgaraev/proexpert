<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Services;

use App\BusinessModules\Core\Mdm\Models\MdmChangeLog;
use App\BusinessModules\Core\Mdm\Models\MdmRecord;

class MdmChangeLogService
{
    public function log(
        int $organizationId,
        string $entityType,
        int $entityId,
        string $action,
        ?array $before = null,
        ?array $after = null,
        ?int $userId = null,
        ?array $metadata = null,
        ?MdmRecord $record = null
    ): MdmChangeLog {
        return MdmChangeLog::create([
            'organization_id' => $organizationId,
            'mdm_record_id' => $record?->id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'before_values' => $before,
            'after_values' => $after,
            'changed_by_user_id' => $userId,
            'metadata' => $metadata,
        ]);
    }
}
