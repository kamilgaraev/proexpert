<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Project;

use App\Http\Resources\Api\V1\Admin\User\ProjectTeamMemberResource;
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

        $resolver = app(ProjectCustomerResolverService::class);
        $resolvedCustomer = $resolver->resolveLegalCustomer($this->resource);
        $resolvedGeneralContractor = $resolver->resolveGeneralContractor($this->resource);
        $resolvedDesigner = $resolver->resolveDesigner($this->resource);
        $resolvedSupervision = $resolver->resolveConstructionSupervision($this->resource);

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'address' => $this->resource->address,
            'description' => $this->resource->description,
            'customer' => $resolvedCustomer['name'] ?? $this->resource->customer,
            'customer_counterparty_id' => $this->resource->customer_counterparty_id,
            'contracting_scheme' => $this->resource->contracting_scheme ?? 'general_contractor',
            'customer_counterparty' => $this->whenLoaded('customerCounterparty', fn () => [
                'id' => $this->resource->customerCounterparty?->id,
                'name' => $this->resource->customerCounterparty?->name,
                'legal_name' => $this->resource->customerCounterparty?->legal_name,
                'inn' => $this->resource->customerCounterparty?->inn,
                'kpp' => $this->resource->customerCounterparty?->kpp,
            ]),
            'designer' => $resolvedDesigner['name'] ?? $this->resource->designer,
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
            'resolved_customer' => $resolvedCustomer,
            'resolved_general_contractor' => $resolvedGeneralContractor,
            'resolved_designer' => $resolvedDesigner,
            'resolved_construction_supervision' => $resolvedSupervision,
            'created_at' => $this->resource->created_at,
            'updated_at' => $this->resource->updated_at,
            'assigned_users' => ProjectTeamMemberResource::collection($this->whenLoaded('users')),
            'assigned_users_count' => $this->whenCounted('users', $this->resource->users_count),
        ];
    }
}
