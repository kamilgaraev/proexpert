<?php

namespace App\Domain\Authorization\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomRoleResource extends JsonResource
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
            'organization_id' => $this->organization_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'system_permissions' => $this->system_permissions ?? [],
            'module_permissions' => $this->module_permissions ?? [],
            'interface_access' => $this->interface_access ?? [],
            'conditions' => $this->when($this->conditions, $this->conditions),
            'is_active' => $this->is_active,
            'created_by' => [
                'id' => $this->createdBy?->id,
                'name' => $this->createdBy?->name,
                'email' => $this->createdBy?->email,
            ],
            'assignments_count' => $this->whenCounted('assignments'),
            'active_assignments_count' => $this->when(
                $this->relationLoaded('assignments'),
                function () {
                    return $this->assignments->where('is_active', true)->count();
                }
            ),
            'assignments' => $this->when(
                $this->relationLoaded('assignments'),
                function () {
                    return $this->assignments->map(function ($assignment) {
                        return [
                            'id' => $assignment->id,
                            'user' => [
                                'id' => $assignment->user->id,
                                'name' => $assignment->user->name,
                                'email' => $assignment->user->email,
                            ],
                            'context' => [
                                'id' => $assignment->context->id,
                                'type' => $assignment->context->type,
                                'resource_id' => $assignment->context->resource_id,
                            ],
                            'is_active' => $assignment->is_active,
                            'assigned_at' => $assignment->created_at,
                            'expires_at' => $assignment->expires_at,
                        ];
                    });
                }
            ),
            'permissions_summary' => [
                'system_permissions_count' => count($this->system_permissions ?? []),
                'module_permissions_count' => array_sum(array_map('count', $this->module_permissions ?? [])),
                'has_wildcard' => in_array('*', $this->system_permissions ?? []),
                'interfaces' => $this->interface_access ?? [],
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * Get additional data that should be returned with the resource array.
     *
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'can_edit' => $request->user()?->can('update', $this->resource),
                'can_delete' => $request->user()?->can('delete', $this->resource),
                'can_assign' => $request->user()?->can('assign', $this->resource),
            ],
        ];
    }
}
