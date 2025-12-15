<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Models\Estimate;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\ContractWorkTypeCategoryEnum;
use App\Enums\Contract\GpCalculationTypeEnum;
use Carbon\Carbon;
use App\Traits\HasOnboardingDemo;

class Contract extends Model
{
    use HasFactory, SoftDeletes, HasOnboardingDemo;

    protected $fillable = [
        'organization_id',
        'project_id',
        'contractor_id',
        'supplier_id',
        'contract_category',
        'number',
        'date',
        'subject',
        'work_type_category',
        'payment_terms',
        'base_amount',
        'total_amount',
        'gp_percentage',
        'gp_calculation_type',
        'gp_coefficient',
        'warranty_retention_calculation_type',
        'warranty_retention_percentage',
        'warranty_retention_coefficient',
        'subcontract_amount',
        'planned_advance_amount',
        'actual_advance_amount',
        'status',
        'start_date',
        'end_date',
        'notes',
        'is_onboarding_demo',
        'is_fixed_amount',
        'is_multi_project',
    ];

    protected $casts = [
        'date' => 'date',
        'base_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'gp_percentage' => 'decimal:3',
        'gp_calculation_type' => GpCalculationTypeEnum::class,
        'gp_coefficient' => 'decimal:4',
        'warranty_retention_calculation_type' => GpCalculationTypeEnum::class,
        'warranty_retention_percentage' => 'decimal:3',
        'warranty_retention_coefficient' => 'decimal:4',
        'subcontract_amount' => 'decimal:2',
        'planned_advance_amount' => 'decimal:2',
        'actual_advance_amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'status' => ContractStatusEnum::class,
        'work_type_category' => ContractWorkTypeCategoryEnum::class,
        'is_onboarding_demo' => 'boolean',
        'is_fixed_amount' => 'boolean',
        'is_multi_project' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Проекты, к которым привязан контракт (для мультипроектных контрактов)
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'contract_project')
                    ->withTimestamps();
    }

    /**
     * Распределения сумм контракта по проектам
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(ContractProjectAllocation::class);
    }

    /**
     * Получить активные распределения
     */
    public function activeAllocations(): HasMany
    {
        return $this->hasMany(ContractProjectAllocation::class)->where('is_active', true);
    }

    /**
     * Получить распределение для конкретного проекта
     */
    public function allocationForProject(int $projectId): ?ContractProjectAllocation
    {
        return $this->activeAllocations()
            ->where('project_id', $projectId)
            ->first();
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    /**
     * Поставщик (для договоров поставки)
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function performanceActs(): HasMany
    {
        return $this->hasMany(ContractPerformanceAct::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ContractPayment::class);
    }

    /**
     * Получить выполненные работы по данному договору.
     */
    public function completedWorks(): HasMany
    {
        return $this->hasMany(CompletedWork::class);
    }

    public function estimates(): HasMany
    {
        return $this->hasMany(Estimate::class);
    }

    /**
     * Получить активную/основную смету договора
     */
    public function estimate(): HasOne
    {
        return $this->hasOne(Estimate::class)->latestOfMany();
    }

    public function agreements(): HasMany
    {
        return $this->hasMany(SupplementaryAgreement::class);
    }

    public function specifications(): BelongsToMany
    {
        return $this->belongsToMany(Specification::class, 'contract_specification')
                    ->withPivot('attached_at', 'is_active')
                    ->withTimestamps();
    }

    /**
     * Активная спецификация (через pivot)
     */
    public function activeSpecification()
    {
        return $this->specifications()
                    ->wherePivot('is_active', true)
                    ->first();
    }

    /**
     * События состояния договора (Event Sourcing)
     */
    public function stateEvents(): HasMany
    {
        return $this->hasMany(ContractStateEvent::class);
    }

    /**
     * Текущее состояние договора (материализованное представление)
     */
    public function currentState(): HasOne
    {
        return $this->hasOne(ContractCurrentState::class);
    }

    /**
     * Проверка, использует ли договор Event Sourcing (новый) или legacy (старый)
     */
    public function usesEventSourcing(): bool
    {
        return $this->stateEvents()->exists();
    }

    /**
     * Получить базовую сумму контракта (без ГП)
     * Если base_amount не задан, возвращаем total_amount (для legacy контрактов)
     * Для контрактов с нефиксированной суммой может быть null
     */
    public function getBaseAmountAttribute($value): ?float
    {
        // Если base_amount явно установлен в БД, возвращаем его
        if ($value !== null) {
            return (float) $value;
        }
        
        // Для контрактов с нефиксированной суммой возвращаем null
        if (!$this->is_fixed_amount) {
            return null;
        }
        
        // Legacy: для старых контрактов без base_amount используем total_amount
        return (float) ($this->attributes['total_amount'] ?? 0);
    }

    /**
     * Рассчитать сумму ГП (генподрядного процента)
     * ВАЖНО: ГП рассчитывается от base_amount (базовой суммы), а не от total_amount!
     * Для контрактов с нефиксированной суммой возвращает 0 (ГП не применим)
     */
    public function getGpAmountAttribute(): float
    {
        // Для контрактов с нефиксированной суммой ГП не применим
        if (!$this->is_fixed_amount) {
            return 0.00;
        }
        
        $baseAmount = $this->base_amount ?? 0;
        $calculationType = $this->gp_calculation_type ?? GpCalculationTypeEnum::PERCENTAGE;
        
        if ($calculationType === GpCalculationTypeEnum::COEFFICIENT) {
            $coefficient = $this->gp_coefficient ?? 0;
            // Формула: gp_amount = base_amount × (coefficient - 1)
            // Коэффициент 1.0 → изменение 0
            // Коэффициент 0.944 → изменение -5.6% от базы
            // Коэффициент 1.1 → изменение +10% от базы
            return round($baseAmount * ($coefficient - 1), 2);
        }
        
        $percentage = $this->gp_percentage ?? 0;
        if ($percentage != 0 && $baseAmount > 0) {
            // Расчет: base_amount * (gp_percentage / 100)
            // Например: 7961111.72 * (-0.944 / 100) = -75152.89
            return round(($baseAmount * $percentage) / 100, 2);
        }
        
        return 0.00;
    }

    /**
     * Получить итоговую сумму контракта с учетом генподрядного процента
     * total_amount_with_gp = base_amount + gp_amount
     * Для контрактов с нефиксированной суммой возвращает null
     */
    public function getTotalAmountWithGpAttribute(): ?float
    {
        // Для контрактов с нефиксированной суммой итоговая сумма не определена
        if (!$this->is_fixed_amount) {
            return null;
        }
        
        $baseAmount = $this->base_amount ?? 0;
        $gpAmount = $this->gp_amount ?? 0;
        return round($baseAmount + $gpAmount, 2);
    }

    /**
     * Рассчитать сумму гарантийного удержания от общей суммы контракта
     * Гарантийное удержание рассчитывается от общей суммы (base_amount + gp_amount)
     * По умолчанию: 2.5% от общей суммы контракта
     */
    public function getWarrantyRetentionAmountAttribute(): float
    {
        // Общая сумма контракта = базовая сумма + ГП
        $baseAmount = $this->base_amount ?? 0;
        $gpAmount = $this->gp_amount ?? 0;
        $totalContractAmount = $baseAmount + $gpAmount;
        
        // Если общая сумма равна нулю, то и гарантийное удержание равно нулю
        if ($totalContractAmount == 0) {
            return 0.00;
        }
        
        $calculationType = $this->warranty_retention_calculation_type ?? GpCalculationTypeEnum::PERCENTAGE;
        
        if ($calculationType === GpCalculationTypeEnum::COEFFICIENT) {
            $coefficient = $this->warranty_retention_coefficient ?? 0;
            // Формула: warranty_retention_amount = total_amount × (1 - coefficient)
            // Коэффициент 1.0 → удержание 0
            // Коэффициент 0.975 → удержание 2.5% от общей суммы
            // Коэффициент 0.95 → удержание 5% от общей суммы
            return round($totalContractAmount * (1 - $coefficient), 2);
        }
        
        // По умолчанию 2.5% от общей суммы контракта
        $percentage = $this->warranty_retention_percentage ?? 2.5;
        if ($percentage != 0 && $totalContractAmount > 0) {
            // Расчет: total_amount * (warranty_retention_percentage / 100)
            // Например: 1100000 * (2.5 / 100) = 27500
            return round(($totalContractAmount * $percentage) / 100, 2);
        }
        
        return 0.00;
    }

    /**
     * Получить общую сумму контракта с учетом генподряда и субподряда
     */

    /**
     * Получить остаток планируемого аванса для выплаты
     */
    public function getRemainingAdvanceAmountAttribute(): float
    {
        $planned = $this->planned_advance_amount ?? 0;
        $actual = $this->actual_advance_amount ?? 0;
        return max(0, $planned - $actual);
    }

    /**
     * Проверить, выплачен ли планируемый аванс полностью
     */
    public function getIsAdvanceFullyPaidAttribute(): bool
    {
        $planned = $this->planned_advance_amount ?? 0;
        $actual = $this->actual_advance_amount ?? 0;
        return $planned > 0 && $actual >= $planned;
    }

    /**
     * Получить процент выплаченного аванса
     */
    public function getAdvancePaymentPercentageAttribute(): float
    {
        $planned = $this->planned_advance_amount ?? 0;
        $actual = $this->actual_advance_amount ?? 0;
        
        if ($planned <= 0) {
            return 0.0;
        }
        return round(($actual / $planned) * 100, 2);
    }

    /**
     * Получить общую сумму выполненных работ по контракту
     */
    public function getCompletedWorksAmountAttribute(): float
    {
        return (float) $this->completedWorks()
            ->whereIn('status', ['confirmed'])
            ->sum('total_amount');
    }

    /**
     * Получить оставшуюся сумму по контракту
     * Для контрактов с нефиксированной суммой возвращает null (остаток не определен)
     */
    public function getRemainingAmountAttribute(): ?float
    {
        // Для контрактов с нефиксированной суммой остаток не определен
        if (!$this->is_fixed_amount) {
            return null;
        }
        
        $totalAmount = $this->total_amount ?? 0;
        $completedAmount = $this->completed_works_amount ?? 0;
        return max(0, $totalAmount - $completedAmount);
    }

    /**
     * Получить процент выполнения контракта
     * Для контрактов с нефиксированной суммой всегда возвращает 0 (процент не применим)
     */
    public function getCompletionPercentageAttribute(): float
    {
        // Для контрактов с нефиксированной суммой процент выполнения не применим
        if (!$this->is_fixed_amount) {
            return 0.0;
        }
        
        $totalAmount = $this->total_amount ?? 0;
        $completedAmount = $this->completed_works_amount ?? 0;
        
        if ($totalAmount <= 0) {
            return 0.0;
        }
        return round(($completedAmount / $totalAmount) * 100, 2);
    }

    /**
     * Проверить, можно ли добавить работу на указанную сумму
     * Для контрактов с нефиксированной суммой всегда возвращает true (лимита нет)
     */
    public function canAddWork(float $amount): bool
    {
        // Проверяем статус контракта
        if (in_array($this->status, [ContractStatusEnum::COMPLETED, ContractStatusEnum::TERMINATED])) {
            return false;
        }

        // Для контрактов с нефиксированной суммой нет лимита
        if (!$this->is_fixed_amount) {
            return true;
        }

        $totalAmount = $this->total_amount ?? 0;
        $completedAmount = $this->completed_works_amount ?? 0;
        
        // Проверяем лимит суммы (с допуском 1%)
        $allowedOverrun = $totalAmount * 0.01;
        return ($completedAmount + $amount) <= ($totalAmount + $allowedOverrun);
    }

    /**
     * Проверить, приближается ли контракт к лимиту (90%+)
     */
    public function isNearingLimit(): bool
    {
        return $this->completion_percentage >= 90.0;
    }

    /**
     * Проверить, просрочен ли контракт
     */
    public function getIsOverdueAttribute(): bool
    {
        return $this->end_date && $this->end_date->isPast() && $this->status === ContractStatusEnum::ACTIVE;
    }

    /**
     * Получить сумму всех платежей по контракту
     */
    public function getTotalPaidAmountAttribute(): float
    {
        return (float) $this->payments()
            ->sum('amount');
    }

    /**
     * Получить сумму всех актов по контракту
     */
    public function getTotalPerformedAmountAttribute(): float
    {
        return (float) $this->performanceActs()
            ->where('is_approved', true)
            ->sum('amount');
    }

    /**
     * Автоматически обновить статус контракта на основе выполненных работ
     */
    public function updateStatusBasedOnCompletion(): bool
    {
        $oldStatus = $this->status;
        
        if ($this->completion_percentage >= 100 && $this->status === ContractStatusEnum::ACTIVE) {
            $this->status = ContractStatusEnum::COMPLETED;
        } elseif ($this->completion_percentage > 0 && $this->status === ContractStatusEnum::DRAFT) {
            $this->status = ContractStatusEnum::ACTIVE;
        }

        if ($this->status !== $oldStatus) {
            $this->save();
            
            // Отправляем событие об изменении статуса
            event(new \App\Events\ContractStatusChanged($this, $oldStatus->value, $this->status->value));
            
            return true;
        }

        return false;
    }

    /**
     * Получить текущее состояние через Event Sourcing
     */
    public function getCurrentState()
    {
        if (!$this->usesEventSourcing()) {
            return null; // Legacy договор - возвращаем null
        }

        $service = app(\App\Services\Contract\ContractStateEventService::class);
        return $service->getCurrentState($this);
    }

    /**
     * Получить состояние договора на определенную дату
     */
    public function getStateAtDate(Carbon $date)
    {
        if (!$this->usesEventSourcing()) {
            return null; // Legacy договор - возвращаем null
        }

        $service = app(\App\Services\Contract\ContractStateEventService::class);
        return $service->getStateAtDate($this, $date);
    }

    /**
     * Получить timeline событий
     */
    public function getTimeline(?Carbon $asOfDate = null)
    {
        if (!$this->usesEventSourcing()) {
            return collect(); // Legacy договор - возвращаем пустую коллекцию
        }

        $service = app(\App\Services\Contract\ContractStateEventService::class);
        return $service->getTimeline($this, $asOfDate);
    }

    /**
     * Пересчитать total_amount для контрактов с нефиксированной суммой
     * total_amount = сумма всех одобренных актов + сумма всех дополнительных соглашений
     * 
     * @return float Новая сумма контракта
     */
    public function recalculateTotalAmountForNonFixed(): ?float
    {
        // Пересчет только для контрактов с нефиксированной суммой
        if ($this->is_fixed_amount) {
            return null;
        }

        // Сумма всех одобренных актов (без учета удаленных)
        $actsAmount = $this->performanceActs()
            ->where('is_approved', true)
            ->sum('amount') ?? 0;

        // Сумма всех дополнительных соглашений (без учета удаленных через soft deletes)
        $agreementsAmount = $this->agreements()
            ->sum('change_amount') ?? 0;

        // Итоговая сумма
        $newTotalAmount = round((float) $actsAmount + (float) $agreementsAmount, 2);

        // Обновляем только если сумма изменилась
        if (abs((float) ($this->total_amount ?? 0) - $newTotalAmount) > 0.01) {
            $this->updateQuietly(['total_amount' => $newTotalAmount]);
            $this->total_amount = $newTotalAmount;
        }

        return $newTotalAmount;
    }

    // ============================================
    // SCOPES
    // ============================================

    /**
     * Scope для организации
     */
    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    // ============================================
    // Procurement Contracts (Договоры поставки)
    // ============================================

    /**
     * Scope для договоров поставки
     */
    public function scopeProcurementContracts($query)
    {
        return $query->where(function ($q) {
            $q->whereNotNull('supplier_id')
              ->orWhere('contract_category', 'procurement')
              ->orWhere('work_type_category', ContractWorkTypeCategoryEnum::SUPPLY->value);
        });
    }

    /**
     * Scope для договоров с подрядчиками (обычные договоры)
     */
    public function scopeWorkContracts($query)
    {
        return $query->where(function ($q) {
            $q->whereNotNull('contractor_id')
              ->where(function ($subQ) {
                  $subQ->whereNull('supplier_id')
                       ->orWhere('contract_category', 'work')
                       ->orWhere(function ($catQ) {
                           $catQ->whereNull('contract_category')
                                ->where('work_type_category', '!=', ContractWorkTypeCategoryEnum::SUPPLY->value);
                       });
              });
        });
    }

    /**
     * Проверить, является ли договор договором поставки
     */
    public function isProcurementContract(): bool
    {
        return $this->supplier_id !== null 
            || $this->contract_category === 'procurement'
            || $this->work_type_category === ContractWorkTypeCategoryEnum::SUPPLY;
    }

    /**
     * Проверить, является ли договор обычным договором с подрядчиком
     */
    public function isWorkContract(): bool
    {
        return $this->contractor_id !== null && !$this->isProcurementContract();
    }

    /**
     * Получить список ID проектов для контракта
     * Для мультипроектных контрактов возвращает массив ID из pivot таблицы
     * Для обычных контрактов возвращает массив с одним project_id
     */
    public function getProjectIds(): array
    {
        if ($this->is_multi_project) {
            return $this->projects()->pluck('projects.id')->toArray();
        }
        
        return $this->project_id ? [$this->project_id] : [];
    }

    /**
     * Синхронизировать проекты для контракта
     * @param array $projectIds - массив ID проектов
     */
    public function syncProjects(array $projectIds): void
    {
        if ($this->is_multi_project) {
            $this->projects()->sync($projectIds);
        } else {
            // Для обычного контракта устанавливаем первый проект из массива
            $this->project_id = !empty($projectIds) ? $projectIds[0] : null;
            $this->save();
            
            // Синхронизируем pivot таблицу для консистентности
            if ($this->project_id) {
                $this->projects()->sync([$this->project_id]);
            } else {
                $this->projects()->sync([]);
            }
        }
    }

    /**
     * Получить выделенную сумму для проекта
     * @param int|null $projectId - ID проекта
     * @return float
     */
    public function getAllocatedAmount(?int $projectId = null): float
    {
        // Если projectId не указан, используем основной проект
        if ($projectId === null) {
            $projectId = $this->project_id;
        }

        // Если проект не указан, возвращаем полную сумму
        if ($projectId === null) {
            return (float) $this->total_amount;
        }

        // Ищем активное распределение для проекта
        $allocation = $this->allocationForProject($projectId);

        // Если распределение найдено, используем его
        if ($allocation) {
            return $allocation->calculateAllocatedAmount();
        }

        // Если распределения нет и контракт не мультипроектный, возвращаем полную сумму
        if (!$this->is_multi_project) {
            return (float) $this->total_amount;
        }

        // Для мультипроектных контрактов без явного распределения
        // используем автоматический расчет (пропорционально актам)
        return $this->calculateAutoAllocation($projectId);
    }

    /**
     * Автоматический расчет распределения на основе актов
     * @param int $projectId
     * @return float
     */
    protected function calculateAutoAllocation(int $projectId): float
    {
        // Получаем общую сумму всех актов по контракту
        $totalActsAmount = ContractPerformanceAct::where('contract_id', $this->id)
            ->where('is_approved', true)
            ->sum('amount');

        // Если актов нет, распределяем поровну между проектами
        if ($totalActsAmount == 0) {
            $projectsCount = $this->projects()->count();
            return $projectsCount > 0 
                ? (float) $this->total_amount / $projectsCount 
                : (float) $this->total_amount;
        }

        // Получаем сумму актов по конкретному проекту
        $projectActsAmount = ContractPerformanceAct::where('contract_id', $this->id)
            ->where('project_id', $projectId)
            ->where('is_approved', true)
            ->sum('amount');

        // Рассчитываем пропорциональную долю
        $proportion = $projectActsAmount / $totalActsAmount;

        return (float) $this->total_amount * $proportion;
    }
}