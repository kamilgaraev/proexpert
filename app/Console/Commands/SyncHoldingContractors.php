<?php

namespace App\Console\Commands;

use App\BusinessModules\Core\MultiOrganization\Services\HoldingContractorSyncService;
use App\Models\Organization;
use App\Modules\Core\AccessController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Команда для миграции и синхронизации подрядчиков в холдингах
 * 
 * Использование:
 * php artisan multiorg:sync-holding-contractors --all
 * php artisan multiorg:sync-holding-contractors --organization=14
 * php artisan multiorg:sync-holding-contractors --dry-run
 */
class SyncHoldingContractors extends Command
{
    protected $signature = 'multiorg:sync-holding-contractors
                            {--organization= : ID конкретной организации для синхронизации}
                            {--all : Синхронизировать все холдинги}
                            {--dry-run : Режим проверки без изменений}
                            {--force : Пересоздать подрядчиков даже если они существуют}';

    protected $description = 'Синхронизирует подрядчиков для организаций в холдингах';

    protected HoldingContractorSyncService $contractorSync;
    protected AccessController $accessController;

    public function __construct(
        HoldingContractorSyncService $contractorSync,
        AccessController $accessController
    ) {
        parent::__construct();
        $this->contractorSync = $contractorSync;
        $this->accessController = $accessController;
    }

    public function handle(): int
    {
        $this->info('🚀 Начинаю синхронизацию подрядчиков холдинга...');
        $this->newLine();

        $isDryRun = $this->option('dry-run');
        $organizationId = $this->option('organization');
        $all = $this->option('all');

        if ($isDryRun) {
            $this->warn('⚠️  Режим DRY-RUN: изменения не будут сохранены');
            $this->newLine();
        }

        // Получаем организации для обработки
        $organizations = $this->getOrganizationsToProcess($organizationId, $all);

        if ($organizations->isEmpty()) {
            $this->error('❌ Не найдено организаций для обработки');
            return self::FAILURE;
        }

        $this->info("📋 Найдено организаций: {$organizations->count()}");
        $this->newLine();

        $totalStats = [
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        $bar = $this->output->createProgressBar($organizations->count());
        $bar->start();

        foreach ($organizations as $org) {
            if (!$isDryRun) {
                $result = $this->contractorSync->syncHoldingContractors($org->id);
            } else {
                $result = $this->simulateSync($org);
            }

            $totalStats['processed']++;
            $totalStats['created'] += $result['created'] ?? 0;
            $totalStats['updated'] += $result['updated'] ?? 0;
            $totalStats['skipped'] += $result['skipped'] ?? 0;
            
            if (isset($result['errors']) && count($result['errors']) > 0) {
                $totalStats['errors'] += count($result['errors']);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Выводим итоговую статистику
        $this->displayResults($totalStats, $isDryRun);

        return self::SUCCESS;
    }

    protected function getOrganizationsToProcess($organizationId, $all)
    {
        if ($organizationId) {
            $org = Organization::find($organizationId);
            
            if (!$org) {
                $this->error("❌ Организация с ID {$organizationId} не найдена");
                return collect();
            }

            if (!$this->hasMultiOrgAccess($org->id)) {
                $this->error("❌ У организации {$org->name} нет доступа к модулю multi-organization");
                return collect();
            }

            return collect([$org]);
        }

        if ($all) {
            $allOrgs = Organization::where(function($query) {
                    $query->where('is_holding', true)
                          ->orWhereNotNull('parent_organization_id');
                })
                ->where('is_active', true)
                ->get();
            
            // Фильтруем только те, у которых есть доступ к модулю
            return $allOrgs->filter(function($org) {
                return $this->hasMultiOrgAccess($org->id);
            });
        }

        $this->error('❌ Укажите --organization=ID или --all');
        return collect();
    }

    protected function hasMultiOrgAccess(int $organizationId): bool
    {
        try {
            return $this->accessController->hasModuleAccess($organizationId, 'multi-organization');
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function simulateSync(Organization $org): array
    {
        $result = [
            'organization_id' => $org->id,
            'organization_name' => $org->name,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
        ];

        // Симуляция для холдинга
        if ($org->is_holding) {
            $childCount = Organization::where('parent_organization_id', $org->id)
                ->where('is_active', true)
                ->count();
            
            // 2 подрядчика на каждую дочернюю (туда и обратно)
            $result['created'] = $childCount * 2;
        }

        // Симуляция для дочерней
        if ($org->parent_organization_id) {
            // 2 подрядчика (головная для дочерней, дочерняя для головной)
            $result['created'] += 2;

            // Siblings
            $siblingCount = Organization::where('parent_organization_id', $org->parent_organization_id)
                ->where('id', '!=', $org->id)
                ->where('is_active', true)
                ->count();
            
            // 2 подрядчика на каждую sibling (туда и обратно)
            $result['created'] += $siblingCount * 2;
        }

        return $result;
    }

    protected function displayResults(array $stats, bool $isDryRun): void
    {
        $this->info('✅ Синхронизация завершена!');
        $this->newLine();

        $this->table(
            ['Метрика', 'Значение'],
            [
                ['Обработано организаций', $stats['processed']],
                ['Создано подрядчиков', $stats['created']],
                ['Обновлено подрядчиков', $stats['updated']],
                ['Пропущено', $stats['skipped']],
                ['Ошибок', $stats['errors']],
            ]
        );

        if ($isDryRun) {
            $this->newLine();
            $this->warn('⚠️  Это был режим DRY-RUN. Для применения изменений запустите команду без флага --dry-run');
        }

        if ($stats['errors'] > 0) {
            $this->newLine();
            $this->error("⚠️  Обнаружены ошибки. Проверьте логи: tail -f storage/logs/laravel.log");
        }
    }
}

