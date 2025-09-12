<?php

namespace App\Domain\Authorization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;
use App\Domain\Authorization\Enums\ConditionType;

/**
 * Модель условий для ролей (ABAC)
 * 
 * @property int $id
 * @property int $assignment_id
 * @property string $condition_type
 * @property array $condition_data
 * @property bool $is_active
 */
class RoleCondition extends Model
{
    protected $fillable = [
        'assignment_id',
        'condition_type',
        'condition_data',
        'is_active'
    ];

    protected $casts = [
        'condition_type' => ConditionType::class,
        'condition_data' => 'array',
        'is_active' => 'boolean'
    ];

    // Типы условий теперь определены в ConditionType enum

    /**
     * Назначение роли
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(UserRoleAssignment::class, 'assignment_id');
    }

    /**
     * Scope: только активные условия
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: условия определенного типа
     */
    public function scopeOfType(Builder $query, ConditionType $type): Builder
    {
        return $query->where('condition_type', $type);
    }

    /**
     * Проверить, выполняется ли условие
     */
    public function evaluate(array $context = []): bool
    {
        if (!$this->is_active) {
            return false;
        }

        return match($this->condition_type) {
            ConditionType::TIME => $this->evaluateTimeCondition(),
            ConditionType::LOCATION => $this->evaluateLocationCondition($context),
            ConditionType::BUDGET => $this->evaluateBudgetCondition($context),
            ConditionType::PROJECT_COUNT => $this->evaluateProjectCountCondition(),
            ConditionType::CUSTOM => $this->evaluateCustomCondition($context),
        };
    }

    /**
     * Проверка временного условия
     */
    protected function evaluateTimeCondition(): bool
    {
        $data = $this->condition_data;
        $now = Carbon::now();

        // Проверка рабочих часов
        if (isset($data['working_hours'])) {
            $hours = explode('-', $data['working_hours']);
            if (count($hours) === 2) {
                $start = Carbon::createFromFormat('H:i', trim($hours[0]));
                $end = Carbon::createFromFormat('H:i', trim($hours[1]));
                
                if (!$now->between($start, $end)) {
                    return false;
                }
            }
        }

        // Проверка дней недели
        if (isset($data['working_days'])) {
            $allowedDays = $data['working_days'];
            if (!in_array($now->dayOfWeek, $allowedDays)) {
                return false;
            }
        }

        // Проверка периода действия
        if (isset($data['valid_from'])) {
            $validFrom = Carbon::parse($data['valid_from']);
            if ($now->lt($validFrom)) {
                return false;
            }
        }

        if (isset($data['valid_until'])) {
            $validUntil = Carbon::parse($data['valid_until']);
            if ($now->gt($validUntil)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Проверка географического условия
     */
    protected function evaluateLocationCondition(array $context): bool
    {
        $data = $this->condition_data;

        // Проверка IP адреса
        if (isset($data['allowed_ips'])) {
            $userIp = $context['ip'] ?? request()->ip();
            if (!in_array($userIp, $data['allowed_ips'])) {
                return false;
            }
        }

        // Проверка географических координат (если есть)
        if (isset($data['allowed_regions'], $context['location'])) {
            $userRegion = $context['location']['region'] ?? null;
            if ($userRegion && !in_array($userRegion, $data['allowed_regions'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Проверка бюджетного условия
     */
    protected function evaluateBudgetCondition(array $context): bool
    {
        $data = $this->condition_data;
        $requestedAmount = $context['amount'] ?? 0;

        // Максимальная сумма операции
        if (isset($data['max_amount']) && $requestedAmount > $data['max_amount']) {
            return false;
        }

        // Дневной лимит
        if (isset($data['daily_limit'])) {
            // Здесь нужно проверить потраченную за день сумму
            // Это требует дополнительной логики с учетом истории операций
        }

        // Месячный лимит
        if (isset($data['monthly_limit'])) {
            // Аналогично для месячного лимита
        }

        return true;
    }

    /**
     * Проверка условия количества проектов
     */
    protected function evaluateProjectCountCondition(): bool
    {
        $data = $this->condition_data;
        $user = $this->assignment->user;

        if (isset($data['max_projects'])) {
            $activeProjectsCount = $user->projects()->active()->count();
            if ($activeProjectsCount >= $data['max_projects']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Проверка кастомного условия
     */
    protected function evaluateCustomCondition(array $context): bool
    {
        $data = $this->condition_data;

        // Здесь можно реализовать сложную логику на основе $data
        // Например, вызов callback функции или применение правил

        return true;
    }

    /**
     * Создать временное условие
     */
    public static function createTimeCondition(
        UserRoleAssignment $assignment,
        ?string $workingHours = null,
        ?array $workingDays = null,
        ?Carbon $validFrom = null,
        ?Carbon $validUntil = null
    ): self {
        $data = [];
        
        if ($workingHours) $data['working_hours'] = $workingHours;
        if ($workingDays) $data['working_days'] = $workingDays;
        if ($validFrom) $data['valid_from'] = $validFrom->toDateTimeString();
        if ($validUntil) $data['valid_until'] = $validUntil->toDateTimeString();

        return static::create([
            'assignment_id' => $assignment->id,
            'condition_type' => ConditionType::TIME,
            'condition_data' => $data,
            'is_active' => true
        ]);
    }

    /**
     * Создать бюджетное условие
     */
    public static function createBudgetCondition(
        UserRoleAssignment $assignment,
        ?float $maxAmount = null,
        ?float $dailyLimit = null,
        ?float $monthlyLimit = null
    ): self {
        $data = [];
        
        if ($maxAmount) $data['max_amount'] = $maxAmount;
        if ($dailyLimit) $data['daily_limit'] = $dailyLimit;
        if ($monthlyLimit) $data['monthly_limit'] = $monthlyLimit;

        return static::create([
            'assignment_id' => $assignment->id,
            'condition_type' => ConditionType::BUDGET,
            'condition_data' => $data,
            'is_active' => true
        ]);
    }
}
