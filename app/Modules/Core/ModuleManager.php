<?php

namespace App\Modules\Core;

use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleActivation;
use App\Modules\Contracts\ModuleInterface;
use App\Modules\Events\ModuleActivated;
use App\Modules\Events\ModuleDeactivated;
use App\Services\Logging\LoggingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
class ModuleManager 
{
    protected ModuleRegistry $registry;
    protected AccessController $accessController;
    protected BillingEngine $billingEngine;
    protected LoggingService $logging;
    
    public function __construct(
        ModuleRegistry $registry,
        AccessController $accessController,
        BillingEngine $billingEngine,
        LoggingService $logging
    ) {
        $this->registry = $registry;
        $this->accessController = $accessController;
        $this->billingEngine = $billingEngine;
        $this->logging = $logging;
    }
    
    public function discoverModules(): Collection
    {
        $startTime = microtime(true);
        
        $this->logging->technical('modules.discovery.started');
        
        try {
            // Модули загружаются из кеша/БД без автоматического сканирования
            // Для обновления модулей используйте: php artisan modules:scan
            $modules = $this->registry->getAllModules();
            
            $duration = (microtime(true) - $startTime) * 1000;
            
            $this->logging->business('modules.discovery.completed', [
                'modules_count' => $modules->count(),
                'duration_ms' => $duration,
                'active_modules' => $modules->where('is_active', true)->count(),
                'inactive_modules' => $modules->where('is_active', false)->count()
            ]);
            
            if ($duration > 1000) {
                $this->logging->technical('modules.discovery.slow', [
                    'duration_ms' => $duration,
                    'modules_count' => $modules->count()
                ], 'warning');
            }
            
            return $modules;
            
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            
            $this->logging->technical('modules.discovery.failed', [
                'error' => $e->getMessage(),
                'duration_ms' => $duration
            ], 'error');
            
            throw $e;
        }
    }
    
