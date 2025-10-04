<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\BusinessModules\Features\AdvancedDashboard\Services\AlertsService;
use App\Services\LogService;

/**
 * Команда для проверки алертов Advanced Dashboard
 * 
 * Запускается по расписанию (каждые 5-15 минут)
 */
class CheckDashboardAlerts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dashboard:check-alerts
                          {--organization= : ID организации для проверки (опционально)}
                          {--force : Игнорировать cooldown и проверить все алерты}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Проверить активные алерты дашборда и отправить уведомления при срабатывании';

    protected AlertsService $alertsService;

    public function __construct(AlertsService $alertsService)
    {
        parent::__construct();
        $this->alertsService = $alertsService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $organizationId = $this->option('organization') ? (int) $this->option('organization') : null;
        
        $this->info('🔍 Начинаем проверку алертов...');
        
        if ($organizationId) {
            $this->info("📍 Организация: {$organizationId}");
        } else {
            $this->info('📍 Все организации');
        }
        
        try {
            $stats = $this->alertsService->checkAllAlerts($organizationId);
            
            $this->newLine();
            $this->info('✅ Проверка завершена!');
            $this->table(
                ['Метрика', 'Значение'],
                [
                    ['Проверено алертов', $stats['checked']],
                    ['Сработало', $stats['triggered']],
                    ['Ошибок', $stats['errors']],
                ]
            );
            
            LogService::info('Dashboard alerts checked', array_merge($stats, [
                'organization_id' => $organizationId,
            ]));
            
            if ($stats['triggered'] > 0) {
                $this->warn("⚠️  Сработало {$stats['triggered']} алерт(ов)!");
            }
            
            if ($stats['errors'] > 0) {
                $this->error("❌ Ошибок: {$stats['errors']}");
                return self::FAILURE;
            }
            
            return self::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error('❌ Ошибка при проверке алертов: ' . $e->getMessage());
            
            LogService::error('Dashboard alerts check failed', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return self::FAILURE;
        }
    }
}

