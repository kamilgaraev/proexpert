<?php

namespace App\Modules\Services;

use App\Models\Organization;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use App\Modules\Core\ModuleManager;
use App\Modules\Core\AccessController;
use App\Modules\Events\ModuleActivated;
use App\Modules\Events\ModuleDeactivated;
use Illuminate\Support\Facades\Log;
class ModuleActivationService
{
    protected ModuleManager $moduleManager;
    protected AccessController $accessController;
    
    public function __construct(ModuleManager $moduleManager, AccessController $accessController)
    {
        $this->moduleManager = $moduleManager;
        $this->accessController = $accessController;
    }
    
    public function activateModule(int $organizationId, string $moduleSlug, array $options = []): array
    {
        return $this->moduleManager->activateModule($organizationId, $moduleSlug, $options);
    }
    
    public function deactivateModule(int $organizationId, string $moduleSlug, bool $withRefund = false): array
    {
        return $this->moduleManager->deactivateModule($organizationId, $moduleSlug, $withRefund);
    }
    
    public function getActivationPreview(int $organizationId, string $moduleSlug): array
    {
        $module = Module::where('slug', $moduleSlug)->first();
        
        if (!$module) {
            return [
                'success' => false,
                'message' => 'Модуль не найден'
            ];
        }
        
        // Получаем данные модуля без class_name
        $moduleData = $module->toPublicArray();
        
        // Проверяем доступность и зависимости
        $checks = $this->accessController->checkDependencies($organizationId, $module);
        $organization = Organization::findOrFail($organizationId);
        
        // Для бесплатных модулей не проверяем баланс
        $canAfford = $module->isFree() ? true : app(\App\Modules\Core\BillingEngine::class)->canAfford($organization, $module);
        
        // Добавляем информацию о том, что модуль бесплатный
        $moduleData['is_free'] = $module->isFree();
        $moduleData['requires_payment'] = !$module->isFree();
        
        return [
            'success' => true,
            'module' => $moduleData,
            'checks' => array_merge($checks, ['can_afford' => $canAfford]),
            'can_activate' => empty($checks['missing_dependencies']) && 
                             empty($checks['conflicts']) && 
                             $canAfford && 
                             !$checks['is_already_active']
        ];
    }
    
    public function renewModule(int $organizationId, string $moduleSlug, int $additionalDays = 30): array
    {
        $module = Module::where('slug', $moduleSlug)->first();
        
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
        
        // Бесплатные модули нельзя продлить
        if ($module->billing_model === 'free') {
            return [
                'success' => false,
                'message' => 'Бесплатные модули не требуют продления',
                'code' => 'FREE_MODULE_CANNOT_RENEW'
            ];
        }
        
        try {
            $newExpirationDate = ($activation->expires_at && $activation->expires_at->isFuture())
                ? $activation->expires_at->addDays($additionalDays)
                : now()->addDays($additionalDays);
                
            $activation->update([
                'expires_at' => $newExpirationDate,
                'next_billing_date' => $newExpirationDate
            ]);
            
            $this->accessController->clearAccessCache($organizationId);
            
            return [
                'success' => true,
                'message' => "Модуль '{$module->name}' продлен до {$newExpirationDate->format('d.m.Y')}",
                'new_expiry_date' => $newExpirationDate
            ];
            
        } catch (\Exception $e) {
            Log::error('Module renewal failed', [
                'organization_id' => $organizationId,
                'module_slug' => $moduleSlug,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'Ошибка продления модуля: ' . $e->getMessage(),
                'code' => 'RENEWAL_ERROR'
            ];
        }
    }
    
    public function getExpiringModules(int $organizationId, int $daysAhead = 7): array
    {
        $expiringActivations = OrganizationModuleActivation::with('module')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays($daysAhead)])
            ->get();
            
        return $expiringActivations->map(function ($activation) {
            return [
                'module_name' => $activation->module->name,
                'module_slug' => $activation->module->slug,
                'expires_at' => $activation->expires_at,
                'days_until_expiration' => $activation->getDaysUntilExpiration(),
                'can_renew' => $activation->module->isSubscription()
            ];
        })->toArray();
    }
    
    public function bulkActivateModules(int $organizationId, array $moduleSlugs): array
    {
        $results = [];
        $successful = 0;
        $failed = 0;
        
        foreach ($moduleSlugs as $slug) {
            $result = $this->activateModule($organizationId, $slug);
            $results[$slug] = $result;
            
            if ($result['success']) {
                $successful++;
            } else {
                $failed++;
            }
        }
        
        return [
            'success' => $failed === 0,
            'message' => "Активировано: {$successful}, ошибок: {$failed}",
            'results' => $results,
            'summary' => [
                'total' => count($moduleSlugs),
                'successful' => $successful,
                'failed' => $failed
            ]
        ];
    }
}
