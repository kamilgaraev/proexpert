<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Services;

use App\BusinessModules\Features\Crm\Models\CrmTimelineEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class CrmTimelineService
{
    public function record(
        int $organizationId,
        string $entityType,
        string $entityId,
        string $eventType,
        string $summary,
        ?int $actorUserId = null,
        array $metadata = []
    ): CrmTimelineEvent {
        return CrmTimelineEvent::query()->create([
            'organization_id' => $organizationId,
            'actor_user_id' => $actorUserId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'event_type' => $eventType,
            'summary' => $summary,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    public function paginate(
        int $organizationId,
        string $entityType,
        string $entityId,
        int $perPage = 20
    ): LengthAwarePaginator {
        return CrmTimelineEvent::query()
            ->forOrganization($organizationId)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }
}
