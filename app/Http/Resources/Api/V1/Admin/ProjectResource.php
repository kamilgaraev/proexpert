<?php

namespace App\Http\Resources\Api\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
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
            'address' => $this->address,
            'description' => $this->description,
            'customer' => $this->customer,
            'designer' => $this->designer,
            'budgetAmount' => $this->budget_amount,
            'siteAreaM2' => $this->site_area_m2,
            'contractNumber' => $this->contract_number,
            'status' => $this->status,
            'startDate' => $this->start_date ? $this->start_date->format('Y-m-d') : null,
            'endDate' => $this->end_date ? $this->end_date->format('Y-m-d') : null,
            'isArchived' => $this->is_archived,
            'organizationId' => $this->organization_id,
            'createdAt' => $this->created_at->format('Y-m-d H:i:s'),
            'updatedAt' => $this->updated_at->format('Y-m-d H:i:s'),
            // Можно добавить связанные данные при необходимости
            // 'manager' => new UserResource($this->whenLoaded('manager')),
            // 'foremen' => UserResource::collection($this->whenLoaded('users')),
        ];
    }
} 