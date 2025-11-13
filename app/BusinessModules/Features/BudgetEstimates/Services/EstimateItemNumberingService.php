<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для управления автоматической нумерацией позиций сметы
 * 
 * Функционал:
 * - Автоматическая нумерация позиций в рамках секции (1, 2, 3...)
 * - Пересчет номеров при перемещении позиций
 * - Поддержка глобальной нумерации (сквозная по всей смете)
 * - Поддержка нумерации в рамках секции
 */
class EstimateItemNumberingService
{
    /**
     * Режимы нумерации
     */
    public const NUMBERING_GLOBAL = 'global';      // Сквозная нумерация по всей смете (1, 2, 3...)
    public const NUMBERING_BY_SECTION = 'section'; // Нумерация в рамках каждой секции (Секция 1: 1, 2, 3; Секция 2: 1, 2, 3...)
    public const NUMBERING_HIERARCHICAL = 'hierarchical'; // Иерархическая (1.1, 1.2, 2.1, 2.2...)

    /**
     * Генерировать номер для новой позиции
     * 
     * @param int $estimateId ID сметы
     * @param int|null $sectionId ID секции (null для позиций без секции)
     * @param string $mode Режим нумерации (global, section, hierarchical)
     */
    public function generatePositionNumber(
        int $estimateId, 
        ?int $sectionId = null, 
        string $mode = self::NUMBERING_BY_SECTION
    ): string {
        switch ($mode) {
            case self::NUMBERING_GLOBAL:
                return $this->generateGlobalNumber($estimateId);
                
            case self::NUMBERING_BY_SECTION:
                return $this->generateSectionNumber($estimateId, $sectionId);
                
            case self::NUMBERING_HIERARCHICAL:
                return $this->generateHierarchicalNumber($estimateId, $sectionId);
                
            default:
                throw new \InvalidArgumentException("Неизвестный режим нумерации: {$mode}");
        }
    }

    /**
     * Сквозная нумерация по всей смете
     */
    protected function generateGlobalNumber(int $estimateId): string
    {
        // PostgreSQL не поддерживает UNSIGNED, используем INTEGER
        $driver = config('database.default');
        $connection = config("database.connections.{$driver}.driver");
        
        $castType = ($connection === 'pgsql') ? 'INTEGER' : 'UNSIGNED';
        
        $maxNumber = EstimateItem::where('estimate_id', $estimateId)
            ->selectRaw("MAX(CAST(position_number AS {$castType})) as max_num")
            ->value('max_num');

        return (string) (($maxNumber ?? 0) + 1);
    }

    /**
     * Нумерация в рамках секции
     */
    protected function generateSectionNumber(int $estimateId, ?int $sectionId): string
    {
        // PostgreSQL не поддерживает UNSIGNED, используем INTEGER
        $driver = config('database.default');
        $connection = config("database.connections.{$driver}.driver");
        
        $castType = ($connection === 'pgsql') ? 'INTEGER' : 'UNSIGNED';
        
        if ($sectionId === null) {
            // Позиции без секции - нумеруем отдельно
            $maxNumber = EstimateItem::where('estimate_id', $estimateId)
                ->whereNull('estimate_section_id')
                ->selectRaw("MAX(CAST(position_number AS {$castType})) as max_num")
                ->value('max_num');
        } else {
            // Позиции в рамках секции
            $maxNumber = EstimateItem::where('estimate_id', $estimateId)
                ->where('estimate_section_id', $sectionId)
                ->selectRaw("MAX(CAST(position_number AS {$castType})) as max_num")
                ->value('max_num');
        }

        return (string) (($maxNumber ?? 0) + 1);
    }

    /**
     * Иерархическая нумерация (номер секции + номер позиции)
     * Например: 1.1, 1.2, 2.1, 2.2, 2.3
     */
    protected function generateHierarchicalNumber(int $estimateId, ?int $sectionId): string
    {
        if ($sectionId === null) {
            // Позиции без секции
            return $this->generateSectionNumber($estimateId, null);
        }

        $section = EstimateSection::find($sectionId);
        if (!$section) {
            throw new \InvalidArgumentException("Секция {$sectionId} не найдена");
        }

        $sectionNumber = $section->section_number;
        $positionInSection = $this->generateSectionNumber($estimateId, $sectionId);

        return "{$sectionNumber}.{$positionInSection}";
    }

    /**
     * Пересчитать номера всех позиций сметы
     * 
     * @param int $estimateId ID сметы
     * @param string $mode Режим нумерации
     */
    public function recalculateAllItemNumbers(int $estimateId, string $mode = self::NUMBERING_BY_SECTION): void
    {
        DB::transaction(function () use ($estimateId, $mode) {
            Log::info('estimate.items.recalculate_numbers.start', [
                'estimate_id' => $estimateId,
                'mode' => $mode,
            ]);

            switch ($mode) {
                case self::NUMBERING_GLOBAL:
                    $this->recalculateGlobal($estimateId);
                    break;
                    
                case self::NUMBERING_BY_SECTION:
                    $this->recalculateBySection($estimateId);
                    break;
                    
                case self::NUMBERING_HIERARCHICAL:
                    $this->recalculateHierarchical($estimateId);
                    break;
            }

            Log::info('estimate.items.recalculate_numbers.complete', [
                'estimate_id' => $estimateId,
            ]);
        });
    }

