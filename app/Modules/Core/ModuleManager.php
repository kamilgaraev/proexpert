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
        app(ModuleScanner::class)->scanAndRegister();
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
        
        // Проверяем баланс
        if (!$this->billingEngine->canAfford($organization, $module)) {
            return [
                'success' => false,
                'message' => 'Недостаточно средств на балансе',
                'code' => 'INSUFFICIENT_BALANCE'
            ];
        }
        
        try {
            DB::transaction(function () use ($organizationId, $module, $organization, $options) {
                // Списываем деньги
                if (!$this->billingEngine->chargeForModule($organization, $module)) {
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
                        'expires_at' => $module->billing_model === 'subscription' 
                            ? now()->addDays($durationDays) 
                            : null,
                        'status' => 'active',
                        'paid_amount' => $this->billingEngine->calculateChargeAmount($module),
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
}
