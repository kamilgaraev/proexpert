<?php

namespace App\Modules\Core;

use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleActivation;
use App\Modules\Contracts\ModuleInterface;
use App\Modules\Events\ModuleActivated;
use App\Modules\Events\ModuleDeactivated;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
class ModuleManager 
{
    protected ModuleRegistry $registry;
    protected AccessController $accessController;
    protected BillingEngine $billingEngine;
    
    public function __construct(
        ModuleRegistry $registry,
        AccessController $accessController,
        BillingEngine $billingEngine
    ) {
        $this->registry = $registry;
        $this->accessController = $accessController;
        $this->billingEngine = $billingEngine;
    }
    
    public function discoverModules(): Collection
    {
        // Модули загружаются из кеша/БД без автоматического сканирования
        // Для обновления модулей используйте: php artisan modules:scan
        return $this->registry->getAllModules();
    }
    
    public function activateModule(int $organizationId, string $moduleSlug, array $options = []): array
    {
        $module = $this->registry->getModule($moduleSlug);
        
        if (!$module) {
            return [
                'success' => false,
                'message' => 'Модуль не найден',
                'code' => 'MODULE_NOT_FOUND'
            ];
        }
        
        if (!$module->is_active) {
            return [
                'success' => false,
                'message' => 'Модуль недоступен для активации',
                'code' => 'MODULE_INACTIVE'
            ];
        }
        
        // Проверяем, не активирован ли уже
        if ($this->accessController->hasModuleAccess($organizationId, $moduleSlug)) {
            return [
                'success' => false,
                'message' => 'Модуль уже активирован',
                'code' => 'MODULE_ALREADY_ACTIVE'
            ];
        }
        
        // Проверяем зависимости
        $missingDependencies = $this->accessController->checkDependencies($organizationId, $module);
        if (!empty($missingDependencies)) {
            return [
                'success' => false,
                'message' => 'Не хватает зависимых модулей: ' . implode(', ', $missingDependencies),
                'code' => 'MISSING_DEPENDENCIES',
                'missing_dependencies' => $missingDependencies
            ];
        }
        
        // Проверяем конфликты
        $conflicts = $this->accessController->checkConflicts($organizationId, $module);
        if (!empty($conflicts)) {
            return [
                'success' => false,
                'message' => 'Конфликт с активными модулями: ' . implode(', ', $conflicts),
                'code' => 'MODULE_CONFLICTS',
                'conflicts' => $conflicts
            ];
        }
        
        $organization = Organization::findOrFail($organizationId);
        
        // Проверяем баланс только для платных модулей
        if (!$module->isFree() && !$this->billingEngine->canAfford($organization, $module)) {
            return [
                'success' => false,
                'message' => 'Недостаточно средств на балансе',
                'code' => 'INSUFFICIENT_BALANCE'
            ];
        }
        
        try {
            DB::transaction(function () use ($organizationId, $module, $organization, $options) {
                // Списываем деньги только для платных модулей
                if (!$module->isFree() && !$this->billingEngine->chargeForModule($organization, $module)) {
                    throw new \Exception('Ошибка списания средств');
                }
                
                // Создаем активацию
                $pricingConfig = $module->pricing_config ?? [];
                $durationDays = $pricingConfig['duration_days'] ?? 30;
                
                OrganizationModuleActivation::updateOrCreate(
                    [
                        'organization_id' => $organizationId,
                        'module_id' => $module->id,
                    ],
                    [
                        'activated_at' => now(),
                        'expires_at' => $module->isFree() 
                            ? null  // Бесплатные модули не имеют срока истечения
                            : ($module->billing_model === 'subscription' 
                                ? now()->addDays($durationDays) 
                                : null),
                        'status' => 'active',
                        'paid_amount' => $module->isFree() 
                            ? 0 
                            : $this->billingEngine->calculateChargeAmount($module),
                        'module_settings' => $options['settings'] ?? [],
                        'usage_stats' => []
                    ]
                );
                
                // Очищаем кэш доступа
                $this->accessController->clearAccessCache($organizationId);
            });
            
            event(new ModuleActivated($organizationId, $moduleSlug));
            
            return [
                'success' => true,
                'message' => "Модуль '{$module->name}' успешно активирован",
                'module' => $module
            ];
            
        } catch (\Exception $e) {
            Log::error('Module activation failed', [
                'organization_id' => $organizationId,
                'module_slug' => $moduleSlug,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'Ошибка активации модуля: ' . $e->getMessage(),
                'code' => 'ACTIVATION_ERROR'
            ];
        }
    }
    
    public function deactivationPreview(int $organizationId, string $moduleSlug): array
    {
        $module = $this->registry->getModule($moduleSlug);
        
        if (!$module) {
            return [
                'success' => false,
                'message' => 'Модуль не найден',
                'code' => 'MODULE_NOT_FOUND'
            ];
        }
        
        if (!$module->can_deactivate) {
            return [
                'success' => false,
                'message' => 'Данный модуль нельзя деактивировать, так как он является системным',
                'code' => 'MODULE_CANNOT_BE_DEACTIVATED'
            ];
        }
        
        $activation = OrganizationModuleActivation::where('organization_id', $organizationId)
            ->where('module_id', $module->id)
            ->where('status', 'active')
            ->first();
            
        if (!$activation) {
            return [
                'success' => false,
                'message' => 'Модуль не активирован',
                'code' => 'MODULE_NOT_ACTIVE'
            ];
        }

        // Рассчитываем возможный возврат
        $refundAmount = 0;
        if ($activation->expires_at) {
            $refundAmount = $this->billingEngine->calculateRefundAmount(
                $module, 
                $activation->activated_at, 
                $activation->expires_at
            );
        }

        // Проверяем зависимые модули
        $dependentModules = $this->findDependentModules($organizationId, $moduleSlug);
        $canDeactivate = empty($dependentModules);

        // Собираем информацию о потерях
        $lossesInfo = [
            'features' => $module->features ?? [],
            'permissions' => $module->permissions ?? [],
            'functionality' => $this->getModuleFunctionality($module),
        ];

        $organization = Organization::findOrFail($organizationId);
        $currentBalance = $this->billingEngine->getBalance($organization);

        return [
            'success' => true,
            'preview' => [
                'module' => [
                    'name' => $module->name,
                    'slug' => $module->slug,
                    'description' => $module->description,
                    'type' => $module->type,
                    'billing_model' => $module->billing_model,
                    'icon' => $module->icon
                ],
                'activation' => [
                    'activated_at' => $activation->activated_at,
                    'expires_at' => $activation->expires_at,
                    'status' => $activation->status,
                    'days_used' => $activation->activated_at->diffInDays(now()),
                    'days_remaining' => $activation->expires_at ? max(0, now()->diffInDays($activation->expires_at, false)) : 0
                ],
                'financial_impact' => [
                    'refund_amount' => $refundAmount,
                    'currency' => $module->getCurrency(),
                    'current_balance' => $currentBalance,
                    'balance_after_refund' => $currentBalance + $refundAmount
                ],
                'what_you_lose' => $lossesInfo,
                'dependent_modules' => $dependentModules,
                'warnings' => $this->getDeactivationWarnings($module, $dependentModules),
                'can_proceed' => $canDeactivate
            ],
            'message' => $canDeactivate 
                ? ($refundAmount > 0 
                    ? "При отмене модуля '{$module->name}' вам будет возвращено {$refundAmount} руб." 
                    : "Модуль '{$module->name}' можно отменить без возврата средств.")
                : "Модуль '{$module->name}' нельзя отменить, так как от него зависят другие активные модули."
        ];
    }

    public function deactivateModule(int $organizationId, string $moduleSlug, bool $withRefund = false): array
    {
        $module = $this->registry->getModule($moduleSlug);
        
        if (!$module) {
            return [
                'success' => false,
                'message' => 'Модуль не найден',
                'code' => 'MODULE_NOT_FOUND'
            ];
        }
        
        if (!$module->can_deactivate) {
            return [
                'success' => false,
                'message' => 'Данный модуль нельзя деактивировать, так как он является системным',
                'code' => 'MODULE_CANNOT_BE_DEACTIVATED'
            ];
        }
        
        $activation = OrganizationModuleActivation::where('organization_id', $organizationId)
            ->where('module_id', $module->id)
            ->where('status', 'active')
            ->first();
            
        if (!$activation) {
            return [
                'success' => false,
                'message' => 'Модуль не активирован',
                'code' => 'MODULE_NOT_ACTIVE'
            ];
        }
        
        try {
            DB::transaction(function () use ($activation, $module, $organizationId, $withRefund) {
                // Рассчитываем возврат, если нужен
                $refundAmount = 0;
                if ($withRefund && $activation->expires_at) {
                    $refundAmount = $this->billingEngine->calculateRefundAmount(
                        $module, 
                        $activation->activated_at, 
                        $activation->expires_at
                    );
                    
                    if ($refundAmount > 0) {
                        $organization = Organization::findOrFail($organizationId);
                        $this->billingEngine->refundModule(
                            $organization, 
                            $module, 
                            $refundAmount, 
                            "Отмена модуля '{$module->name}'"
                        );
                    }
                }
                
                // Деактивируем модуль
                $activation->update([
                    'status' => 'suspended',
                    'cancelled_at' => now()
                ]);
                
                // Очищаем кэш доступа
                $this->accessController->clearAccessCache($organizationId);
            });
            
            event(new ModuleDeactivated($organizationId, $moduleSlug));
            
            return [
                'success' => true,
                'message' => "Модуль '{$module->name}' деактивирован",
                'refund_amount' => $refundAmount ?? 0
            ];
            
        } catch (\Exception $e) {
            Log::error('Module deactivation failed', [
                'organization_id' => $organizationId,
                'module_slug' => $moduleSlug,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'Ошибка деактивации модуля: ' . $e->getMessage(),
                'code' => 'DEACTIVATION_ERROR'
            ];
        }
    }
    
    public function hasAccess(int $organizationId, string $moduleSlug): bool
    {
        return $this->accessController->hasModuleAccess($organizationId, $moduleSlug);
    }
    
    public function getOrganizationModules(int $organizationId): Collection
    {
        return $this->accessController->getActiveModules($organizationId);
    }
    
    public function getAllAvailableModules(): Collection
    {
        return $this->registry->getAllModules();
    }
    
    public function getModulesByType(string $type): Collection
    {
        return $this->registry->getModulesByType($type);
    }
    
    protected function findDependentModules(int $organizationId, string $moduleSlug): array
    {
        $dependentModules = [];
        $activeModules = $this->getOrganizationModules($organizationId);
        
        foreach ($activeModules as $activeModule) {
            if ($activeModule->dependencies && in_array($moduleSlug, $activeModule->dependencies)) {
                $dependentModules[] = [
                    'name' => $activeModule->name,
                    'slug' => $activeModule->slug,
                    'type' => $activeModule->type,
                    'description' => $activeModule->description
                ];
            }
        }
        
        return $dependentModules;
    }
    
    protected function getModuleFunctionality(Module $module): array
    {
        $functionality = [];
        
        // Определяем ключевую функциональность по типу модуля
        switch ($module->slug) {
            case 'project-management':
                $functionality = [
                    'Создание и управление проектами',
                    'Назначение прорабов на объекты',
                    'Аналитика по проектам',
                    'Управление материалами проектов'
                ];
                break;
                
            case 'contract-management':
                $functionality = [
                    'Управление контрактами и договорами',
                    'Создание актов выполненных работ',
                    'Управление платежами',
                    'Экспорт документов в PDF/Excel'
                ];
                break;
                
            case 'workflow-management':
                $functionality = [
                    'Система заявок с объектов',
                    'Управление выполненными работами',
                    'Уведомления по заявкам',
                    'Статистика рабочих процессов'
                ];
                break;
                
            case 'multi-organization':
                $functionality = [
                    'Управление дочерними организациями',
                    'Иерархическая структура доступа',
                    'Консолидированная отчетность',
                    'Создание корпоративных сайтов'
                ];
                break;
                
            default:
                // Используем features из модуля или общее описание
                $functionality = $module->features ?? ['Базовая функциональность модуля'];
        }
        
        return $functionality;
    }
    
    protected function getDeactivationWarnings(Module $module, array $dependentModules): array
    {
        $warnings = [];
        
        if (!empty($dependentModules)) {
            $moduleNames = array_column($dependentModules, 'name');
            $warnings[] = [
                'type' => 'dependencies',
                'severity' => 'error',
                'message' => 'Невозможно отключить модуль, так как от него зависят: ' . implode(', ', $moduleNames)
            ];
        }
        
        if ($module->billing_model === 'subscription' && $module->getPrice() > 0) {
            $warnings[] = [
                'type' => 'billing',
                'severity' => 'info',
                'message' => 'При отмене подписки на модуль будет произведен пропорциональный возврат средств'
            ];
        }
        
        if ($module->type === 'core') {
            $warnings[] = [
                'type' => 'system',
                'severity' => 'warning', 
                'message' => 'Отключение системного модуля может повлиять на работу других компонентов'
            ];
        }
        
        // Специальные предупреждения для конкретных модулей
        switch ($module->slug) {
            case 'project-management':
                $warnings[] = [
                    'type' => 'data_loss',
                    'severity' => 'warning',
                    'message' => 'Доступ к данным проектов и назначениям прорабов будет ограничен'
                ];
                break;
                
            case 'multi-organization':
                $warnings[] = [
                    'type' => 'access_loss',
                    'severity' => 'warning',
                    'message' => 'Потеряете доступ к управлению дочерними организациями и консолидированной отчетности'
                ];
                break;
        }
        
        return $warnings;
    }
}
