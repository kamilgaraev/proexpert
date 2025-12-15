<?php

namespace App\Console\Commands\Billing;

use Illuminate\Console\Command;
use App\Models\OrganizationModuleActivation;
use App\Models\Organization;
use App\Models\Module;
use App\Modules\Core\BillingEngine;
use App\Modules\Core\AccessController;
use App\Services\Logging\LoggingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RenewModulesCommand extends Command
{
    protected $signature = 'modules:renew 
                            {--organization= : ID организации для продления конкретных модулей}
                            {--module= : Slug модуля для продления конкретного модуля}
                            {--dry-run : Показать модули которые будут продлены без реального продления}
                            {--days-ahead=1 : За сколько дней до окончания начинать продление}';

    protected $description = 'Автоматическое продление модулей организаций с включенным автопродлением';

    protected BillingEngine $billingEngine;
    protected AccessController $accessController;
    protected LoggingService $logging;

    public function __construct(
        BillingEngine $billingEngine,
        AccessController $accessController,
        LoggingService $logging
    ) {
        parent::__construct();
        $this->billingEngine = $billingEngine;
        $this->accessController = $accessController;
        $this->logging = $logging;
    }

    public function handle(): int
    {
        $daysAhead = (int) $this->option('days-ahead');
        $organizationId = $this->option('organization');
        $moduleSlug = $this->option('module');
        $isDryRun = $this->option('dry-run');
        
        $this->info("Поиск модулей для автопродления...");
        
        // Ищем активные модули с включенным автопродлением
        $query = OrganizationModuleActivation::with(['organization', 'module'])
            ->where('is_auto_renew_enabled', true)
            ->where('status', 'active')
            ->where('is_bundled_with_plan', false) // Не трогаем модули из подписки
            ->whereNull('cancelled_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays($daysAhead))
            ->where('expires_at', '>', now()->subDay());
            
        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }
        
        if ($moduleSlug) {
            $query->whereHas('module', function($q) use ($moduleSlug) {
                $q->where('slug', $moduleSlug);
            });
        }
        
        $activations = $query->get();
        
        if ($activations->isEmpty()) {
            $this->info('Нет модулей для продления.');
            return Command::SUCCESS;
        }
        
        $this->info("Найдено модулей для продления: " . $activations->count());
        $this->newLine();
        
        if ($isDryRun) {
            $this->warn('Режим DRY RUN - модули не будут продлены');
            $this->newLine();
        }
        
        $table = [];
        $successCount = 0;
        $failedCount = 0;
        $skippedCount = 0;
        
        foreach ($activations as $activation) {
            $organizationName = $activation->organization->name ?? 'N/A';
            $moduleName = $activation->module->name ?? 'N/A';
            $moduleSlug = $activation->module->slug ?? 'N/A';
            $expiresAt = $activation->expires_at->format('Y-m-d H:i');
            $daysLeft = now()->diffInDays($activation->expires_at, false);
            
            $module = $activation->module;
            $organization = $activation->organization;
            
            // Получаем цену модуля
            $pricingConfig = $module->pricing_config ?? [];
            $price = (float) ($pricingConfig['base_price'] ?? 0);
            $durationDays = $pricingConfig['duration_days'] ?? 30;
            
            $table[] = [
                'ID активации' => $activation->id,
                'Организация' => $organizationName,
                'Модуль' => $moduleName,
                'Заканчивается' => $expiresAt,
                'Осталось дней' => round($daysLeft, 1),
                'Цена' => $price . ' руб.',
                'Длительность' => $durationDays . ' дн.',
            ];
            
            if (!$isDryRun) {
                try {
                    $result = $this->renewModule($activation, $organization, $module, $durationDays, $price);
                    
                    if ($result['success']) {
                        $successCount++;
                        $this->info("✓ Модуль '{$moduleName}' продлен для {$organizationName} до {$result['new_expires_at']}");
                    } else {
                        $failedCount++;
                        $this->error("✗ Ошибка продления {$moduleName} для {$organizationName}: {$result['message']}");
                    }
                    
                } catch (\Exception $e) {
                    $failedCount++;
                    $this->error("✗ Исключение при продлении {$moduleName} для {$organizationName}: " . $e->getMessage());
                    
                    Log::error('Module renewal exception', [
                        'activation_id' => $activation->id,
                        'organization_id' => $organization->id,
                        'module_slug' => $moduleSlug,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            } else {
                $skippedCount++;
            }
        }
        
        $this->newLine();
        $this->table(
            ['ID активации', 'Организация', 'Модуль', 'Заканчивается', 'Осталось дней', 'Цена', 'Длительность'],
            $table
        );
        
        $this->newLine();
        
        if ($isDryRun) {
            $this->info("Всего найдено для продления: {$skippedCount}");
        } else {
            $this->info("Успешно продлено: {$successCount}");
            if ($failedCount > 0) {
                $this->error("Ошибок: {$failedCount}");
            }
            
            // Логируем итоги
            $this->logging->business('modules.auto_renew.completed', [
                'total_found' => $activations->count(),
                'success_count' => $successCount,
                'failed_count' => $failedCount,
                'days_ahead' => $daysAhead,
                'organization_id' => $organizationId,
                'module_slug' => $moduleSlug
            ]);
        }
        
        return Command::SUCCESS;
    }
    
    /**
     * Продлить модуль организации
     */
    protected function renewModule(
        OrganizationModuleActivation $activation,
        Organization $organization,
        Module $module,
        int $durationDays,
        float $price
    ): array {
        $this->logging->business('module.renewal.started', [
            'activation_id' => $activation->id,
            'organization_id' => $organization->id,
            'module_slug' => $module->slug,
            'module_name' => $module->name,
            'price' => $price,
            'duration_days' => $durationDays,
            'old_expires_at' => $activation->expires_at,
            'balance_before' => $this->billingEngine->getBalance($organization)
        ]);
        
        // Проверяем баланс
        if (!$this->billingEngine->canAfford($organization, $module)) {
            $currentBalance = $this->billingEngine->getBalance($organization);
            
            $this->logging->business('module.renewal.failed', [
                'activation_id' => $activation->id,
                'organization_id' => $organization->id,
                'module_slug' => $module->slug,
                'reason' => 'insufficient_balance',
                'required_amount' => $price,
                'current_balance' => $currentBalance
            ]);
            
            // Отключаем автопродление при недостатке средств
            $activation->update(['is_auto_renew_enabled' => false]);
            
            return [
                'success' => false,
                'message' => "Недостаточно средств на балансе ({$currentBalance} руб., требуется {$price} руб.). Автопродление отключено."
            ];
        }
        
        try {
            return DB::transaction(function () use ($activation, $organization, $module, $durationDays, $price) {
                // Списываем средства
                if ($price > 0) {
                    $charged = $this->billingEngine->chargeForModule($organization, $module);
                    
                    if (!$charged) {
                        throw new \Exception('Ошибка списания средств');
                    }
                }
                
                // Вычисляем новую дату истечения
                // Если модуль уже истёк, продлеваем с текущей даты
                // Если ещё не истёк, продлеваем с даты истечения
                $newExpiresAt = $activation->expires_at->isFuture()
                    ? $activation->expires_at->copy()->addDays($durationDays)
                    : now()->addDays($durationDays);
                
                // Обновляем активацию
                $activation->update([
                    'expires_at' => $newExpiresAt,
                    'next_billing_date' => $newExpiresAt,
                    'paid_amount' => $price,
                    'status' => 'active'
                ]);
                
                // Очищаем кэш доступа
                $this->accessController->clearAccessCache($organization->id);
                
                $this->logging->business('module.renewed', [
                    'activation_id' => $activation->id,
                    'organization_id' => $organization->id,
                    'module_slug' => $module->slug,
                    'module_name' => $module->name,
                    'amount_charged' => $price,
                    'new_expires_at' => $newExpiresAt,
                    'duration_days' => $durationDays,
                    'renewal_type' => 'automatic',
                    'balance_after' => $this->billingEngine->getBalance($organization)
                ]);
                
                $this->logging->audit('module.renewed', [
                    'activation_id' => $activation->id,
                    'organization_id' => $organization->id,
                    'module_slug' => $module->slug,
                    'amount_charged' => $price,
                    'performed_by' => 'system_auto_renew',
                    'new_expires_at' => $newExpiresAt
                ]);
                
                return [
                    'success' => true,
                    'message' => "Модуль '{$module->name}' успешно продлен",
                    'new_expires_at' => $newExpiresAt->format('Y-m-d H:i')
                ];
            });
            
        } catch (\Exception $e) {
            $this->logging->business('module.renewal.failed', [
                'activation_id' => $activation->id,
                'organization_id' => $organization->id,
                'module_slug' => $module->slug,
                'reason' => 'exception',
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'Ошибка при продлении: ' . $e->getMessage()
            ];
        }
    }
}

