<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Tenders\Services;

use App\BusinessModules\Features\Tenders\Models\TenderTimelineEvent;

final class TenderTimelineService
{
    public function record(
        int $organizationId,
        string $tenderId,
        string $eventType,
        string $summary,
        ?int $actorUserId,
        array $metadata = []
    ): TenderTimelineEvent {
        return TenderTimelineEvent::query()->create([
            'organization_id' => $organizationId,
            'tender_id' => $tenderId,
            'actor_user_id' => $actorUserId,
            'event_type' => $eventType,
            'summary' => $summary,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
