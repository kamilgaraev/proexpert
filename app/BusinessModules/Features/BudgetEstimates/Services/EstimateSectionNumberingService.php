<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\Models\Estimate;
use App\Models\EstimateSection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для управления автоматической нумерацией разделов сметы
 * 
 * Функционал:
 * - Автоматическая генерация section_number на основе sort_order и иерархии
 * - Пересчет номеров при изменении структуры (перемещение, удаление, добавление)
 * - Поддержка иерархической нумерации (1, 1.1, 1.2, 2, 2.1, 2.1.1 и т.д.)
 */
class EstimateSectionNumberingService
{
    /**
     * Генерировать номер для нового раздела
     */
    public function generateSectionNumber(int $estimateId, ?int $parentSectionId = null): string
    {
        if ($parentSectionId === null) {
            // Корневой раздел - просто следующий номер
            return $this->getNextRootSectionNumber($estimateId);
        }

        // Вложенный раздел - номер родителя + точка + следующий номер
        $parent = EstimateSection::find($parentSectionId);
        if (!$parent) {
            throw new \InvalidArgumentException("Родительский раздел {$parentSectionId} не найден");
        }

        $parentNumber = $parent->section_number;
        $nextChildNumber = $this->getNextChildSectionNumber($parentSectionId);

        return "{$parentNumber}.{$nextChildNumber}";
    }

    /**
     * Получить следующий номер для корневого раздела
     */
    protected function getNextRootSectionNumber(int $estimateId): string
    {
        $maxNumber = EstimateSection::where('estimate_id', $estimateId)
            ->whereNull('parent_section_id')
            ->selectRaw('MAX(CAST(section_number AS UNSIGNED)) as max_num')
            ->value('max_num');

        return (string) (($maxNumber ?? 0) + 1);
    }

    /**
     * Получить следующий номер для дочернего раздела
     */
    protected function getNextChildSectionNumber(int $parentSectionId): int
    {
        $children = EstimateSection::where('parent_section_id', $parentSectionId)
            ->orderBy('sort_order')
            ->get();

        if ($children->isEmpty()) {
            return 1;
        }

        // Находим максимальный номер среди дочерних разделов
        $maxNumber = 0;
        foreach ($children as $child) {
            // Извлекаем последний сегмент номера (например, из "1.2.3" извлекаем "3")
            $parts = explode('.', $child->section_number);
            $lastSegment = (int) end($parts);
            $maxNumber = max($maxNumber, $lastSegment);
        }

        return $maxNumber + 1;
    }

    /**
     * Пересчитать номера всех разделов сметы
     * Используется после массовых операций или при необходимости нормализации
     */
    public function recalculateAllSectionNumbers(int $estimateId): void
    {
        DB::transaction(function () use ($estimateId) {
            Log::info('estimate.sections.recalculate_numbers.start', [
                'estimate_id' => $estimateId,
            ]);

            // Получаем корневые разделы, отсортированные по sort_order
            $rootSections = EstimateSection::where('estimate_id', $estimateId)
                ->whereNull('parent_section_id')
                ->orderBy('sort_order')
                ->get();

            $rootCounter = 1;
            foreach ($rootSections as $section) {
                $this->recalculateSectionNumberRecursive($section, (string) $rootCounter);
                $rootCounter++;
            }

            Log::info('estimate.sections.recalculate_numbers.complete', [
                'estimate_id' => $estimateId,
                'sections_updated' => $rootCounter - 1,
            ]);
        });
    }

    /**
     * Рекурсивный пересчет номеров раздела и его детей
     */
    protected function recalculateSectionNumberRecursive(EstimateSection $section, string $newNumber): void
    {
        // Обновляем номер текущего раздела
        $section->update([
            'section_number' => $newNumber,
            'full_section_number' => $newNumber, // Полный иерархический номер
        ]);

        // Получаем дочерние разделы, отсортированные по sort_order
        $children = EstimateSection::where('parent_section_id', $section->id)
            ->orderBy('sort_order')
            ->get();

        // Рекурсивно обновляем номера детей
        $childCounter = 1;
        foreach ($children as $child) {
            $childNumber = "{$newNumber}.{$childCounter}";
            $this->recalculateSectionNumberRecursive($child, $childNumber);
            $childCounter++;
        }
    }

