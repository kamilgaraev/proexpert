<?php

namespace App\Http\Resources\Api\V1\Admin\Contract;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Api\V1\Admin\Project\ProjectMiniResource;
use App\Http\Resources\Api\V1\Admin\Contractor\ContractorMiniResource;
use App\Http\Resources\Api\V1\Admin\Contract\PerformanceAct\ContractPerformanceActResource;
use App\Http\Resources\Api\V1\Admin\Contract\Payment\ContractPaymentResource;
use App\Http\Resources\Api\V1\Admin\Contract\Agreement\SupplementaryAgreementResource;
use App\Http\Resources\Api\V1\Admin\Contract\Specification\SpecificationResource;

class ContractResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Работаем только с загруженными связями, НЕ используем accessors
        $confirmedWorks = $this->whenLoaded('completedWorks', function() {
            return $this->completedWorks->where('status', 'confirmed');
        }, collect());
        
        $completedWorksAmount = $confirmedWorks instanceof \Illuminate\Support\Collection 
            ? $confirmedWorks->sum('total_amount') : 0;

        // Рассчитываем итоговую сумму контракта с учетом допсоглашений
        $agreementsDelta = $this->whenLoaded('agreements', function() {
            return $this->agreements->sum('change_amount') ?? 0;
        }, 0);
        
        $baseTotalAmount = (float) ($this->total_amount ?? 0);
        $effectiveTotalAmount = $baseTotalAmount + $agreementsDelta;

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
            // type удалён
            'subject' => $this->subject,
            'work_type_category' => $this->work_type_category?->value,
            'work_type_category_label' => $this->work_type_category?->name,
            'payment_terms' => $this->payment_terms,
            'total_amount' => $effectiveTotalAmount, // С учетом допсоглашений
            'gp_percentage' => (float) ($this->gp_percentage ?? 0),
            'gp_amount' => (float) ($this->gp_amount ?? 0),
            'total_amount_with_gp' => $effectiveTotalAmount + (float) ($this->gp_amount ?? 0), // С учетом допсоглашений
            'planned_advance_amount' => (float) ($this->planned_advance_amount ?? 0),
            'actual_advance_amount' => (float) ($this->actual_advance_amount ?? 0),
            'remaining_advance_amount' => (float) ($this->remaining_advance_amount ?? 0),
            'is_advance_fully_paid' => $this->is_advance_fully_paid ?? false,
            'advance_payment_percentage' => (float) ($this->advance_payment_percentage ?? 0),
            'status' => $this->status->value, // Enum
            'status_label' => $this->status->name, // Для отображения
            'start_date' => $this->start_date, // Формат Y-m-d
            'end_date' => $this->end_date, // Формат Y-m-d
            'notes' => $this->notes,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),

            // Аналитические данные из загруженных связей (НЕ accessors!)
            'completed_works_amount' => (float) ($completedWorksAmount ?? 0),
            'total_performed_amount' => (float) $this->whenLoaded('performanceActs', function() {
                $totalAmount = 0;
                foreach ($this->performanceActs->where('is_approved', true) as $act) {
                    // Если у акта есть связанные работы - считаем по ним
                    if ($act->relationLoaded('completedWorks') && $act->completedWorks->count() > 0) {
                        $totalAmount += $act->completedWorks->sum('pivot.included_amount');
                    } else {
                        // Если работы не связаны - используем старое поле amount (для совместимости)
                        $totalAmount += $act->amount ?? 0;
                    }
                }
                return $totalAmount;
            }, 0),
            'remaining_amount' => (float) max(0, $effectiveTotalAmount - ($this->whenLoaded('performanceActs', function() {
                $totalAmount = 0;
                foreach ($this->performanceActs->where('is_approved', true) as $act) {
                    if ($act->relationLoaded('completedWorks') && $act->completedWorks->count() > 0) {
                        $totalAmount += $act->completedWorks->sum('pivot.included_amount');
                    } else {
                        $totalAmount += $act->amount ?? 0;
                    }
                }
                return $totalAmount;
            }, 0))),
            'completion_percentage' => $effectiveTotalAmount > 0 ? 
                round((($this->whenLoaded('performanceActs', function() {
                    $totalAmount = 0;
                    foreach ($this->performanceActs->where('is_approved', true) as $act) {
                        if ($act->relationLoaded('completedWorks') && $act->completedWorks->count() > 0) {
                            $totalAmount += $act->completedWorks->sum('pivot.included_amount');
                        } else {
                            $totalAmount += $act->amount ?? 0;
                        }
                    }
                    return $totalAmount;
                }, 0)) / $effectiveTotalAmount) * 100, 2) : 0.0,
            'total_paid_amount' => (float) $this->whenLoaded('payments', function() {
                return $this->payments->sum('amount') ?? 0;
            }, 0),
            'is_nearing_limit' => ($this->whenLoaded('performanceActs', function() {
                $totalAmount = 0;
                foreach ($this->performanceActs->where('is_approved', true) as $act) {
                    if ($act->relationLoaded('completedWorks') && $act->completedWorks->count() > 0) {
                        $totalAmount += $act->completedWorks->sum('pivot.included_amount');
                    } else {
                        $totalAmount += $act->amount ?? 0;
                    }
                }
                return $totalAmount;
            }, 0)) >= ($effectiveTotalAmount * 0.9),
            'can_add_work' => !in_array($this->status->value, ['completed', 'terminated']),

            // Связанные данные (если загружены)
            'agreements' => SupplementaryAgreementResource::collection($this->whenLoaded('agreements')),
            'specifications' => SpecificationResource::collection($this->whenLoaded('specifications')),
            'performance_acts' => ContractPerformanceActResource::collection($this->whenLoaded('performanceActs')), 
            'payments' => ContractPaymentResource::collection($this->whenLoaded('payments')),
            
            // === АГРЕГИРОВАННЫЕ ДАННЫЕ ===
            // Заказчик (организация-владелец проекта)
            'customer' => $this->when(
                $this->relationLoaded('project') && $this->project?->relationLoaded('organization'),
                function() {
                    return [
                        'id' => $this->project->organization->id,
                        'name' => $this->project->organization->name,
                        'inn' => $this->project->organization->inn,
                        'kpp' => $this->project->organization->kpp,
                        'legal_address' => $this->project->organization->legal_address,
                        'contact_email' => $this->project->organization->contact_email,
                        'contact_phone' => $this->project->organization->contact_phone,
                    ];
                }
            ),
            
            // Расширенные данные подрядчика
            'contractor_details' => $this->when(
                $this->relationLoaded('contractor'),
                function() {
                    return [
                        'id' => $this->contractor->id,
                        'name' => $this->contractor->name,
                        'inn' => $this->contractor->inn,
                        'kpp' => $this->contractor->kpp,
                        'legal_address' => $this->contractor->legal_address,
                        'email' => $this->contractor->email,
                        'phone' => $this->contractor->phone,
                        'director_name' => $this->contractor->director_name,
                        'contact_person' => $this->contractor->contact_person,
                        'contact_person_phone' => $this->contractor->contact_person_phone,
                    ];
                }
            ),
            
            // Участники проекта (из project_organization)
            'project_participants' => $this->when(
                $this->relationLoaded('project') && $this->project?->relationLoaded('organizations'),
                function() {
                    return $this->project->organizations->map(function($org) {
                        return [
                            'organization_id' => $org->id,
                            'organization_name' => $org->name,
                            'role' => $org->pivot->role_new ?? $org->pivot->role,
                            'role_label' => match($org->pivot->role_new ?? $org->pivot->role) {
                                'owner' => 'Заказчик/Генподрядчик',
                                'general_contractor' => 'Генподрядчик',
                                'contractor' => 'Подрядчик',
                                'subcontractor' => 'Субподрядчик',
                                'supplier' => 'Поставщик',
                                'supervisor' => 'Технический надзор',
                                default => 'Участник'
                            },
                            'is_active' => $org->pivot->is_active,
                            'invited_at' => $org->pivot->invited_at,
                            'accepted_at' => $org->pivot->accepted_at,
                        ];
                    });
                }
            ),
        ];
    }
} 