    /**
     * Пересчет сквозной нумерации
     */
    protected function recalculateGlobal(int $estimateId): void
    {
        $items = EstimateItem::where('estimate_id', $estimateId)
            ->orderBy('estimate_section_id')
            ->orderBy('id')
            ->get();

        $counter = 1;
        foreach ($items as $item) {
            $item->update(['position_number' => (string) $counter]);
            $counter++;
        }
    }

    /**
     * Пересчет нумерации в рамках секций
     */
    protected function recalculateBySection(int $estimateId): void
    {
        // Сначала позиции без секции
        $itemsWithoutSection = EstimateItem::where('estimate_id', $estimateId)
            ->whereNull('estimate_section_id')
            ->orderBy('id')
            ->get();

        $counter = 1;
        foreach ($itemsWithoutSection as $item) {
            $item->update(['position_number' => (string) $counter]);
            $counter++;
        }

        // Затем позиции по секциям
        $sections = EstimateSection::where('estimate_id', $estimateId)
            ->orderBy('sort_order')
            ->get();

        foreach ($sections as $section) {
            $items = EstimateItem::where('estimate_id', $estimateId)
                ->where('estimate_section_id', $section->id)
                ->orderBy('id')
                ->get();

            $counter = 1;
            foreach ($items as $item) {
                $item->update(['position_number' => (string) $counter]);
                $counter++;
            }
        }
    }

    /**
     * Пересчет иерархической нумерации
     */
    protected function recalculateHierarchical(int $estimateId): void
    {
        // Позиции без секции
        $itemsWithoutSection = EstimateItem::where('estimate_id', $estimateId)
            ->whereNull('estimate_section_id')
            ->orderBy('id')
            ->get();

        $counter = 1;
        foreach ($itemsWithoutSection as $item) {
            $item->update(['position_number' => (string) $counter]);
            $counter++;
        }

        // Позиции по секциям с иерархической нумерацией
        $sections = EstimateSection::where('estimate_id', $estimateId)
            ->orderBy('sort_order')
            ->get();

        foreach ($sections as $section) {
            $items = EstimateItem::where('estimate_id', $estimateId)
                ->where('estimate_section_id', $section->id)
                ->orderBy('id')
                ->get();

            $counter = 1;
            foreach ($items as $item) {
                $hierarchicalNumber = "{$section->section_number}.{$counter}";
                $item->update(['position_number' => $hierarchicalNumber]);
                $counter++;
            }
        }
    }

    /**
     * Пересчитать номера после перемещения позиции
     * 
     * @param EstimateItem $item Перемещенная позиция
     * @param int|null $oldSectionId Старая секция
     * @param string $mode Режим нумерации
     */
    public function recalculateAfterMove(
        EstimateItem $item, 
        ?int $oldSectionId, 
        string $mode = self::NUMBERING_BY_SECTION
    ): void {
        DB::transaction(function () use ($item, $oldSectionId, $mode) {
            if ($mode === self::NUMBERING_GLOBAL) {
                // Для глобальной нумерации пересчитываем всю смету
                $this->recalculateGlobal($item->estimate_id);
            } else {
                // Для секционной нумерации пересчитываем старую и новую секции
                if ($oldSectionId !== null) {
                    $this->recalculateSectionItems($item->estimate_id, $oldSectionId, $mode);
                }
                
                if ($item->estimate_section_id !== null) {
                    $this->recalculateSectionItems($item->estimate_id, $item->estimate_section_id, $mode);
                }
            }
        });
    }

    /**
     * Пересчитать номера позиций в конкретной секции
     */
    protected function recalculateSectionItems(int $estimateId, ?int $sectionId, string $mode): void
    {
        $query = EstimateItem::where('estimate_id', $estimateId);
        
        if ($sectionId === null) {
            $query->whereNull('estimate_section_id');
        } else {
            $query->where('estimate_section_id', $sectionId);
        }

        $items = $query->orderBy('id')->get();

        $counter = 1;
        foreach ($items as $item) {
            if ($mode === self::NUMBERING_HIERARCHICAL && $sectionId !== null) {
                $section = EstimateSection::find($sectionId);
                $newNumber = "{$section->section_number}.{$counter}";
            } else {
                $newNumber = (string) $counter;
            }

            $item->update(['position_number' => $newNumber]);
            $counter++;
        }
    }

    /**
     * Пересчитать номера после массового изменения порядка
     */
    public function recalculateAfterReorder(
        int $estimateId,
        array $itemsOrder,
        string $mode = self::NUMBERING_BY_SECTION
    ): void {
        DB::transaction(function () use ($estimateId, $itemsOrder, $mode) {
            // Обновляем порядок для всех позиций
            foreach ($itemsOrder as $itemId => $order) {
                EstimateItem::where('id', $itemId)
                    ->update(['sort_order' => $order]);
            }

            // Пересчитываем номера
            $this->recalculateAllItemNumbers($estimateId, $mode);
        });
    }

    /**
     * Получить настройки нумерации для сметы
     * Возвращает режим нумерации из настроек модуля или сметы
     */
    public function getNumberingMode(int $estimateId): string
    {
        // По умолчанию - нумерация по секциям
        // TODO: Можно добавить настройку на уровне сметы или организации
        return self::NUMBERING_BY_SECTION;
    }
}

