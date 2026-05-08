<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Activity;

use App\Models\Activity\ActivityEvent;
use Illuminate\Http\Request;

class ActivityEventDetailResource extends ActivityEventResource
{
    public function toArray(Request $request): array
    {
        /** @var ActivityEvent $event */
        $event = $this->resource;
        $data = parent::toArray($request);

        $data['technical'] = [
            'correlation_id' => $event->correlation_id,
            'interface' => $event->interface,
            'ip_address' => $event->ip_address,
            'user_agent' => $event->user_agent,
        ];

        return $data;
    }
}
