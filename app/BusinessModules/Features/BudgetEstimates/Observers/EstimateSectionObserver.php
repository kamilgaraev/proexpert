<?php

namespace App\BusinessModules\Features\BudgetEstimates\Observers;

use App\Models\EstimateSection;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateSectionNumberingService;
use Illuminate\Support\Facades\Log;

/**
 * Observer для автоматического управления нумерацией разделов сметы
 * 
 * События:
 * - creating: Генерация section_number при создании (если не указан)
 * - created: Пересчет номеров после создания
 * - updating: Обработка изменения parent_section_id или sort_order
 * - updated: Пересчет номеров после обновления
 * - deleted: Пересчет номеров после удаления
 */
class EstimateSectionObserver
{
    public function __construct(
        protected EstimateSectionNumberingService $numberingService
    ) {}

    /**
     * Перед созданием раздела - генерируем номер, если не указан
     */
    public function creating(EstimateSection $section): void
    {
        // Если номер раздела не указан вручную - генерируем автоматически
        if (empty($section->section_number)) {
            $section->section_number = $this->numberingService->generateSectionNumber(
                $section->estimate_id,
                $section->parent_section_id
            );

            Log::debug('estimate.section.number_generated', [
                'estimate_id' => $section->estimate_id,
                'parent_section_id' => $section->parent_section_id,
                'generated_number' => $section->section_number,
            ]);
        }

        // Устанавливаем полный номер
        $section->full_section_number = $section->section_number;

        // Если sort_order не указан - определяем автоматически
        if ($section->sort_order === null) {
            $section->sort_order = $this->getNextSortOrder(
                $section->estimate_id,
                $section->parent_section_id
            );
        }
    }

    /**
     * После создания раздела - пересчитываем номера соседних разделов
     */
    public function created(EstimateSection $section): void
    {
        Log::info('estimate.section.created', [
            'estimate_id' => $section->estimate_id,
            'section_id' => $section->id,
            'section_number' => $section->section_number,
            'parent_section_id' => $section->parent_section_id,
        ]);

        // Пересчитываем номера соседних разделов (на том же уровне)
        // Это нужно, если новый раздел вставлен не в конец
        $this->recalculateSiblingsIfNeeded($section);
    }

    /**
     * Перед обновлением раздела - отслеживаем изменения структуры
     */
    public function updating(EstimateSection $section): void
    {
        // Проверяем, изменился ли родитель или порядок сортировки
        $originalParentId = $section->getOriginal('parent_section_id');
        $originalSortOrder = $section->getOriginal('sort_order');

        $parentChanged = $originalParentId !== $section->parent_section_id;
        $sortOrderChanged = $originalSortOrder !== $section->sort_order;

        if ($parentChanged || $sortOrderChanged) {
            // Сохраняем флаг, что нужен пересчет после обновления
            $section->setAttribute('_needs_renumbering', true);
            $section->setAttribute('_original_parent_id', $originalParentId);

            Log::debug('estimate.section.structure_changed', [
                'section_id' => $section->id,
                'parent_changed' => $parentChanged,
                'sort_order_changed' => $sortOrderChanged,
                'old_parent' => $originalParentId,
                'new_parent' => $section->parent_section_id,
                'old_sort_order' => $originalSortOrder,
                'new_sort_order' => $section->sort_order,
            ]);
        }
    }

    /**
     * После обновления раздела - пересчитываем номера если нужно
     */
    public function updated(EstimateSection $section): void
    {
        // Если была изменена структура - пересчитываем номера
        if ($section->getAttribute('_needs_renumbering')) {
            $originalParentId = $section->getAttribute('_original_parent_id');

            Log::info('estimate.section.updated_with_renumbering', [
                'section_id' => $section->id,
                'estimate_id' => $section->estimate_id,
            ]);

            // Пересчитываем номера после перемещения
            $this->numberingService->recalculateAfterMove($section);

            // Пересчитываем номера в старом родителе (если родитель изменился)
            if ($originalParentId !== $section->parent_section_id) {
                $this->numberingService->recalculateAfterDelete(
                    $section->estimate_id,
                    $originalParentId
                );
            }
        }
    }

    /**
     * После удаления раздела - пересчитываем номера оставшихся разделов
     */
    public function deleted(EstimateSection $section): void
    {
        Log::info('estimate.section.deleted', [
            'section_id' => $section->id,
            'estimate_id' => $section->estimate_id,
            'section_number' => $section->section_number,
        ]);

        // Пересчитываем номера оставшихся разделов на том же уровне
        $this->numberingService->recalculateAfterDelete(
            $section->estimate_id,
            $section->parent_section_id
        );
    }

    /**
     * Получить следующий sort_order для нового раздела
     */
    protected function getNextSortOrder(int $estimateId, ?int $parentSectionId): int
    {
        $query = EstimateSection::where('estimate_id', $estimateId);

        if ($parentSectionId) {
            $query->where('parent_section_id', $parentSectionId);
        } else {
            $query->whereNull('parent_section_id');
        }

        $maxOrder = $query->max('sort_order');

        return ($maxOrder ?? -1) + 1;
    }

    /**
     * Пересчитать номера соседних разделов, если новый раздел вставлен не в конец
     */
    protected function recalculateSiblingsIfNeeded(EstimateSection $section): void
    {
        // Получаем количество разделов на том же уровне
        $query = EstimateSection::where('estimate_id', $section->estimate_id)
            ->where('id', '!=', $section->id);

        if ($section->parent_section_id) {
            $query->where('parent_section_id', $section->parent_section_id);
        } else {
            $query->whereNull('parent_section_id');
        }

        $siblingsCount = $query->count();

        // Если есть соседние разделы - пересчитываем
        if ($siblingsCount > 0) {
            // Проверяем, не вставлен ли раздел в середину (не в конец)
            // Если sort_order меньше максимального - нужен пересчет
            $maxSortOrder = $query->max('sort_order');
            
            if ($section->sort_order < $maxSortOrder) {
                Log::debug('estimate.section.recalculate_siblings', [
                    'section_id' => $section->id,
                    'sort_order' => $section->sort_order,
                    'max_sort_order' => $maxSortOrder,
                ]);

                $this->numberingService->recalculateAfterDelete(
                    $section->estimate_id,
                    $section->parent_section_id
                );
            }
        }
    }
}