    /**
     * Пересчитать номера при изменении порядка (после drag-and-drop)
     * 
     * @param int $estimateId ID сметы
     * @param array $sectionsOrder Массив [section_id => sort_order]
     */
    public function recalculateAfterReorder(int $estimateId, array $sectionsOrder): void
    {
        DB::transaction(function () use ($estimateId, $sectionsOrder) {
            // Сначала обновляем sort_order для всех разделов
            foreach ($sectionsOrder as $sectionId => $sortOrder) {
                EstimateSection::where('id', $sectionId)
                    ->update(['sort_order' => $sortOrder]);
            }

            // Затем пересчитываем все номера
            $this->recalculateAllSectionNumbers($estimateId);
        });
    }

    /**
     * Пересчитать номера после перемещения раздела в другого родителя
     */
    public function recalculateAfterMove(EstimateSection $section): void
    {
        DB::transaction(function () use ($section) {
            // Генерируем новый номер на основе нового положения
            $newNumber = $this->generateSectionNumber(
                $section->estimate_id,
                $section->parent_section_id
            );

            // Рекурсивно обновляем номер раздела и всех его детей
            $this->recalculateSectionNumberRecursive($section, $newNumber);

            // Пересчитываем номера соседних разделов на том же уровне
            $this->recalculateSiblingSections($section);
        });
    }

    /**
     * Пересчитать номера соседних разделов (на том же уровне иерархии)
     */
    protected function recalculateSiblingSections(EstimateSection $section): void
    {
        // Получаем всех соседей (разделы с тем же родителем)
        $siblings = EstimateSection::where('estimate_id', $section->estimate_id)
            ->where('parent_section_id', $section->parent_section_id)
            ->where('id', '!=', $section->id)
            ->orderBy('sort_order')
            ->get();

        foreach ($siblings as $sibling) {
            // Генерируем корректный номер на основе позиции
            $newNumber = $this->generateSectionNumber(
                $sibling->estimate_id,
                $sibling->parent_section_id
            );
            
            $this->recalculateSectionNumberRecursive($sibling, $newNumber);
        }
    }

    /**
     * Пересчитать номера после удаления раздела
     * Вызывается для пересчета оставшихся разделов на том же уровне
     */
    public function recalculateAfterDelete(int $estimateId, ?int $parentSectionId = null): void
    {
        DB::transaction(function () use ($estimateId, $parentSectionId) {
            // Получаем все разделы на том же уровне иерархии
            $query = EstimateSection::where('estimate_id', $estimateId)
                ->orderBy('sort_order');

            if ($parentSectionId === null) {
                $query->whereNull('parent_section_id');
            } else {
                $query->where('parent_section_id', $parentSectionId);
            }

            $sections = $query->get();

            // Пересчитываем номера для оставшихся разделов
            if ($parentSectionId === null) {
                // Корневые разделы
                $counter = 1;
                foreach ($sections as $section) {
                    $this->recalculateSectionNumberRecursive($section, (string) $counter);
                    $counter++;
                }
            } else {
                // Дочерние разделы
                $parent = EstimateSection::find($parentSectionId);
                if ($parent) {
                    $counter = 1;
                    foreach ($sections as $section) {
                        $newNumber = "{$parent->section_number}.{$counter}";
                        $this->recalculateSectionNumberRecursive($section, $newNumber);
                        $counter++;
                    }
                }
            }
        });
    }

    /**
     * Получить полный иерархический номер раздела
     * (с учетом всех родителей)
     */
    public function getFullSectionNumber(EstimateSection $section): string
    {
        return $section->section_number;
    }

    /**
     * Валидация корректности нумерации сметы
     * Возвращает массив ошибок (пустой, если все ОК)
     */
    public function validateNumbering(int $estimateId): array
    {
        $errors = [];
        
        $sections = EstimateSection::where('estimate_id', $estimateId)->get();
        
        foreach ($sections as $section) {
            // Проверка формата номера
            if (!preg_match('/^\d+(\.\d+)*$/', $section->section_number)) {
                $errors[] = "Раздел {$section->id}: некорректный формат номера '{$section->section_number}'";
            }

            // Проверка соответствия номера иерархии
            if ($section->parent_section_id) {
                $parent = EstimateSection::find($section->parent_section_id);
                if ($parent) {
                    if (!str_starts_with($section->section_number, $parent->section_number . '.')) {
                        $errors[] = "Раздел {$section->id}: номер не соответствует родителю (родитель: {$parent->section_number}, раздел: {$section->section_number})";
                    }
                }
            }
        }

        return $errors;
    }
}

