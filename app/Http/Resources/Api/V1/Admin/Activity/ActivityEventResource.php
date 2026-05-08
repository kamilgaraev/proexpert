<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Activity;

use App\Models\Activity\ActivityEvent;
use App\Services\Activity\ActivityEventPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

use function trans_message;

class ActivityEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ActivityEvent $event */
        $event = $this->resource;
        $presenter = app(ActivityEventPresenter::class);

        return [
            'id' => $event->id,
            'occurred_at' => $event->occurred_at?->toISOString(),
            'module' => $event->module,
            'event_type' => $event->event_type,
            'action' => $event->action,
            'result' => $event->result,
            'severity' => $event->severity,
            'title' => $event->title,
            'description' => $event->description,
            'actor' => [
                'id' => $event->actor_user_id,
                'name' => $event->actor_name ?: trans_message('activity.system_actor'),
                'email' => $event->actor_email,
                'type' => $event->actor_type,
            ],
            'subject' => [
                'type' => $event->subject_type,
                'id' => $event->subject_id,
                'label' => $event->subject_label,
            ],
            'project' => $event->project ? [
                'id' => $event->project->id,
                'name' => $event->project->name,
            ] : null,
            'target_user' => $event->targetUser ? [
                'id' => $event->targetUser->id,
                'name' => $event->targetUser->name,
            ] : null,
            'changes' => $presenter->changesForResource($event),
            'details' => $presenter->detailsForResource($event),
        ];
    }
}
