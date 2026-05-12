<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserAuthSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_name' => $this->device_name,
            'ip_address' => $this->ip_address,
            'ip_country' => $this->ip_country,
            'ip_city' => $this->ip_city,
            'risk_score' => $this->risk_score,
            'risk_flags' => $this->risk_flags ?? [],
            'status' => $this->status?->value ?? $this->status,
            'is_trusted' => (bool) $this->is_trusted,
            'is_current' => $request->attributes->get('auth_session')?->id === $this->id,
            'first_seen_at' => $this->first_seen_at?->toIso8601String(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
        ];
    }
}
