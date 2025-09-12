<?php

namespace App\Domain\Authorization\Services;

use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Models\RoleCondition;
use App\Domain\Authorization\Enums\ConditionType;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Сервис для оценки ABAC условий
 */
class ConditionEvaluator
{
    /**
     * Оценить все условия для назначения роли
     */
    public function evaluateAssignmentConditions(UserRoleAssignment $assignment, array $context = []): bool
    {
        $conditions = $assignment->conditions()->active()->get();
        
        if ($conditions->isEmpty()) {
            return true; // Нет условий - разрешаем
        }
        
        foreach ($conditions as $condition) {
            if (!$this->evaluateCondition($condition, $context)) {
                return false; // Одно условие не выполнено - запрещаем
            }
        }
        
        return true; // Все условия выполнены
    }

    /**
     * Оценить отдельное условие
     */
    public function evaluateCondition(RoleCondition $condition, array $context = []): bool
    {
        if (!$condition->is_active) {
            return true;
        }

        return match($condition->condition_type) {
            ConditionType::TIME => $this->evaluateTimeCondition($condition->condition_data, $context),
            ConditionType::LOCATION => $this->evaluateLocationCondition($condition->condition_data, $context),
            ConditionType::BUDGET => $this->evaluateBudgetCondition($condition->condition_data, $context),
            ConditionType::PROJECT_COUNT => $this->evaluateProjectCountCondition($condition->condition_data, $context),
            ConditionType::CUSTOM => $this->evaluateCustomCondition($condition->condition_data, $context),
        };
    }

    /**
     * Оценить временное условие
     */
    protected function evaluateTimeCondition(array $data, array $context): bool
    {
        $now = Carbon::now();

        // Рабочие часы
        if (isset($data['working_hours'])) {
            $hours = explode('-', $data['working_hours']);
            if (count($hours) === 2) {
                try {
                    $start = Carbon::createFromFormat('H:i', trim($hours[0]));
                    $end = Carbon::createFromFormat('H:i', trim($hours[1]));
                    
                    if (!$now->between($start, $end)) {
                        return false;
                    }
                } catch (\Exception $e) {
                    // Неверный формат времени - пропускаем условие
                    Log::warning('Invalid time format in condition', $data);
                }
            }
        }

        // Дни недели (0 = воскресенье, 6 = суббота)
        if (isset($data['working_days']) && is_array($data['working_days'])) {
            if (!in_array($now->dayOfWeek, $data['working_days'])) {
                return false;
            }
        }

        // Период действия
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
     * Оценить географическое условие
     */
    protected function evaluateLocationCondition(array $data, array $context): bool
    {
        // Проверка IP адреса
        if (isset($data['allowed_ips']) && is_array($data['allowed_ips'])) {
            $userIp = $context['ip'] ?? request()->ip();
            if (!in_array($userIp, $data['allowed_ips'])) {
                return false;
            }
        }

        // Проверка регионов
        if (isset($data['allowed_regions'], $context['location']['region'])) {
            $userRegion = $context['location']['region'];
            if (!in_array($userRegion, $data['allowed_regions'])) {
                return false;
            }
        }

        // Проверка геолокации (если требуется)
        if (isset($data['require_geolocation']) && $data['require_geolocation']) {
            if (!isset($context['location']['lat'], $context['location']['lon'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Оценить бюджетное условие
     */
    protected function evaluateBudgetCondition(array $data, array $context): bool
    {
        $requestedAmount = $context['amount'] ?? 0;

        // Максимальная сумма операции
        if (isset($data['max_amount']) && $requestedAmount > $data['max_amount']) {
            return false;
        }

        // Дневной лимит (требует дополнительной логики с историей операций)
        if (isset($data['daily_limit'])) {
            // TODO: Реализовать проверку дневного лимита
            // Нужно подсчитать сумму операций за сегодня для пользователя
        }

        // Месячный лимит
        if (isset($data['monthly_limit'])) {
            // TODO: Реализовать проверку месячного лимита
        }

        return true;
    }

    /**
     * Оценить условие количества проектов
     */
    protected function evaluateProjectCountCondition(array $data, array $context): bool
    {
        if (!isset($data['max_projects'])) {
            return true;
        }

        // Получаем пользователя из контекста
        $userId = $context['user_id'] ?? null;
        if (!$userId) {
            return false;
        }

        // Подсчитываем активные проекты пользователя
        $activeProjectsCount = $this->getUserActiveProjectsCount($userId);
        
        return $activeProjectsCount < $data['max_projects'];
    }

    /**
     * Оценить кастомное условие
     */
    protected function evaluateCustomCondition(array $data, array $context): bool
    {
        // Реализация кастомных условий зависит от бизнес-логики
        
        // Пример: проверка уровня доступа
        if (isset($data['min_access_level'], $context['user_access_level'])) {
            return $context['user_access_level'] >= $data['min_access_level'];
        }

        // Пример: проверка стажа работы
        if (isset($data['min_experience_months'], $context['user_experience'])) {
            return $context['user_experience'] >= $data['min_experience_months'];
        }

        return true;
    }

    /**
     * Получить количество активных проектов пользователя
     */
    protected function getUserActiveProjectsCount(int $userId): int
    {
        // Подсчет зависит от структуры проектов в вашей системе
        return \App\Models\Project::where('status', 'active')
            ->whereHas('users', function ($query) use ($userId) {
                $query->where('user_id', $userId);
            })
            ->count();
    }

    /**
     * Создать временное условие
     */
    public function createTimeCondition(
        UserRoleAssignment $assignment,
        ?string $workingHours = null,
        ?array $workingDays = null,
        ?Carbon $validFrom = null,
        ?Carbon $validUntil = null
    ): RoleCondition {
        $data = [];
        
        if ($workingHours) $data['working_hours'] = $workingHours;
        if ($workingDays) $data['working_days'] = $workingDays;
        if ($validFrom) $data['valid_from'] = $validFrom->toDateTimeString();
        if ($validUntil) $data['valid_until'] = $validUntil->toDateTimeString();

        return RoleCondition::create([
            'assignment_id' => $assignment->id,
            'condition_type' => ConditionType::TIME,
            'condition_data' => $data,
            'is_active' => true
        ]);
    }

    /**
     * Создать бюджетное условие
     */
    public function createBudgetCondition(
        UserRoleAssignment $assignment,
        ?float $maxAmount = null,
        ?float $dailyLimit = null,
        ?float $monthlyLimit = null
    ): RoleCondition {
        $data = [];
        
        if ($maxAmount !== null) $data['max_amount'] = $maxAmount;
        if ($dailyLimit !== null) $data['daily_limit'] = $dailyLimit;
        if ($monthlyLimit !== null) $data['monthly_limit'] = $monthlyLimit;

        return RoleCondition::create([
            'assignment_id' => $assignment->id,
            'condition_type' => ConditionType::BUDGET,
            'condition_data' => $data,
            'is_active' => true
        ]);
    }
}
