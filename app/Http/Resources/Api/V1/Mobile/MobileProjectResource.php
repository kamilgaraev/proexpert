<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Mobile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MobileProjectResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->address ?? 'Адрес не указан',
            'status' => $this->status,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'customer_name' => $this->customer ?? $this->customer_organization,
            // Добавляем роль пользователя в проекте, если она есть в pivot
            'my_role' => $this->pivot?->role ?? null,
        ];
    }
}
