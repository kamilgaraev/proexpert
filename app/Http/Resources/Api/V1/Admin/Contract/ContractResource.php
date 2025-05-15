<?php

namespace App\Http\Resources\Api\V1\Admin\Contract;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Api\V1\Admin\Project\ProjectMiniResource;
use App\Http\Resources\Api\V1\Admin\Contractor\ContractorMiniResource;

class ContractResource extends JsonResource
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
            'project_id' => $this->project_id,
            'project' => new ProjectMiniResource($this->whenLoaded('project')),
            'contractor_id' => $this->contractor_id,
            'contractor' => new ContractorMiniResource($this->whenLoaded('contractor')),
            'parent_contract_id' => $this->parent_contract_id,
            'parent_contract' => new ContractMiniResource($this->whenLoaded('parentContract')),
            'number' => $this->number,
            'date' => $this->date, // Предполагается, что в модели кастуется в нужный формат (Y-m-d)
            'type' => $this->type->value, // Enum
            'type_label' => $this->type->name, // Для отображения
            'subject' => $this->subject,
            'work_type_category' => $this->work_type_category?->value,
            'work_type_category_label' => $this->work_type_category?->name,
            'payment_terms' => $this->payment_terms,
            'total_amount' => (float) $this->total_amount,
            'gp_percentage' => (float) $this->gp_percentage,
            'gp_amount' => (float) $this->gp_amount, // Accessor
            'planned_advance_amount' => (float) $this->planned_advance_amount,
            'status' => $this->status->value, // Enum
            'status_label' => $this->status->name, // Для отображения
            'start_date' => $this->start_date, // Формат Y-m-d
            'end_date' => $this->end_date, // Формат Y-m-d
            'notes' => $this->notes,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),

            // Суммарные данные (можно вычислять в сервисе и добавлять к ресурсу, если часто нужны)
            // 'total_performed_amount' => $this->when(isset($this->total_performed_amount), (float) $this->total_performed_amount),
            // 'total_paid_amount' => $this->when(isset($this->total_paid_amount), (float) $this->total_paid_amount),

            // Связанные данные (если загружены)
            'child_contracts' => ContractMiniResource::collection($this->whenLoaded('childContracts')),
            // 'performance_acts' => PerformanceActResource::collection($this->whenLoaded('performanceActs')), 
            // 'payments' => PaymentResource::collection($this->whenLoaded('payments')),
        ];
    }
} 