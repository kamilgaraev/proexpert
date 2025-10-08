<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\BusinessModules\Features\AdvancedDashboard\Models\DashboardAlert;
use App\BusinessModules\Features\AdvancedDashboard\Events\AlertTriggered;
use App\BusinessModules\Features\AdvancedDashboard\Services\KPICalculationService;
use App\Models\Contract;
use App\Models\Project;
use App\Models\Material;

/**
 * Сервис управления алертами
 * 
 * Предоставляет методы для:
 * - Регистрации и управления алертами
 * - Проверки условий срабатывания
 * - Отправки уведомлений
 */
class AlertsService
{
    /**
     * Зарегистрировать новый алерт
     * 
     * @param int $userId ID пользователя
     * @param int $organizationId ID организации
     * @param array $data Данные алерта
     * @return DashboardAlert
     */
    public function registerAlert(int $userId, int $organizationId, array $data): DashboardAlert
    {
        // Валидация данных
        $this->validateAlertData($data);
        
        // Создаем алерт
        $alert = DashboardAlert::create([
            'dashboard_id' => $data['dashboard_id'] ?? null,
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'alert_type' => $data['alert_type'],
            'target_entity' => $data['target_entity'] ?? null,
            'target_entity_id' => $data['target_entity_id'] ?? null,
            'conditions' => $data['conditions'],
            'comparison_operator' => $data['comparison_operator'],
            'threshold_value' => $data['threshold_value'] ?? null,
            'threshold_unit' => $data['threshold_unit'] ?? null,
            'notification_channels' => $data['notification_channels'] ?? ['in_app'],
            'recipients' => $data['recipients'] ?? [],
            'cooldown_minutes' => $data['cooldown_minutes'] ?? 60,
            'priority' => $data['priority'] ?? 'medium',
            'is_active' => $data['is_active'] ?? true,
        ]);
        
        Log::info('Alert registered', [
            'alert_id' => $alert->id,
            'type' => $alert->alert_type,
            'user_id' => $userId,
        ]);
        
        return $alert;
    }

    /**
     * Обновить алерт
     * 
     * @param int $alertId ID алерта
     * @param array $data Новые данные
     * @return DashboardAlert
     */
    public function updateAlert(int $alertId, array $data): DashboardAlert
    {
        $alert = DashboardAlert::findOrFail($alertId);
        
        $this->validateAlertData($data);
        
        $alert->update($data);
        
        return $alert->fresh();
    }

    /**
     * Удалить алерт
     * 
     * @param int $alertId ID алерта
     * @return bool
     */
    public function deleteAlert(int $alertId): bool
    {
        $alert = DashboardAlert::findOrFail($alertId);
        
        return $alert->delete();
    }

    /**
     * Включить/выключить алерт
     * 
     * @param int $alertId ID алерта
     * @param bool $isActive Новый статус
     * @return DashboardAlert
     */
    public function toggleAlert(int $alertId, bool $isActive): DashboardAlert
    {
        $alert = DashboardAlert::findOrFail($alertId);
        
        $alert->update(['is_active' => $isActive]);
        
        return $alert->fresh();
    }

    /**
     * Проверить условия всех активных алертов
     * 
     * @param int|null $organizationId Проверить только для организации (опционально)
     * @return array Статистика проверки
     */
    public function checkAllAlerts(?int $organizationId = null): array
    {
        $query = DashboardAlert::active()->needingCheck();
        
        if ($organizationId) {
            $query->forOrganization($organizationId);
        }
        
        $alerts = $query->get();
        
        $stats = [
            'checked' => 0,
            'triggered' => 0,
            'errors' => 0,
        ];
        
        foreach ($alerts as $alert) {
            try {
                $shouldTrigger = $this->checkAlertConditions($alert);
                
                $alert->updateCheckTime();
                $stats['checked']++;
                
                if ($shouldTrigger && $alert->canTrigger()) {
                    $this->triggerAlert($alert);
                    $stats['triggered']++;
                }
                
            } catch (\Exception $e) {
                Log::error('Alert check failed', [
                    'alert_id' => $alert->id,
                    'error' => $e->getMessage(),
                ]);
                $stats['errors']++;
            }
        }
        
        return $stats;
    }

