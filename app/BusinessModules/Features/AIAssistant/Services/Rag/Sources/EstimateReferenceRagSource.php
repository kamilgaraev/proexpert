<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\Models\EstimateLibraryItem;
use App\Models\EstimatePositionCatalog;
use App\Models\EstimateTemplate;
use App\Models\NormativeRate;
use BackedEnum;

final class EstimateReferenceRagSource implements RagSourceCollectorInterface
{
    public function sourceType(): string
    {
        return 'estimate_reference';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
        foreach ($this->templates($organizationId)->cursor() as $template) {
            yield $this->templateChunk($template, $organizationId);
        }

        foreach ($this->libraryItems($organizationId)->cursor() as $item) {
            yield $this->libraryItemChunk($item, $organizationId);
        }

        foreach ($this->catalogItems($organizationId)->cursor() as $item) {
            yield $this->catalogItemChunk($item);
        }

        foreach ($this->usedNormativeRates($organizationId, $projectId)->cursor() as $rate) {
            yield $this->normativeRateChunk($rate, $organizationId);
        }
    }

    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        return match ($entityType) {
            'estimate_template' => $this->collectTemplate($organizationId, $entityId),
            'estimate_library_item' => $this->collectLibraryItem($organizationId, $entityId),
            'normative_rate' => $this->collectNormativeRate($organizationId, $entityId),
            'estimate_catalog_item' => $this->collectCatalogItem($organizationId, $entityId),
            default => [],
        };
    }

    private function collectTemplate(int $organizationId, string|int $entityId): array
    {
        $template = $this->templates($organizationId)
            ->where('id', $entityId)
            ->first();

        return $template instanceof EstimateTemplate ? [$this->templateChunk($template, $organizationId)] : [];
    }

    private function collectLibraryItem(int $organizationId, string|int $entityId): array
    {
        $item = $this->libraryItems($organizationId)
            ->where('id', $entityId)
            ->first();

        return $item instanceof EstimateLibraryItem ? [$this->libraryItemChunk($item, $organizationId)] : [];
    }

    private function collectNormativeRate(int $organizationId, string|int $entityId): array
    {
        $rate = NormativeRate::query()
            ->with(['collection', 'section'])
            ->where('id', $entityId)
            ->whereHas('estimateItems.estimate', static fn ($query) => $query->where('organization_id', $organizationId))
            ->first();

        return $rate instanceof NormativeRate ? [$this->normativeRateChunk($rate, $organizationId)] : [];
    }

    private function collectCatalogItem(int $organizationId, string|int $entityId): array
    {
        $item = $this->catalogItems($organizationId)
            ->where('id', $entityId)
            ->first();

        return $item instanceof EstimatePositionCatalog ? [$this->catalogItemChunk($item)] : [];
    }

    private function templates(int $organizationId)
    {
        return EstimateTemplate::query()
            ->where(static function ($query) use ($organizationId): void {
                $query
                    ->where('organization_id', $organizationId)
                    ->orWhere('is_public', true);
            })
            ->orderBy('id');
    }

    private function libraryItems(int $organizationId)
    {
        return EstimateLibraryItem::query()
            ->with(['library', 'positions'])
            ->whereHas('library', static function ($query) use ($organizationId): void {
                $query
                    ->where('organization_id', $organizationId)
                    ->orWhere('access_level', 'public');
            })
            ->orderBy('id');
    }

    private function catalogItems(int $organizationId)
    {
        return EstimatePositionCatalog::query()
            ->with(['category', 'measurementUnit', 'workType'])
            ->where('organization_id', $organizationId)
            ->orderBy('id');
    }

    private function usedNormativeRates(int $organizationId, ?int $projectId)
    {
        return NormativeRate::query()
            ->with(['collection', 'section'])
            ->whereHas('estimateItems.estimate', static function ($query) use ($organizationId, $projectId): void {
                $query->where('organization_id', $organizationId);

                if ($projectId !== null) {
                    $query->where('project_id', $projectId);
                }
            })
            ->orderBy('id');
    }

    private function templateChunk(EstimateTemplate $template, int $organizationId): RagChunkData
    {
        $structure = is_array($template->template_structure) ? $template->template_structure : [];
        $content = $this->lines([
            'Шаблон сметы: '.$this->stringValue($template->name),
            'Категория работ: '.$this->stringValue($template->work_type_category),
            'Доступ: '.($template->is_public ? 'общий' : 'организация'),
            'Использований: '.$this->stringValue($template->usage_count),
            'Разделов в структуре: '.$this->stringValue(count($structure)),
            'Описание: '.$this->stringValue($template->description),
        ]);

        return new RagChunkData(
            organizationId: $organizationId,
            projectId: null,
            sourceType: $this->sourceType(),
            entityType: 'estimate_template',
            entityId: (int) $template->id,
            title: 'Шаблон сметы: '.$this->stringValue($template->name),
            content: $content,
            metadata: [
                'reference_kind' => 'estimate_template',
                'organization_id' => $template->organization_id,
                'is_public' => (bool) $template->is_public,
                'work_type_category' => $template->work_type_category,
            ],
            updatedAt: $template->updated_at
        );
    }

    private function libraryItemChunk(EstimateLibraryItem $item, int $organizationId): RagChunkData
    {
        $content = $this->lines([
            'Библиотечный элемент сметы: '.$this->stringValue($item->name),
            'Библиотека: '.$this->stringValue($item->library?->name),
            'Категория: '.$this->stringValue($item->library?->category),
            'Доступ: '.$this->stringValue($item->library?->access_level),
            'Позиций: '.$this->stringValue($item->positions_count ?? $item->positions->count()),
            'Использований: '.$this->stringValue($item->usage_count),
            'Описание: '.$this->stringValue($item->description),
        ]);

        return new RagChunkData(
            organizationId: $organizationId,
            projectId: null,
            sourceType: $this->sourceType(),
            entityType: 'estimate_library_item',
            entityId: (int) $item->id,
            title: 'Элемент библиотеки: '.$this->stringValue($item->name),
            content: $content,
            metadata: [
                'reference_kind' => 'estimate_library_item',
                'library_id' => $item->library_id,
                'library_organization_id' => $item->library?->organization_id,
                'access_level' => $item->library?->access_level,
            ],
            updatedAt: $item->updated_at
        );
    }

    private function catalogItemChunk(EstimatePositionCatalog $item): RagChunkData
    {
        $content = $this->lines([
            'Позиция сметного каталога: '.$this->stringValue($item->name),
            'Код: '.$this->stringValue($item->code),
            'Тип: '.$this->stringValue($item->item_type),
            'Категория: '.$this->stringValue($item->category?->name),
            'Единица измерения: '.$this->stringValue($item->measurementUnit?->name),
            'Вид работ: '.$this->stringValue($item->workType?->name),
            'Цена: '.$this->moneyValue($item->unit_price),
            'Прямые затраты: '.$this->moneyValue($item->direct_costs),
            'Активна: '.($item->is_active ? 'да' : 'нет'),
            'Описание: '.$this->stringValue($item->description),
        ]);

        return new RagChunkData(
            organizationId: (int) $item->organization_id,
            projectId: null,
            sourceType: $this->sourceType(),
            entityType: 'estimate_catalog_item',
            entityId: (int) $item->id,
            title: 'Позиция каталога: '.$this->stringValue($item->name),
            content: $content,
            metadata: [
                'reference_kind' => 'estimate_catalog_item',
                'category_id' => $item->category_id,
                'item_type' => $this->scalarValue($item->item_type),
                'is_active' => (bool) $item->is_active,
            ],
            updatedAt: $item->updated_at
        );
    }

    private function normativeRateChunk(NormativeRate $rate, int $organizationId): RagChunkData
    {
        $content = $this->lines([
            'Нормативная расценка: '.$this->stringValue($rate->code).' '.$this->stringValue($rate->name),
            'Сборник: '.$this->stringValue($rate->collection?->code).' '.$this->stringValue($rate->collection?->name),
            'Раздел: '.$this->stringValue($rate->section?->code).' '.$this->stringValue($rate->section?->name),
            'Единица измерения: '.$this->stringValue($rate->measurement_unit),
            'Базовая цена: '.$this->moneyValue($rate->base_price),
            'Материалы: '.$this->moneyValue($rate->materials_cost),
            'Машины: '.$this->moneyValue($rate->machinery_cost),
            'Оплата труда: '.$this->moneyValue($rate->labor_cost),
            'Трудозатраты: '.$this->quantityValue($rate->labor_hours),
            'Год базы: '.$this->stringValue($rate->base_price_year),
            'Описание: '.$this->stringValue($rate->description),
            'Примечания: '.$this->stringValue($rate->notes),
        ]);

        return new RagChunkData(
            organizationId: $organizationId,
            projectId: null,
            sourceType: $this->sourceType(),
            entityType: 'normative_rate',
            entityId: (int) $rate->id,
            title: 'Норма: '.$this->stringValue($rate->code).' '.$this->stringValue($rate->name),
            content: $content,
            metadata: [
                'reference_kind' => 'normative_rate',
                'collection_id' => $rate->collection_id,
                'section_id' => $rate->section_id,
                'code' => $rate->code,
            ],
            updatedAt: $rate->updated_at
        );
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function lines(array $lines): string
    {
        return implode("\n", array_filter($lines, static fn (string $line): bool => ! str_ends_with($line, ': ') && ! str_ends_with($line, ': -')));
    }

    private function scalarValue(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    private function stringValue(mixed $value): string
    {
        $value = $this->scalarValue($value);

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function quantityValue(mixed $value): string
    {
        return is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.') : '';
    }

    private function moneyValue(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 2, '.', ' ') : '';
    }
}
