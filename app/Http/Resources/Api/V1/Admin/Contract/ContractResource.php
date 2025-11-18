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
        // Если контракт использует Event Sourcing, считаем из активных событий
        // Иначе используем старый способ (sum change_amount)
        if ($this->usesEventSourcing()) {
            try {
                $stateEventService = app(\App\Services\Contract\ContractStateEventService::class);
                $currentState = $stateEventService->getCurrentState($this->resource);
                $calculatedTotalAmount = $currentState['total_amount'] ?? 0;
                
                // Получаем все события и фильтруем только активные
                $allEvents = $stateEventService->getTimeline($this->resource);
                $activeEventsOnly = $allEvents->filter(fn($e) => $e->isActive())->values();
                
                // НАХОДИМ ПЕРВОЕ СОБЫТИЕ ОТ ДС (для определения базовой суммы)
                // События от ДС: triggered_by_type === SupplementaryAgreement::class
                // Ищем по created_at (события упорядочены по времени создания)
                $firstAgreementEvent = $activeEventsOnly
                    ->where('triggered_by_type', \App\Models\SupplementaryAgreement::class)
                    ->sortBy('created_at')
                    ->first();
                
                // БАЗОВАЯ СУММА = сумма всех активных событий ДО первого события от ДС
                // Включаем: CREATED, AMENDED не от ДС
                // Исключаем: PAYMENT_CREATED, события от ДС
                if ($firstAgreementEvent) {
                    // Получаем время первого события от ДС
                    $firstAgreementTime = $firstAgreementEvent->created_at;
                    
                    // Суммируем все события, созданные ДО первого события от ДС
                    $baseEvents = $activeEventsOnly->filter(function($event) use ($firstAgreementTime) {
                        // Исключаем события от ДС
                        if ($event->triggered_by_type === \App\Models\SupplementaryAgreement::class) {
                            return false;
                        }
                        
                        // Исключаем платежи
                        if ($event->event_type === \App\Enums\Contract\ContractStateEventTypeEnum::PAYMENT_CREATED) {
                            return false;
                        }
                        
                        // Включаем только события, созданные до первого события от ДС
                        return $event->created_at < $firstAgreementTime;
                    });
                    
                    $baseTotalAmount = $baseEvents->sum('amount_delta');
                } else {
                    // Если нет событий от ДС - базовая сумма = сумма всех событий кроме платежей и событий от ДС
                    $baseTotalAmount = $activeEventsOnly
                        ->filter(function($event) {
                            // Исключаем платежи и события от ДС
                            if ($event->event_type === \App\Enums\Contract\ContractStateEventTypeEnum::PAYMENT_CREATED) {
                                return false;
                            }
                            if ($event->triggered_by_type === \App\Models\SupplementaryAgreement::class) {
                                return false;
                            }
                            return true;
                        })
                        ->sum('amount_delta');
                }
                
                // ДЕЛЬТА ДС = сумма только РЕАЛЬНЫХ активных событий от ДС
                // Включаем: AMENDED от ДС (НЕ компенсирующие), SUPPL_AGREEMENT_CREATED от ДС
                // Исключаем: 
                //   - CREATED (базовая сумма)
                //   - PAYMENT_CREATED (платежи)
                //   - SUPERSEDED (аннулирование)
                //   - AMENDED не от ДС (изменения базовой суммы)
                //   - Компенсирующие AMENDED (is_compensating === true)
                $agreementsDelta = $activeEventsOnly
                    ->filter(function($event) {
                        // Исключаем события, не связанные с ДС
                        if (in_array($event->event_type, [
                            \App\Enums\Contract\ContractStateEventTypeEnum::CREATED,
                            \App\Enums\Contract\ContractStateEventTypeEnum::PAYMENT_CREATED,
                            \App\Enums\Contract\ContractStateEventTypeEnum::SUPERSEDED,
                        ])) {
                            return false;
                        }
                        
                        // Для AMENDED - проверяем, что это от ДС и НЕ компенсирующее
                        if ($event->event_type === \App\Enums\Contract\ContractStateEventTypeEnum::AMENDED) {
                            // Должно быть от ДС
                            if ($event->triggered_by_type !== \App\Models\SupplementaryAgreement::class) {
                                return false;
                            }
                            
                            // Исключаем компенсирующие события
                            $metadata = $event->metadata ?? [];
                            if (isset($metadata['is_compensating']) && $metadata['is_compensating'] === true) {
                                return false;
                            }
                            
                            return true;
                        }
                        
                        // Для SUPPL_AGREEMENT_CREATED - учитываем только если от ДС
                        if ($event->event_type === \App\Enums\Contract\ContractStateEventTypeEnum::SUPPLEMENTARY_AGREEMENT_CREATED) {
                            return $event->triggered_by_type === \App\Models\SupplementaryAgreement::class;
                        }
                        
                        return false;
                    })
                    ->sum('amount_delta');
                
                // Правильный расчет: базовая сумма + изменения от допсоглашений
                $effectiveTotalAmount = $baseTotalAmount + $agreementsDelta;
            } catch (\Exception $e) {
                // Fallback на старый способ если Event Sourcing не работает
                $agreementsDelta = $this->whenLoaded('agreements', function() {
                    return $this->agreements->sum('change_amount') ?? 0;
                }, 0);
                $baseTotalAmount = (float) ($this->total_amount ?? 0);
                $effectiveTotalAmount = $baseTotalAmount + $agreementsDelta;
            }
        } else {
            // Старый способ для legacy контрактов
            $agreementsDelta = $this->whenLoaded('agreements', function() {
                return $this->agreements->sum('change_amount') ?? 0;
            }, 0);
            $baseTotalAmount = (float) ($this->total_amount ?? 0);
            $effectiveTotalAmount = $baseTotalAmount + $agreementsDelta;
        }

        // Получаем base_amount из модели (если есть) или используем total_amount для legacy
        $modelBaseAmount = (float) ($this->base_amount ?? $this->total_amount ?? 0);
        
        // Рассчитываем сумму ГП от base_amount (НЕ от effectiveTotalAmount!)
        $gpAmount = $this->gp_amount; // Используем accessor модели для правильного расчета
        
        // total_amount (итоговая сумма) = base_amount + gp_amount
        $totalAmountCalculated = round($modelBaseAmount + $gpAmount, 2);

        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'project_id' => $this->project_id,
            'project' => new ProjectMiniResource($this->whenLoaded('project')),
            'contractor_id' => $this->contractor_id,
            'contractor' => new ContractorMiniResource($this->whenLoaded('contractor')),
            'number' => $this->number,
            'date' => $this->date,
            'subject' => $this->subject,
            'work_type_category' => $this->work_type_category?->value,
            'work_type_category_label' => $this->work_type_category?->label(),
            'payment_terms' => $this->payment_terms,
            'base_amount' => $modelBaseAmount,
            'total_amount' => $totalAmountCalculated,
            'gp_percentage' => (float) ($this->gp_percentage ?? 0),
            'gp_amount' => (float) $gpAmount,
            'total_amount_with_gp' => $totalAmountCalculated,
            'planned_advance_amount' => (float) ($this->planned_advance_amount ?? 0),
            'actual_advance_amount' => (float) ($this->actual_advance_amount ?? 0),
            'remaining_advance_amount' => (float) ($this->remaining_advance_amount ?? 0),
            'is_advance_fully_paid' => $this->is_advance_fully_paid ?? false,
            'advance_payment_percentage' => (float) ($this->advance_payment_percentage ?? 0),
            'status' => $this->status->value,
            'status_label' => $this->status->name,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
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
            'remaining_amount' => (float) max(0, $totalAmountWithGp - ($this->whenLoaded('performanceActs', function() {
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
            'completion_percentage' => $totalAmountWithGp > 0 ? 
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
                }, 0)) / $totalAmountWithGp) * 100, 2) : 0.0,
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
            }, 0)) >= ($totalAmountWithGp * 0.9),
            'can_add_work' => !in_array($this->status->value, ['completed', 'terminated']),

            // Связанные данные (если загружены)
            'agreements' => SupplementaryAgreementResource::collection($this->whenLoaded('agreements')),
            'specifications' => SpecificationResource::collection($this->whenLoaded('specifications')),
            'performance_acts' => ContractPerformanceActResource::collection($this->whenLoaded('performanceActs')), 
            'payments' => ContractPaymentResource::collection($this->whenLoaded('payments')),
            
            // === ФИНАНСОВАЯ СВОДКА ===
            'financial_summary' => [
                // Базовая сумма контракта (без Д/С)
                'base_amount' => $baseTotalAmount,
                
                // Дополнительные соглашения
                'agreements_total_change' => $agreementsDelta,
                'agreements_count' => $this->whenLoaded('agreements', fn() => $this->agreements->count(), 0),
                
                // Итоговая сумма с учетом всех Д/С
                'total_amount_with_agreements' => $effectiveTotalAmount,
                
                // Спецификации
                'specifications_total' => (float) $this->whenLoaded('specifications', function() {
                    return $this->specifications->sum('total_amount') ?? 0;
                }, 0),
                'specifications_count' => $this->whenLoaded('specifications', fn() => $this->specifications->count(), 0),
                
                // Акты выполненных работ
                'acts_total_amount' => (float) $this->whenLoaded('performanceActs', function() {
                    $totalAmount = 0;
                    foreach ($this->performanceActs->where('is_approved', true) as $act) {
                        if ($act->relationLoaded('completedWorks') && $act->completedWorks->count() > 0) {
                            $totalAmount += $act->completedWorks->sum('pivot.included_amount');
                        } else {
                            $totalAmount += $act->amount ?? 0;
                        }
                    }
                    return $totalAmount;
                }, 0),
                'acts_count' => $this->whenLoaded('performanceActs', fn() => $this->performanceActs->where('is_approved', true)->count(), 0),
                'acts_pending_count' => $this->whenLoaded('performanceActs', fn() => $this->performanceActs->where('is_approved', false)->count(), 0),
                
                // Платежи
                'payments_total_amount' => (float) $this->whenLoaded('payments', fn() => $this->payments->sum('amount') ?? 0, 0),
                'payments_count' => $this->whenLoaded('payments', fn() => $this->payments->count(), 0),
                'advance_payments' => (float) $this->whenLoaded('payments', function() {
                    return $this->payments->where('payment_type', 'advance')->sum('amount') ?? 0;
                }, 0),
                'regular_payments' => (float) $this->whenLoaded('payments', function() {
                    return $this->payments->where('payment_type', 'regular')->sum('amount') ?? 0;
                }, 0),
                
                // Расчетные показатели
                'remaining_to_perform' => max(0, $totalAmountWithGp - (float) $this->whenLoaded('performanceActs', function() {
                    $totalAmount = 0;
                    foreach ($this->performanceActs->where('is_approved', true) as $act) {
                        if ($act->relationLoaded('completedWorks') && $act->completedWorks->count() > 0) {
                            $totalAmount += $act->completedWorks->sum('pivot.included_amount');
                        } else {
                            $totalAmount += $act->amount ?? 0;
                        }
                    }
                    return $totalAmount;
                }, 0)),
                
                'remaining_to_pay' => max(0, (float) $this->whenLoaded('performanceActs', function() {
                    $totalAmount = 0;
                    foreach ($this->performanceActs->where('is_approved', true) as $act) {
                        if ($act->relationLoaded('completedWorks') && $act->completedWorks->count() > 0) {
                            $totalAmount += $act->completedWorks->sum('pivot.included_amount');
                        } else {
                            $totalAmount += $act->amount ?? 0;
                        }
                    }
                    return $totalAmount;
                }, 0) - (float) $this->whenLoaded('payments', fn() => $this->payments->sum('amount') ?? 0, 0)),
                
                // Проценты выполнения
                'performance_percentage' => $totalAmountWithGp > 0 ? 
                    round((((float) $this->whenLoaded('performanceActs', function() {
                        $totalAmount = 0;
                        foreach ($this->performanceActs->where('is_approved', true) as $act) {
                            if ($act->relationLoaded('completedWorks') && $act->completedWorks->count() > 0) {
                                $totalAmount += $act->completedWorks->sum('pivot.included_amount');
                            } else {
                                $totalAmount += $act->amount ?? 0;
                            }
                        }
                        return $totalAmount;
                    }, 0)) / $totalAmountWithGp) * 100, 2) : 0.0,
                    
                'payment_percentage' => $totalAmountWithGp > 0 ? 
                    round(((float) $this->whenLoaded('payments', fn() => $this->payments->sum('amount') ?? 0, 0) / $totalAmountWithGp) * 100, 2) : 0.0,
                
                // Дополнительные метрики
                'payment_vs_performance_diff' => (float) $this->whenLoaded('payments', function() {
                    $totalPaid = $this->payments->sum('amount') ?? 0;
                    $totalPerformed = 0;
                    if ($this->relationLoaded('performanceActs')) {
                        foreach ($this->performanceActs->where('is_approved', true) as $act) {
                            if ($act->relationLoaded('completedWorks') && $act->completedWorks->count() > 0) {
                                $totalPerformed += $act->completedWorks->sum('pivot.included_amount');
                            } else {
                                $totalPerformed += $act->amount ?? 0;
                            }
                        }
                    }
                    return $totalPaid - $totalPerformed; // Положительное = переплата, отрицательное = долг
                }, 0),
                
                // Аванс
                'advance_status' => [
                    'planned' => (float) ($this->planned_advance_amount ?? 0),
                    'actual' => (float) ($this->actual_advance_amount ?? 0),
                    'remaining' => (float) ($this->remaining_advance_amount ?? 0),
                    'percentage_paid' => (float) ($this->advance_payment_percentage ?? 0),
                    'is_fully_paid' => $this->is_advance_fully_paid ?? false,
                ],
                
                // Генподрядный процент (если применяется)
                'gp_info' => $this->gp_percentage != 0 || $this->gp_coefficient != 0 ? [
                    'percentage' => (float) ($this->gp_percentage ?? 0),
                    'coefficient' => (float) ($this->gp_coefficient ?? 0),
                    'calculation_type' => $this->gp_calculation_type?->value,
                    'gp_amount' => (float) $gpAmount,
                    'total_with_gp' => $totalAmountCalculated,
                ] : null,
                
                // Субподряд
                'subcontract_amount' => (float) ($this->subcontract_amount ?? 0),
                'has_subcontract' => (float) ($this->subcontract_amount ?? 0) > 0,
                
                // Временные метрики
                'days_info' => [
                    'duration_days' => $this->start_date && $this->end_date 
                        ? $this->start_date->diffInDays($this->end_date) 
                        : null,
                    'days_passed' => $this->start_date 
                        ? max(0, $this->start_date->diffInDays(now())) 
                        : null,
                    'days_remaining' => $this->end_date && $this->end_date->isFuture()
                        ? now()->diffInDays($this->end_date)
                        : 0,
                    'is_overdue' => $this->is_overdue ?? false,
                ],
                
                // Эффективность
                'efficiency_metrics' => [
                    // Средняя сумма акта
                    'avg_act_amount' => $this->whenLoaded('performanceActs', function() {
                        $approvedActs = $this->performanceActs->where('is_approved', true);
                        return $approvedActs->count() > 0 
                            ? round($approvedActs->avg('amount'), 2) 
                            : 0;
                    }, 0),
                    
                    // Средняя сумма платежа
                    'avg_payment_amount' => $this->whenLoaded('payments', function() {
                        return $this->payments->count() > 0 
                            ? round($this->payments->avg('amount'), 2) 
                            : 0;
                    }, 0),
                    
                    // Средний срок между актами (в днях)
                    'avg_days_between_acts' => $this->whenLoaded('performanceActs', function() {
                        $acts = $this->performanceActs->where('is_approved', true)
                            ->sortBy('act_date')
                            ->values();
                        
                        if ($acts->count() < 2) return null;
                        
                        $totalDays = 0;
                        for ($i = 1; $i < $acts->count(); $i++) {
                            $totalDays += $acts[$i-1]->act_date->diffInDays($acts[$i]->act_date);
                        }
                        
                        return round($totalDays / ($acts->count() - 1), 1);
                    }, null),
                    
                    // Индекс выполнения (CPI - Cost Performance Index)
                    // CPI > 1 = эффективно, CPI < 1 = перерасход
                    'cost_performance_index' => $this->whenLoaded('payments', function() {
                        $totalPaid = $this->payments->sum('amount') ?? 0;
                        $totalPerformed = 0;
                        
                        if ($this->relationLoaded('performanceActs')) {
                            foreach ($this->performanceActs->where('is_approved', true) as $act) {
                                if ($act->relationLoaded('completedWorks') && $act->completedWorks->count() > 0) {
                                    $totalPerformed += $act->completedWorks->sum('pivot.included_amount');
                                } else {
                                    $totalPerformed += $act->amount ?? 0;
                                }
                            }
                        }
                        
                        return $totalPaid > 0 
                            ? round($totalPerformed / $totalPaid, 3) 
                            : null;
                    }, null),
                    
                    // Индекс выполнения по срокам (SPI - Schedule Performance Index)
                    // SPI > 1 = опережение, SPI < 1 = отставание
                    'schedule_performance_index' => $this->start_date && $this->end_date && $this->end_date->isFuture() ? function() {
                        $totalDuration = $this->start_date->diffInDays($this->end_date);
                        $daysPassed = $this->start_date->diffInDays(now());
                        
                        if ($totalDuration <= 0) return null;
                        
                        $plannedProgress = ($daysPassed / $totalDuration) * 100;
                        $actualProgress = $this->completion_percentage ?? 0;
                        
                        return $plannedProgress > 0 
                            ? round($actualProgress / $plannedProgress, 3) 
                            : null;
                    } : null,
                ],
                
                // Риски
                'risk_indicators' => [
                    'is_nearing_budget_limit' => $this->is_nearing_limit ?? false, // >= 90%
                    'is_overdue' => $this->is_overdue ?? false,
                    'has_unpaid_acts' => $this->whenLoaded('performanceActs', function() {
                        $totalPerformed = 0;
                        foreach ($this->performanceActs->where('is_approved', true) as $act) {
                            if ($act->relationLoaded('completedWorks') && $act->completedWorks->count() > 0) {
                                $totalPerformed += $act->completedWorks->sum('pivot.included_amount');
                            } else {
                                $totalPerformed += $act->amount ?? 0;
                            }
                        }
                        
                        $totalPaid = $this->relationLoaded('payments') 
                            ? $this->payments->sum('amount') 
                            : 0;
                        
                        return $totalPerformed > $totalPaid;
                    }, false),
                    
                    'payment_delay_amount' => max(0, (float) $this->whenLoaded('performanceActs', function() {
                        $totalPerformed = 0;
                        foreach ($this->performanceActs->where('is_approved', true) as $act) {
                            if ($act->relationLoaded('completedWorks') && $act->completedWorks->count() > 0) {
                                $totalPerformed += $act->completedWorks->sum('pivot.included_amount');
                            } else {
                                $totalPerformed += $act->amount ?? 0;
                            }
                        }
                        
                        $totalPaid = $this->relationLoaded('payments') 
                            ? $this->payments->sum('amount') 
                            : 0;
                        
                        return $totalPerformed - $totalPaid;
                    }, 0)),
                ],
            ],
            
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
        ];
    }
} 