    /**
     * Проверить условия конкретного алерта
     * 
     * @param DashboardAlert $alert Алерт для проверки
     * @return bool Должен ли сработать алерт
     */
    public function checkAlertConditions(DashboardAlert $alert): bool
    {
        switch ($alert->alert_type) {
            case 'budget_overrun':
                return $this->checkBudgetOverrun($alert);
            
            case 'deadline_risk':
                return $this->checkDeadlineRisk($alert);
            
            case 'low_stock':
                return $this->checkLowStock($alert);
            
            case 'contract_completion':
                return $this->checkContractCompletion($alert);
            
            case 'payment_overdue':
                return $this->checkPaymentOverdue($alert);
            
            case 'kpi_threshold':
                return $this->checkKPIThreshold($alert);
            
            case 'custom':
                return $this->checkCustomConditions($alert);
            
            default:
                Log::warning('Unknown alert type', ['type' => $alert->alert_type]);
                return false;
        }
    }

    /**
     * Сбросить состояние алерта
     * 
     * @param int $alertId ID алерта
     * @return DashboardAlert
     */
    public function resetAlert(int $alertId): DashboardAlert
    {
        $alert = DashboardAlert::findOrFail($alertId);
        
        $alert->reset();
        
        return $alert->fresh();
    }

    /**
     * Получить историю срабатываний алерта
     * 
     * @param int $alertId ID алерта
     * @param int $limit Количество записей
     * @return array
     */
    public function getAlertHistory(int $alertId, int $limit = 50): array
    {
        $alert = DashboardAlert::findOrFail($alertId);
        
        $history = DB::table('alert_history')
            ->where('alert_id', $alertId)
            ->orderBy('triggered_at', 'desc')
            ->limit($limit)
            ->get();
        
        $formattedHistory = [];
        foreach ($history as $record) {
            $formattedHistory[] = [
                'id' => $record->id,
                'status' => $record->status,
                'trigger_value' => $record->trigger_value,
                'message' => $record->message,
                'triggered_at' => Carbon::parse($record->triggered_at)->toIso8601String(),
                'resolved_at' => $record->resolved_at ? Carbon::parse($record->resolved_at)->toIso8601String() : null,
                'trigger_data' => json_decode($record->trigger_data, true),
            ];
        }
        
        return [
            'alert_id' => $alert->id,
            'alert_name' => $alert->name,
            'total_triggers' => $alert->trigger_count,
            'last_triggered_at' => $alert->last_triggered_at?->toIso8601String(),
            'last_checked_at' => $alert->last_checked_at?->toIso8601String(),
            'is_triggered' => $alert->is_triggered,
            'history' => $formattedHistory,
            'history_count' => count($formattedHistory),
        ];
    }

    // ==================== PROTECTED CHECK METHODS ====================

    /**
     * Проверка превышения бюджета
     */
    protected function checkBudgetOverrun(DashboardAlert $alert): bool
    {
        if ($alert->target_entity !== 'project' || !$alert->target_entity_id) {
            return false;
        }
        
        $project = Project::find($alert->target_entity_id);
        
        if (!$project) {
            return false;
        }
        
        // Получаем бюджет проекта из контрактов
        $totalBudget = Contract::where('project_id', $project->id)
            ->sum('total_amount');
        
        if ($totalBudget == 0) {
            return false;
        }
        
        // Получаем фактические расходы
        $actualSpending = DB::table('completed_works')
            ->where('project_id', $project->id)
            ->join('completed_work_materials', 'completed_works.id', '=', 'completed_work_materials.completed_work_id')
            ->sum('completed_work_materials.total_amount');
        
        // Процент использования бюджета
        $budgetUsage = ($actualSpending / $totalBudget) * 100;
        
        return $this->compareValues($budgetUsage, $alert->threshold_value, $alert->comparison_operator);
    }

    /**
     * Проверка риска срыва сроков
     */
    protected function checkDeadlineRisk(DashboardAlert $alert): bool
    {
        if ($alert->target_entity !== 'contract' || !$alert->target_entity_id) {
            return false;
        }
        
        $contract = Contract::find($alert->target_entity_id);
        
        if (!$contract || !$contract->end_date) {
            return false;
        }
        
        // Дней до дедлайна
        $daysUntilDeadline = Carbon::now()->diffInDays($contract->end_date, false);
        
        // Процент выполнения контракта
        $completionPercentage = $contract->progress ?? 0;
        
        // Если выполнено менее X% а до дедлайна осталось менее Y дней - риск
        $riskThreshold = $alert->threshold_value ?? 30; // 30 дней по умолчанию
        
        $isRisk = ($daysUntilDeadline < $riskThreshold) && ($completionPercentage < 80);
        
        return $isRisk;
    }

