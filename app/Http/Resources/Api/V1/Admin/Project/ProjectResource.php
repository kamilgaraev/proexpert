<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Project;

use App\Http\Resources\Api\V1\Admin\User\ForemanUserResource;
use App\Models\Project;
use App\Services\Project\ProjectCustomerResolverService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if (!$this->resource instanceof Project) {
            return [];
        }

        $resolvedCustomer = app(ProjectCustomerResolverService::class)->resolve($this->resource);

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'address' => $this->resource->address,
            'description' => $this->resource->description,
            'customer' => $this->resource->customer,
            'designer' => $this->resource->designer,
            'budget_amount' => $this->resource->budget_amount,
            'site_area_m2' => $this->resource->site_area_m2,
            'contract_number' => $this->resource->contract_number,
            'status' => $this->resource->status,
            'start_date' => $this->resource->start_date?->toDateString(),
            'end_date' => $this->resource->end_date?->toDateString(),
            'is_archived' => (bool) $this->resource->is_archived,
            'is_onboarding_demo' => (bool) $this->resource->is_onboarding_demo,
            'additional_info' => $this->resource->additional_info,
            'external_code' => $this->resource->external_code,
            'cost_category_id' => $this->resource->cost_category_id,
            'accounting_data' => $this->resource->accounting_data,
            'use_in_accounting_reports' => (bool) $this->resource->use_in_accounting_reports,
            'organization_id' => $this->resource->organization_id,
            'resolved_customer' => [
                'id' => $resolvedCustomer['id'],
                'name' => $resolvedCustomer['name'],
                'source' => $resolvedCustomer['source'],
                'role' => $resolvedCustomer['role'],
                'is_fallback_owner' => $resolvedCustomer['is_fallback_owner'],
            ],
            'created_at' => $this->resource->created_at,
            'updated_at' => $this->resource->updated_at,
            'assigned_users' => ForemanUserResource::collection($this->whenLoaded('users')),
            'assigned_users_count' => $this->whenCounted('users', $this->resource->users_count),
        ];
    }
}
