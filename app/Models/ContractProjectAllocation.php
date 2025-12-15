<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Enums\Contract\ContractAllocationTypeEnum;
use Illuminate\Support\Facades\Auth;

class ContractProjectAllocation extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'contract_id',
        'project_id',
        'allocation_type',
        'allocated_amount',
        'allocated_percentage',
        'custom_formula',
        'notes',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'allocation_type' => ContractAllocationTypeEnum::class,
        'allocated_amount' => 'decimal:2',
        'allocated_percentage' => 'decimal:2',
        'custom_formula' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Boot метод для автоматического логирования
     */
    protected static function boot()
    {
        parent::boot();

        // Автоматически устанавливаем created_by при создании
        static::creating(function ($allocation) {
            if (Auth::check() && !$allocation->created_by) {
                $allocation->created_by = Auth::id();
            }
        });

        // Автоматически устанавливаем updated_by при обновлении
        static::updating(function ($allocation) {
            if (Auth::check()) {
                $allocation->updated_by = Auth::id();
            }
        });

        // Логируем создание
        static::created(function ($allocation) {
            $allocation->logHistory('created', null, $allocation->toArray());
        });

        // Логируем обновление
        static::updated(function ($allocation) {
            $allocation->logHistory('updated', $allocation->getOriginal(), $allocation->getChanges());
        });

        // Логируем удаление
        static::deleted(function ($allocation) {
            $allocation->logHistory('deleted', $allocation->toArray(), null);
        });
    }

    /**
     * Контракт
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * Проект
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Пользователь, создавший распределение
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Пользователь, обновивший распределение
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * История изменений
     */
    public function history(): HasMany
    {
        return $this->hasMany(ContractAllocationHistory::class, 'allocation_id');
    }

    /**
     * Рассчитать выделенную сумму на основе типа распределения
     */
    public function calculateAllocatedAmount(): float
    {
        return match ($this->allocation_type) {
            ContractAllocationTypeEnum::FIXED => (float) $this->allocated_amount,
            ContractAllocationTypeEnum::PERCENTAGE => $this->calculateFromPercentage(),
            ContractAllocationTypeEnum::AUTO => $this->calculateAuto(),
            ContractAllocationTypeEnum::CUSTOM => $this->calculateCustom(),
        };
    }

    /**
     * Расчет на основе процента
     */
    protected function calculateFromPercentage(): float
    {
        if (!$this->contract) {
            $this->load('contract');
        }

        $contractTotal = (float) $this->contract->total_amount;
        $percentage = (float) $this->allocated_percentage;

        return $contractTotal * ($percentage / 100);
    }

    /**
     * Автоматический расчет (на основе актов или равномерно)
     */
    protected function calculateAuto(): float
    {
        if (!$this->contract) {
            $this->load('contract');
        }

        // Если контракт не мультипроектный, возвращаем полную сумму
        if (!$this->contract->is_multi_project) {
            return (float) $this->contract->total_amount;
        }

        // Получаем общую сумму всех актов по контракту
        $totalActsAmount = \App\Models\ContractPerformanceAct::where('contract_id', $this->contract_id)
            ->where('is_approved', true)
            ->sum('amount');

        // Если актов нет, распределяем поровну между проектами
        if ($totalActsAmount == 0) {
            $projectsCount = $this->contract->projects()->count();
            return $projectsCount > 0 
                ? (float) $this->contract->total_amount / $projectsCount 
                : (float) $this->contract->total_amount;
        }

        // Получаем сумму актов по конкретному проекту
        $projectActsAmount = \App\Models\ContractPerformanceAct::where('contract_id', $this->contract_id)
            ->where('project_id', $this->project_id)
            ->where('is_approved', true)
            ->sum('amount');

        // Рассчитываем пропорциональную долю
        $proportion = $projectActsAmount / $totalActsAmount;

        return (float) $this->contract->total_amount * $proportion;
    }

    /**
     * Расчет по пользовательской формуле
     */
    protected function calculateCustom(): float
    {
        if (!$this->custom_formula || !is_array($this->custom_formula)) {
            return 0;
        }

        // Здесь можно реализовать различные типы формул
        // Например: по площади, по объему работ, по количеству рабочих и т.д.
        
        // Пример простой формулы на основе коэффициента
        if (isset($this->custom_formula['type']) && $this->custom_formula['type'] === 'coefficient') {
            $coefficient = (float) ($this->custom_formula['coefficient'] ?? 1);
            return (float) $this->contract->total_amount * $coefficient;
        }

        return 0;
    }

    /**
     * Валидация корректности распределения
     */
    public function validate(): array
    {
        $errors = [];

        // Проверяем, что сумма всех активных распределений не превышает сумму контракта
        $totalAllocated = static::where('contract_id', $this->contract_id)
            ->where('is_active', true)
            ->where('id', '!=', $this->id)
            ->get()
            ->sum(function ($allocation) {
                return $allocation->calculateAllocatedAmount();
            });

        $currentAllocated = $this->calculateAllocatedAmount();
        $contractTotal = (float) $this->contract->total_amount;

        if (($totalAllocated + $currentAllocated) > $contractTotal) {
            $errors[] = 'Сумма всех распределений превышает общую сумму контракта';
        }

        // Проверяем наличие обязательных полей в зависимости от типа
        if ($this->allocation_type === ContractAllocationTypeEnum::FIXED && !$this->allocated_amount) {
            $errors[] = 'Для фиксированного распределения необходимо указать сумму';
        }

        if ($this->allocation_type === ContractAllocationTypeEnum::PERCENTAGE && !$this->allocated_percentage) {
            $errors[] = 'Для процентного распределения необходимо указать процент';
        }

        if ($this->allocation_type === ContractAllocationTypeEnum::CUSTOM && !$this->custom_formula) {
            $errors[] = 'Для пользовательского распределения необходимо указать формулу';
        }

        return $errors;
    }

    /**
     * Логирование изменений в историю
     */
    protected function logHistory(string $action, ?array $oldValues, ?array $newValues): void
    {
        ContractAllocationHistory::create([
            'allocation_id' => $this->id,
            'contract_id' => $this->contract_id,
            'project_id' => $this->project_id,
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'user_id' => Auth::id(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * Scope для получения только активных распределений
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope для получения распределений по контракту
     */
    public function scopeForContract($query, int $contractId)
    {
        return $query->where('contract_id', $contractId);
    }

    /**
     * Scope для получения распределений по проекту
     */
    public function scopeForProject($query, int $projectId)
    {
        return $query->where('project_id', $projectId);
    }
}