    public function activateModule(int $organizationId, string $moduleSlug, array $options = []): array
    {
        $startTime = microtime(true);
        
        $this->logging->business('module.activation.started', [
            'organization_id' => $organizationId,
            'module_slug' => $moduleSlug,
            'has_options' => !empty($options)
        ]);
        
        // ДИАГНОСТИКА: Время получения модуля
        $getModuleStart = microtime(true);
        $module = $this->registry->getModule($moduleSlug);
        $getModuleDuration = (microtime(true) - $getModuleStart) * 1000;
        
        $this->logging->technical('module.registry.lookup', [
            'module_slug' => $moduleSlug,
            'found' => $module !== null,
            'duration_ms' => $getModuleDuration
        ]);
        
        if (!$module) {
            $this->logging->business('module.activation.failed', [
                'organization_id' => $organizationId,
                'module_slug' => $moduleSlug,
                'reason' => 'MODULE_NOT_FOUND',
                'duration_ms' => (microtime(true) - $startTime) * 1000
            ]);
            
            return [
                'success' => false,
                'message' => 'Модуль не найден',
                'code' => 'MODULE_NOT_FOUND'
            ];
        }
        
        if (!$module->is_active) {
            $this->logging->business('module.activation.failed', [
                'organization_id' => $organizationId,
                'module_slug' => $moduleSlug,
                'module_id' => $module->id,
                'reason' => 'MODULE_INACTIVE',
                'duration_ms' => (microtime(true) - $startTime) * 1000
            ]);
            
            return [
                'success' => false,
                'message' => 'Модуль недоступен для активации',
                'code' => 'MODULE_INACTIVE'
            ];
        }
        
        // Проверка статуса разработки
        if (!$module->canBeActivatedByStatus()) {
            $statusInfo = $module->getDevelopmentStatusInfo();
            
            $this->logging->business('module.activation.failed', [
                'organization_id' => $organizationId,
                'module_slug' => $moduleSlug,
                'module_id' => $module->id,
                'reason' => 'MODULE_STATUS_NOT_READY',
                'development_status' => $statusInfo['status'],
                'duration_ms' => (microtime(true) - $startTime) * 1000
            ]);
            
            return [
                'success' => false,
                'message' => "Модуль недоступен для активации: {$statusInfo['description']}",
                'code' => 'MODULE_STATUS_NOT_READY',
                'development_status' => $statusInfo
            ];
        }
        
        // ДИАГНОСТИКА: Проверяем, не активирован ли уже
        $accessCheckStart = microtime(true);
        $hasAccess = $this->accessController->hasModuleAccess($organizationId, $moduleSlug);
        $accessCheckDuration = (microtime(true) - $accessCheckStart) * 1000;
        
        $this->logging->technical('module.access.check', [
            'organization_id' => $organizationId,
            'module_slug' => $moduleSlug,
            'has_access' => $hasAccess,
            'duration_ms' => $accessCheckDuration
        ]);
        
        if ($hasAccess) {
            $this->logging->business('module.activation.failed', [
                'organization_id' => $organizationId,
                'module_slug' => $moduleSlug,
                'reason' => 'MODULE_ALREADY_ACTIVE',
                'duration_ms' => (microtime(true) - $startTime) * 1000
            ]);
            
            return [
                'success' => false,
                'message' => 'Модуль уже активирован',
                'code' => 'MODULE_ALREADY_ACTIVE'
            ];
        }
        
        $dependenciesCheckStart = microtime(true);
        $dependenciesCheck = $this->accessController->checkDependencies($organizationId, $module);
        $dependenciesCheckDuration = (microtime(true) - $dependenciesCheckStart) * 1000;
        
        $this->logging->technical('module.dependencies.check', [
            'organization_id' => $organizationId,
            'module_slug' => $moduleSlug,
            'dependencies_check' => $dependenciesCheck,
            'has_missing_dependencies' => !empty($dependenciesCheck['missing_dependencies']),
            'duration_ms' => $dependenciesCheckDuration
        ]);
        
        if (!empty($dependenciesCheck['missing_dependencies'])) {
            $this->logging->business('module.activation.failed', [
                'organization_id' => $organizationId,
                'module_slug' => $moduleSlug,
                'reason' => 'MISSING_DEPENDENCIES',
                'missing_dependencies' => $dependenciesCheck['missing_dependencies'],
                'duration_ms' => (microtime(true) - $startTime) * 1000
            ]);
            
            return [
                'success' => false,
                'message' => 'Не хватает зависимых модулей: ' . implode(', ', $dependenciesCheck['missing_dependencies']),
                'code' => 'MISSING_DEPENDENCIES',
                'missing_dependencies' => $dependenciesCheck['missing_dependencies']
            ];
        }
        
        // ДИАГНОСТИКА: Проверяем конфликты
        $conflictsCheckStart = microtime(true);
        $conflicts = $this->accessController->checkConflicts($organizationId, $module);
        $conflictsCheckDuration = (microtime(true) - $conflictsCheckStart) * 1000;
        
        $this->logging->technical('module.conflicts.check', [
            'organization_id' => $organizationId,
            'module_slug' => $moduleSlug,
            'conflicts' => $conflicts,
            'has_conflicts' => !empty($conflicts),
            'duration_ms' => $conflictsCheckDuration
        ]);
        
        if (!empty($conflicts)) {
            $this->logging->business('module.activation.failed', [
                'organization_id' => $organizationId,
                'module_slug' => $moduleSlug,
                'reason' => 'MODULE_CONFLICTS',
                'conflicts' => $conflicts,
                'duration_ms' => (microtime(true) - $startTime) * 1000
            ]);
            
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
            
            $this->clearOrganizationModuleCaches($organizationId);
            
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
            
            $this->clearOrganizationModuleCaches($organizationId);
            
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
    
    public function activateTrial(int $organizationId, string $moduleSlug): array
    {
        $startTime = microtime(true);
        
        $this->logging->business('module.trial.activation.started', [
            'organization_id' => $organizationId,
            'module_slug' => $moduleSlug
        ]);
        
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
        
        // Проверка статуса разработки
        if (!$module->canBeActivatedByStatus()) {
            $statusInfo = $module->getDevelopmentStatusInfo();
            return [
                'success' => false,
                'message' => "Модуль недоступен для активации: {$statusInfo['description']}",
                'code' => 'MODULE_STATUS_NOT_READY',
                'development_status' => $statusInfo
            ];
        }
        
        if ($module->isFree()) {
            return [
                'success' => false,
                'message' => 'Trial период доступен только для платных модулей',
                'code' => 'TRIAL_NOT_AVAILABLE_FOR_FREE'
            ];
        }
        
        if ($this->hasUsedTrial($organizationId, $module->id)) {
            return [
                'success' => false,
                'message' => 'Trial период уже был использован для этого модуля',
                'code' => 'TRIAL_ALREADY_USED'
            ];
        }
        
        if ($this->accessController->hasModuleAccess($organizationId, $moduleSlug)) {
            return [
                'success' => false,
                'message' => 'Модуль уже активирован',
                'code' => 'MODULE_ALREADY_ACTIVE'
            ];
        }
        
        $pricingConfig = $module->pricing_config ?? [];
        $trialDays = $pricingConfig['trial_days'] ?? 14;
        
        try {
            DB::transaction(function () use ($organizationId, $module, $trialDays) {
                OrganizationModuleActivation::create([
                    'organization_id' => $organizationId,
                    'module_id' => $module->id,
                    'status' => 'trial',
                    'activated_at' => now(),
                    'trial_ends_at' => now()->addDays($trialDays),
                    'expires_at' => now()->addDays($trialDays),
                    'paid_amount' => 0,
                    'module_settings' => [],
                    'usage_stats' => []
                ]);
                
                $this->accessController->clearAccessCache($organizationId);
            });
            
            event(new \App\Modules\Events\TrialActivated($organizationId, $moduleSlug, $trialDays));
            
            $this->clearOrganizationModuleCaches($organizationId);
            
            $duration = (microtime(true) - $startTime) * 1000;
            
            $this->logging->business('module.trial.activation.completed', [
                'organization_id' => $organizationId,
                'module_slug' => $moduleSlug,
                'trial_days' => $trialDays,
                'duration_ms' => $duration
            ]);
            
            return [
                'success' => true,
                'message' => "Trial период модуля '{$module->name}' активирован на {$trialDays} дней",
                'trial_days' => $trialDays,
                'trial_ends_at' => now()->addDays($trialDays)->toISOString()
            ];
            
        } catch (\Exception $e) {
            $this->logging->technical('module.trial.activation.failed', [
                'organization_id' => $organizationId,
                'module_slug' => $moduleSlug,
                'error' => $e->getMessage()
            ], 'error');
            
            return [
                'success' => false,
                'message' => 'Ошибка активации trial периода: ' . $e->getMessage(),
                'code' => 'TRIAL_ACTIVATION_ERROR'
            ];
        }
    }
    
    public function hasUsedTrial(int $organizationId, int $moduleId): bool
    {
        return OrganizationModuleActivation::where('organization_id', $organizationId)
            ->where('module_id', $moduleId)
            ->whereNotNull('trial_ends_at')
            ->exists();
    }
    
    public function convertTrialToPaid(int $organizationId, string $moduleSlug): array
    {
        $module = $this->registry->getModule($moduleSlug);
        
        if (!$module) {
            return [
                'success' => false,
                'message' => 'Модуль не найден',
                'code' => 'MODULE_NOT_FOUND'
            ];
        }
        
        $activation = OrganizationModuleActivation::where('organization_id', $organizationId)
            ->where('module_id', $module->id)
            ->where('status', 'trial')
            ->first();
            
        if (!$activation) {
            return [
                'success' => false,
                'message' => 'Trial период не найден',
                'code' => 'TRIAL_NOT_FOUND'
            ];
        }
        
        $organization = Organization::findOrFail($organizationId);
        
        if (!$this->billingEngine->canAfford($organization, $module)) {
            return [
                'success' => false,
                'message' => 'Недостаточно средств на балансе',
                'code' => 'INSUFFICIENT_BALANCE'
            ];
        }
        
        try {
            DB::transaction(function () use ($activation, $module, $organization) {
                if (!$this->billingEngine->chargeForModule($organization, $module)) {
                    throw new \Exception('Ошибка списания средств');
                }
                
                $pricingConfig = $module->pricing_config ?? [];
                $durationDays = $pricingConfig['duration_days'] ?? 30;
                
                $activation->update([
                    'status' => 'active',
                    'activated_at' => now(),
                    'expires_at' => $module->billing_model === 'subscription' 
                        ? now()->addDays($durationDays) 
                        : null,
                    'paid_amount' => $this->billingEngine->calculateChargeAmount($module)
                ]);
                
                $this->accessController->clearAccessCache($organization->id);
            });
            
            $this->clearOrganizationModuleCaches($organizationId);
            
            $this->logging->business('module.trial.converted', [
                'organization_id' => $organizationId,
                'module_slug' => $moduleSlug,
                'paid_amount' => $activation->paid_amount
            ]);
            
            return [
                'success' => true,
                'message' => "Модуль '{$module->name}' успешно активирован",
                'paid_amount' => $activation->paid_amount
            ];
            
        } catch (\Exception $e) {
            $this->logging->technical('module.trial.conversion.failed', [
                'organization_id' => $organizationId,
                'module_slug' => $moduleSlug,
                'error' => $e->getMessage()
            ], 'error');
            
            return [
                'success' => false,
                'message' => 'Ошибка конвертации trial: ' . $e->getMessage(),
                'code' => 'TRIAL_CONVERSION_ERROR'
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
    
    protected function clearOrganizationModuleCaches(int $organizationId): void
    {
        $cacheKeys = [
            "org_active_modules_{$organizationId}",
            "active_modules_{$organizationId}",
            "modules_with_status_{$organizationId}",  // Кеш списка модулей для UI
        ];
        
        foreach ($cacheKeys as $key) {
            Cache::forget($key);
        }
        
        $organization = Organization::find($organizationId);
        if ($organization) {
            $userIds = $organization->users()->pluck('users.id');
            
            foreach ($userIds as $userId) {
                Cache::forget("user_permissions_full_{$userId}_{$organizationId}");
                Cache::forget("user_permissions_{$userId}_{$organizationId}");
                Cache::forget("user_available_permissions_{$userId}_{$organizationId}");
            }
        }
        
        $this->logging->technical('cache.organization_modules.cleared', [
            'organization_id' => $organizationId,
            'cleared_keys' => count($cacheKeys),
            'cleared_user_caches' => isset($userIds) ? count($userIds) : 0
        ]);
    }
    
    /**
     * Включить/выключить автопродление модуля
     */
    public function toggleAutoRenew(int $organizationId, string $moduleSlug, bool $enabled): array
    {
        $module = $this->registry->getModule($moduleSlug);
        
        if (!$module) {
            return [
                'success' => false,
                'message' => 'Модуль не найден',
                'code' => 'MODULE_NOT_FOUND'
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
        
        // Бесплатные модули не требуют продления
        if ($module->billing_model === 'free') {
            return [
                'success' => false,
                'message' => 'Бесплатные модули не требуют продления',
                'code' => 'FREE_MODULE_NO_RENEWAL'
            ];
        }
        
        // Модули в составе подписки нельзя отдельно управлять
        if ($activation->is_bundled_with_plan) {
            return [
                'success' => false,
                'message' => 'Модуль входит в подписку, управляйте автопродлением подписки',
                'code' => 'BUNDLED_MODULE_NO_CONTROL'
            ];
        }
        
        $activation->update(['is_auto_renew_enabled' => $enabled]);
        
        $this->logging->business('module.auto_renew.toggled', [
            'organization_id' => $organizationId,
            'module_slug' => $moduleSlug,
            'enabled' => $enabled
        ]);
        
        return [
            'success' => true,
            'message' => $enabled ? 'Автопродление включено' : 'Автопродление выключено',
            'is_auto_renew_enabled' => $enabled
        ];
    }
    
    /**
     * Массовое включение/выключение автопродления
     */
    public function bulkToggleAutoRenew(int $organizationId, bool $enabled): array
    {
        $activations = OrganizationModuleActivation::where('organization_id', $organizationId)
            ->where('status', 'active')
            ->where('is_bundled_with_plan', false)
            ->whereHas('module', function($query) {
                $query->where('billing_model', '!=', 'free');
            })
            ->get();
        
        if ($activations->isEmpty()) {
            return [
                'success' => false,
                'message' => 'Нет модулей для изменения автопродления',
                'code' => 'NO_MODULES_TO_UPDATE'
            ];
        }
        
        $updated = 0;
        foreach ($activations as $activation) {
            $activation->update(['is_auto_renew_enabled' => $enabled]);
            $updated++;
        }
        
        $this->logging->business('module.auto_renew.bulk_toggled', [
            'organization_id' => $organizationId,
            'enabled' => $enabled,
            'updated_count' => $updated
        ]);
        
        return [
            'success' => true,
            'message' => $enabled 
                ? "Автопродление включено для {$updated} модулей"
                : "Автопродление выключено для {$updated} модулей",
            'updated_count' => $updated
        ];
    }
}
