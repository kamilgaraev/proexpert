<?php

namespace App\BusinessModules\Enterprise\MultiOrganization\Core\Infrastructure\EventHandlers;

use App\BusinessModules\Enterprise\MultiOrganization\Core\Domain\Events\OrganizationDataUpdated;
use App\BusinessModules\Enterprise\MultiOrganization\Reporting\Domain\DataAggregator;
use App\BusinessModules\Enterprise\MultiOrganization\Core\Domain\Models\HoldingAggregate;
use App\Models\OrganizationGroup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class UpdateHoldingMetrics implements ShouldQueue
{
    use InteractsWithQueue;

    private DataAggregator $dataAggregator;

    public function __construct(DataAggregator $dataAggregator)
    {
        $this->dataAggregator = $dataAggregator;
    }

    public function handle(OrganizationDataUpdated $event): void
    {
        try {
            // Очищаем кэш метрик для измененной организации
            $this->dataAggregator->clearOrganizationCache($event->organization->id);
            
            // Если изменения финансовые, очищаем кэш холдинга
            if ($event->isFinancialDataChanged()) {
                $holdingId = $event->getHoldingId();
                
                if ($holdingId) {
                    $this->dataAggregator->clearHoldingCache($holdingId);
                    
                    // Предварительно пересчитываем основные метрики
                    $this->precomputeMetrics($holdingId);
                }
            }

            // Если изменения структурные (добавление/удаление дочерних организаций)
            if ($event->isStructuralChange()) {
                $holdingId = $event->getHoldingId();
                
                if ($holdingId) {
                    // Полная очистка кэша холдинга при структурных изменениях
                    $this->clearAllHoldingCache($holdingId);
                }
            }

            Log::info('Метрики холдинга обновлены', [
                'organization_id' => $event->organization->id,
                'holding_id' => $event->getHoldingId(),
                'changed_fields' => $event->changedFields,
                'is_financial' => $event->isFinancialDataChanged(),
                'is_structural' => $event->isStructuralChange(),
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка обновления метрик холдинга', [
                'organization_id' => $event->organization->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Не бросаем исключение, чтобы не блокировать основной процесс
        }
    }

    /**
     * Предварительный расчет основных метрик для быстрого доступа
     */
    private function precomputeMetrics(int $holdingId): void
    {
        try {
            $group = OrganizationGroup::find($holdingId);
            if (!$group) {
                return;
            }

            $holding = new HoldingAggregate($group);
            
            // Предварительно загружаем в кэш основные метрики
            $holding->getConsolidatedMetrics();
            
            // Предварительно рассчитываем доходы по организациям
            $this->dataAggregator->getRevenueByOrganization($holding);
            
            // Предварительно рассчитываем тренд доходов
            $this->dataAggregator->getMonthlyRevenueTrend($holding);

        } catch (\Exception $e) {
            Log::warning('Ошибка предварительного расчета метрик', [
                'holding_id' => $holdingId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Полная очистка кэша холдинга
     */
    private function clearAllHoldingCache(int $holdingId): void
    {
        try {
            $group = OrganizationGroup::find($holdingId);
            if (!$group) {
                return;
            }

            $holding = new HoldingAggregate($group);
            
            // Очищаем кэш холдинга
            $this->dataAggregator->clearHoldingCache($holdingId);
            
            // Очищаем кэш всех организаций в холдинге
            foreach ($holding->getAllOrganizations() as $organization) {
                $this->dataAggregator->clearOrganizationCache($organization->id);
            }
            
            // Очищаем кэш агрегата
            $holding->clearMetricsCache();

        } catch (\Exception $e) {
            Log::warning('Ошибка очистки кэша холдинга', [
                'holding_id' => $holdingId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Определение приоритета обработки события
     */
    public function viaQueue(): string
    {
        return 'analytics'; // Отдельная очередь для аналитических задач
    }

    /**
     * Количество попыток
     */
    public int $tries = 3;

    /**
     * Таймаут выполнения
     */
    public int $timeout = 120;
}
