<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\DTOs\Activity\ActivityEventData;
use App\Models\Activity\ActivityEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ActivityEventRecorder
{
    public function __construct(
        private readonly ActivityEventRedactor $redactor,
        private readonly ActivityEventPresenter $presenter,
    ) {}

    public function record(ActivityEventData $data): ?ActivityEvent
    {
        try {
            $payload = $data->toArray();
            $presentation = $this->presenter->presentForData($data);

            $payload['title'] = $presentation['title'];
            $payload['description'] = $presentation['description'];
            $payload['changes'] = $this->redactor->redact($payload['changes'] ?? []);
            $payload['context'] = $this->redactor->redact($payload['context'] ?? []);

            return ActivityEvent::query()->create($payload);
        } catch (Throwable $e) {
            Log::error('activity.record_failed', [
                'organization_id' => $data->organizationId,
                'actor_user_id' => $data->actorUserId,
                'event_type' => $data->eventType,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