    /**
     * Проверка низких остатков материалов
     */
    protected function checkLowStock(DashboardAlert $alert): bool
    {
        if ($alert->target_entity !== 'material' || !$alert->target_entity_id) {
            return false;
        }
        
        $material = Material::find($alert->target_entity_id);
        
        if (!$material) {
            return false;
        }
        
        // Текущий остаток
        $currentStock = $material->balance ?? 0;
        
        // Пороговое значение
        $threshold = $alert->threshold_value ?? 10;
        
        return $this->compareValues($currentStock, $threshold, $alert->comparison_operator);
    }

    /**
     * Проверка завершения контракта
     */
    protected function checkContractCompletion(DashboardAlert $alert): bool
    {
        if ($alert->target_entity !== 'contract' || !$alert->target_entity_id) {
            return false;
        }
        
        $contract = Contract::find($alert->target_entity_id);
        
        if (!$contract) {
            return false;
        }
        
        $completionPercentage = $contract->progress ?? 0;
        $threshold = $alert->threshold_value ?? 90; // 90% по умолчанию
        
        return $this->compareValues($completionPercentage, $threshold, $alert->comparison_operator);
    }

    /**
     * Проверка просроченных платежей
     */
    protected function checkPaymentOverdue(DashboardAlert $alert): bool
    {
        $organizationId = $alert->organization_id;
        $now = Carbon::now();
        
        $overdueContracts = Contract::where('organization_id', $organizationId)
            ->whereNotNull('payment_due_date')
            ->where('payment_due_date', '<', $now)
            ->where('status', '!=', 'completed')
            ->where('status', '!=', 'paid')
            ->count();
        
        $overdueMaterials = Material::join('projects', 'materials.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $organizationId)
            ->whereNotNull('materials.payment_due_date')
            ->where('materials.payment_due_date', '<', $now)
            ->where('materials.status', '!=', 'paid')
            ->count();
        
        $totalOverdue = $overdueContracts + $overdueMaterials;
        $threshold = $alert->threshold_value ?? 1;
        
        return $this->compareValues($totalOverdue, $threshold, $alert->comparison_operator);
    }

    /**
     * Проверка порога KPI
     */
    protected function checkKPIThreshold(DashboardAlert $alert): bool
    {
        if ($alert->target_entity !== 'user' || !$alert->target_entity_id) {
            return false;
        }
        
        $kpiService = app(KPICalculationService::class);
        
        $from = Carbon::now()->startOfMonth();
        $to = Carbon::now();
        
        try {
            $kpiData = $kpiService->calculateUserKPI(
                $alert->target_entity_id,
                $alert->organization_id,
                $from,
                $to
            );
            
            $kpiValue = $kpiData['overall_kpi'] ?? 0;
            $threshold = $alert->threshold_value ?? 60;
            
            return $this->compareValues($kpiValue, $threshold, $alert->comparison_operator);
        } catch (\Exception $e) {
            Log::error("KPI threshold check failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Проверка кастомных условий
     */
    protected function checkCustomConditions(DashboardAlert $alert): bool
    {
        $conditions = $alert->conditions;
        
        if (!$conditions || !isset($conditions['metric'])) {
            return false;
        }
        
        $metric = $conditions['metric'];
        $value = $this->getCustomMetricValue($metric, $alert);
        
        if ($value === null) {
            return false;
        }
        
        $threshold = $alert->threshold_value;
        $operator = $alert->comparison_operator;
        
        return $this->compareValues($value, $threshold, $operator);
    }
    
    /**
     * Получить значение кастомной метрики
     */
    protected function getCustomMetricValue(string $metric, DashboardAlert $alert)
    {
        switch ($metric) {
            case 'active_projects_count':
                return Project::where('organization_id', $alert->organization_id)
                    ->where('status', 'active')
                    ->count();
                    
            case 'total_contracts_value':
                return Contract::where('organization_id', $alert->organization_id)
                    ->whereIn('status', ['active', 'in_progress'])
                    ->sum('total_amount');
                    
            case 'material_spending_rate':
                $from = Carbon::now()->startOfMonth();
                $to = Carbon::now();
                return DB::table('completed_work_materials')
                    ->join('completed_works', 'completed_work_materials.completed_work_id', '=', 'completed_works.id')
                    ->join('projects', 'completed_works.project_id', '=', 'projects.id')
                    ->where('projects.organization_id', $alert->organization_id)
                    ->whereBetween('completed_works.created_at', [$from, $to])
                    ->sum('completed_work_materials.total_amount');
                    
            case 'average_contract_progress':
                return Contract::where('organization_id', $alert->organization_id)
                    ->whereIn('status', ['active', 'in_progress'])
                    ->avg('progress');
                    
            case 'overdue_contracts_count':
                return Contract::where('organization_id', $alert->organization_id)
                    ->whereNotNull('end_date')
                    ->where('end_date', '<', Carbon::now())
                    ->where('status', '!=', 'completed')
                    ->count();
                    
            default:
                return null;
        }
    }

    /**
     * Сравнить значения с учетом оператора
     */
    protected function compareValues($actual, $threshold, string $operator): bool
    {
        switch ($operator) {
            case 'gt':
            case '>':
                return $actual > $threshold;
            
            case 'gte':
            case '>=':
                return $actual >= $threshold;
            
            case 'lt':
            case '<':
                return $actual < $threshold;
            
            case 'lte':
            case '<=':
                return $actual <= $threshold;
            
            case 'eq':
            case '==':
                return $actual == $threshold;
            
            case 'neq':
            case '!=':
                return $actual != $threshold;
            
            default:
                return false;
        }
    }

    /**
     * Сработать алерт
     */
    protected function triggerAlert(DashboardAlert $alert): void
    {
        // Обновляем статус алерта
        $alert->trigger();
        
        // Отправляем уведомления
        $this->sendAlertNotifications($alert);
        
        // Испускаем событие
        event(new AlertTriggered($alert));
        
        Log::info('Alert triggered', [
            'alert_id' => $alert->id,
            'type' => $alert->alert_type,
            'priority' => $alert->priority,
        ]);
    }

    /**
     * Отправить уведомления об алерте
     */
    protected function sendAlertNotifications(DashboardAlert $alert): void
    {
        $channels = $alert->notification_channels ?? ['in_app'];
        
        foreach ($channels as $channel) {
            try {
                switch ($channel) {
                    case 'email':
                        $this->sendEmailNotification($alert);
                        break;
                    
                    case 'in_app':
                        $this->sendInAppNotification($alert);
                        break;
                    
                    case 'webhook':
                        $this->sendWebhookNotification($alert);
                        break;
                }
            } catch (\Exception $e) {
                Log::error('Failed to send alert notification', [
                    'alert_id' => $alert->id,
                    'channel' => $channel,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Отправить email уведомление
     */
    protected function sendEmailNotification(DashboardAlert $alert): void
    {
        // TODO: Реализовать отправку email после создания системы уведомлений
        Log::info('Email notification queued', ['alert_id' => $alert->id]);
    }

    /**
     * Отправить in-app уведомление
     */
    protected function sendInAppNotification(DashboardAlert $alert): void
    {
        // TODO: Реализовать in-app уведомления
        Log::info('In-app notification queued', ['alert_id' => $alert->id]);
    }

    /**
     * Отправить webhook уведомление
     */
    protected function sendWebhookNotification(DashboardAlert $alert): void
    {
        // TODO: Реализовать webhook уведомления
        Log::info('Webhook notification queued', ['alert_id' => $alert->id]);
    }

    /**
     * Валидация данных алерта
     */
    protected function validateAlertData(array $data): void
    {
        $requiredFields = ['name', 'alert_type', 'comparison_operator'];
        
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                throw new \InvalidArgumentException("Field '{$field}' is required");
            }
        }
        
        $validAlertTypes = [
            'budget_overrun',
            'deadline_risk',
            'low_stock',
            'contract_completion',
            'payment_overdue',
            'kpi_threshold',
            'custom',
        ];
        
        if (!in_array($data['alert_type'], $validAlertTypes)) {
            throw new \InvalidArgumentException("Invalid alert_type: {$data['alert_type']}");
        }
        
        $validOperators = ['gt', 'gte', 'lt', 'lte', 'eq', 'neq', '>', '>=', '<', '<=', '==', '!='];
        
        if (!in_array($data['comparison_operator'], $validOperators)) {
            throw new \InvalidArgumentException("Invalid comparison_operator: {$data['comparison_operator']}");
        }
    }
}

