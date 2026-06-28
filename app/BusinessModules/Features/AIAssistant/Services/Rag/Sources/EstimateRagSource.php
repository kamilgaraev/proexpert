<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Collection;

final class EstimateRagSource implements RagSourceCollectorInterface
{
    private const SECTION_ITEMS_LIMIT = 20;

    public function sourceType(): string
    {
        return 'estimate';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
        $query = Estimate::query()
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId));

        foreach ($query->lazyById(50) as $estimate) {
            $estimate->load($this->estimateRelations());

            foreach ($this->chunksForEstimate($estimate) as $chunk) {
                yield $chunk;
            }
        }
    }

    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        if ($entityType === 'estimate') {
            $estimate = Estimate::query()
                ->with($this->estimateRelations())
                ->where('organization_id', $organizationId)
                ->where('id', $entityId)
                ->first();

            return $estimate instanceof Estimate ? $this->chunksForEstimate($estimate) : [];
        }

        if ($entityType === 'estimate_section') {
            $section = EstimateSection::query()
                ->with($this->sectionRelations())
                ->where('id', $entityId)
                ->whereHas('estimate', static fn ($query) => $query->where('organization_id', $organizationId))
                ->first();

            return $section instanceof EstimateSection && $section->estimate instanceof Estimate
                ? [$this->sectionChunk($section->estimate, $section)]
                : [];
        }

        return [];
    }

    /**
     * @return array<int, RagChunkData>
     */
    private function chunksForEstimate(Estimate $estimate): array
    {
        $chunks = [$this->chunk($estimate)];

        foreach ($estimate->sections as $section) {
            $chunks[] = $this->sectionChunk($estimate, $section);
        }

        return $chunks;
    }

    private function chunk(Estimate $estimate): RagChunkData
    {
        $sections = $estimate->sections
            ->take(8)
            ->map(fn ($section): string => trim(sprintf(
                '%s %s %s',
                $this->stringValue($section->section_number),
                $this->stringValue($section->name),
                $this->moneyValue($section->section_total_amount)
            )))
            ->filter()
            ->values()
            ->all();

        $items = $this->topItemsByAmount($estimate->items, 8)
            ->map(fn (EstimateItem $item): string => trim(sprintf(
                '%s %s %s %s x %s = %s',
                $this->stringValue($item->position_number),
                $this->stringValue($item->name),
                $this->stringValue($item->normative_rate_code ?? $item->normativeRate?->code),
                $this->quantityValue($item->quantity_total ?? $item->quantity),
                $this->stringValue($item->measurementUnit?->name),
                $this->moneyValue($item->current_total_amount ?? $item->total_amount)
            )))
            ->filter()
            ->values()
            ->all();

        $content = $this->lines([
            'Смета: '.$this->stringValue($estimate->number).' '.$this->stringValue($estimate->name),
            'Проект: '.$this->stringValue($estimate->project?->name),
            'Договор: '.$this->stringValue($estimate->contract?->number ?? $estimate->contract?->subject),
            'Статус: '.$this->stringValue($estimate->status),
            'Тип: '.$this->stringValue($estimate->type),
            'Версия: '.$this->stringValue($estimate->version),
            'Дата сметы: '.$this->dateValue($estimate->estimate_date),
            'Прямые затраты: '.$this->moneyValue($estimate->total_direct_costs),
            'Сумма: '.$this->moneyValue($estimate->total_amount),
            'Сумма с НДС: '.$this->moneyValue($estimate->total_amount_with_vat),
            'Разделы: '.implode('; ', $sections),
            'Ключевые позиции: '.implode('; ', $items),
            'Описание: '.$this->stringValue($estimate->description),
        ]);

        return new RagChunkData(
            organizationId: (int) $estimate->organization_id,
            projectId: $estimate->project_id !== null ? (int) $estimate->project_id : null,
            sourceType: $this->sourceType(),
            entityType: 'estimate',
            entityId: (int) $estimate->id,
            title: 'Смета: '.$this->stringValue($estimate->number ?? $estimate->name),
            content: $content,
            metadata: [
                'status' => $this->scalarValue($estimate->status),
                'project_id' => $estimate->project_id,
                'contract_id' => $estimate->contract_id,
                'sections_count' => $estimate->sections->count(),
                'items_count' => $estimate->items->count(),
                'total_amount' => $this->numericValue($estimate->total_amount),
            ],
            updatedAt: $estimate->updated_at
        );
    }

    private function sectionChunk(Estimate $estimate, EstimateSection $section): RagChunkData
    {
        $sectionItems = $this->sectionItems($estimate, $section);
        $items = $this->topItemsByAmount($sectionItems, self::SECTION_ITEMS_LIMIT)
            ->map(fn (EstimateItem $item): string => $this->itemLine($item))
            ->filter()
            ->values()
            ->all();

        $content = $this->lines([
            'Раздел сметы: '.$this->sectionName($section),
            'Смета: '.$this->stringValue($estimate->number).' '.$this->stringValue($estimate->name),
            'Проект: '.$this->stringValue($estimate->project?->name),
            'Договор: '.$this->stringValue($estimate->contract?->number ?? $estimate->contract?->subject),
            'Родительский раздел: '.$this->sectionName($section->parent),
            'Статус сметы: '.$this->stringValue($estimate->status),
            'Сумма раздела: '.$this->moneyValue($section->section_total_amount),
            'Сумма позиций раздела: '.$this->moneyValue($this->sectionItemsTotal($sectionItems)),
            'Позиций в разделе: '.$this->stringValue($sectionItems->count()),
            'Итоги по типам: '.implode('; ', $this->itemTypeTotals($sectionItems)),
            'Ключевые позиции раздела: '.implode('; ', $items),
            'Описание раздела: '.$this->stringValue($section->description),
        ]);

        return new RagChunkData(
            organizationId: (int) $estimate->organization_id,
            projectId: $estimate->project_id !== null ? (int) $estimate->project_id : null,
            sourceType: $this->sourceType(),
            entityType: 'estimate_section',
            entityId: (int) $section->id,
            title: 'Раздел сметы: '.$this->sectionName($section).' ('.$this->stringValue($estimate->number ?? $estimate->name).')',
            content: $content,
            metadata: [
                'status' => $this->scalarValue($estimate->status),
                'project_id' => $estimate->project_id,
                'contract_id' => $estimate->contract_id,
                'estimate_id' => $estimate->id,
                'estimate_section_id' => $section->id,
                'section_name' => $this->stringValue($section->name),
                'section_number' => $this->stringValue($section->section_number),
                'parent_section_id' => $section->parent_section_id,
                'items_count' => $sectionItems->count(),
                'section_total_amount' => $this->numericValue($section->section_total_amount),
                'items_total_amount' => $this->sectionItemsTotal($sectionItems),
                'item_type_totals' => $this->itemTypeTotalsForMetadata($sectionItems),
            ],
            updatedAt: $section->updated_at ?? $estimate->updated_at
        );
    }

    /**
     * @return array<int, string>
     */
    private function estimateRelations(): array
    {
        return [
            'project',
            'contract',
            'sections' => static fn ($query) => $query
                ->orderBy('sort_order')
                ->orderBy('section_number')
                ->orderBy('id'),
            'sections.parent',
            'items' => static fn ($query) => $query
                ->orderBy('position_number')
                ->orderBy('id'),
            'items.section',
            'items.workType',
            'items.measurementUnit',
            'items.normativeRate',
            'items.catalogItem',
            'items.material',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function sectionRelations(): array
    {
        return [
            'estimate.sections' => static fn ($query) => $query
                ->orderBy('sort_order')
                ->orderBy('section_number')
                ->orderBy('id'),
            'estimate.sections.parent',
            'estimate.items' => static fn ($query) => $query
                ->orderBy('position_number')
                ->orderBy('id'),
            'estimate.items.section',
            'estimate.items.workType',
            'estimate.items.measurementUnit',
            'estimate.items.normativeRate',
            'estimate.items.catalogItem',
            'estimate.items.material',
            'estimate.project',
            'estimate.contract',
            'parent',
            'items' => static fn ($query) => $query
                ->orderBy('position_number')
                ->orderBy('id'),
            'items.workType',
            'items.measurementUnit',
            'items.normativeRate',
            'items.catalogItem',
            'items.material',
        ];
    }

    private function sectionName(?EstimateSection $section): string
    {
        if (! $section instanceof EstimateSection) {
            return '';
        }

        return trim(sprintf(
            '%s %s',
            $this->stringValue($section->full_section_number ?? $section->section_number),
            $this->stringValue($section->name)
        ));
    }

    private function itemLine(EstimateItem $item): string
    {
        return implode('; ', array_filter([
            trim($this->stringValue($item->position_number).' '.$this->stringValue($item->name)),
            'тип: '.$this->itemTypeLabel($item->item_type),
            'код: '.$this->stringValue($item->normative_rate_code ?? $item->normativeRate?->code),
            'объем: '.trim($this->quantityValue($item->quantity_total ?? $item->quantity).' '.$this->stringValue($item->measurementUnit?->name)),
            'цена: '.$this->moneyValue($item->current_unit_price ?? $item->unit_price),
            'сумма: '.$this->moneyValue($item->current_total_amount ?? $item->total_amount),
            'вид работ: '.$this->stringValue($item->workType?->name),
            'материал: '.$this->stringValue($item->material?->name),
            'каталог: '.$this->stringValue($item->catalogItem?->code ?? $item->catalogItem?->name),
            'примечание: '.$this->stringValue($item->notes),
        ], static fn (string $part): bool => trim($part) !== '' && ! str_ends_with($part, ': ')));
    }

    private function itemTypeLabel(mixed $value): string
    {
        return match ($this->stringValue($value)) {
            'work' => 'Работа',
            'material' => 'Материал',
            'equipment' => 'Оборудование',
            'machinery' => 'Механизм',
            'labor' => 'Труд',
            'summary' => 'Итого',
            default => $this->stringValue($value),
        };
    }

    /**
     * @return Collection<int, EstimateItem>
     */
    private function sectionItems(Estimate $estimate, EstimateSection $section): Collection
    {
        $sectionIds = $this->sectionAndDescendantIds($estimate, $section);

        return $estimate->items
            ->filter(static fn (EstimateItem $item): bool => in_array((int) $item->estimate_section_id, $sectionIds, true))
            ->values();
    }

    /**
     * @return array<int, int>
     */
    private function sectionAndDescendantIds(Estimate $estimate, EstimateSection $section): array
    {
        $ids = [(int) $section->id];
        $frontier = $ids;

        while ($frontier !== []) {
            $children = $estimate->sections
                ->filter(static fn (EstimateSection $candidate): bool => in_array((int) $candidate->parent_section_id, $frontier, true))
                ->map(static fn (EstimateSection $candidate): int => (int) $candidate->id)
                ->values()
                ->all();

            $children = array_values(array_diff($children, $ids));
            $ids = array_values(array_unique(array_merge($ids, $children)));
            $frontier = $children;
        }

        return $ids;
    }

    /**
     * @param  Collection<int, EstimateItem>  $items
     */
    private function sectionItemsTotal(Collection $items): float
    {
        return (float) $items->sum(fn (EstimateItem $item): float => $this->itemAmount($item));
    }

    /**
     * @param  Collection<int, EstimateItem>  $items
     * @return Collection<int, EstimateItem>
     */
    private function topItemsByAmount(Collection $items, int $limit): Collection
    {
        return $items
            ->sort(function (EstimateItem $left, EstimateItem $right): int {
                $amountComparison = $this->itemAmount($right) <=> $this->itemAmount($left);

                return $amountComparison !== 0
                    ? $amountComparison
                    : ((int) $left->id <=> (int) $right->id);
            })
            ->take($limit)
            ->values();
    }

    private function itemAmount(EstimateItem $item): float
    {
        return (float) ($item->current_total_amount ?? $item->total_amount ?? 0);
    }

    /**
     * @param  Collection<int, EstimateItem>  $items
     * @return array<int, string>
     */
    private function itemTypeTotals(Collection $items): array
    {
        return $items
            ->groupBy(fn (EstimateItem $item): string => $this->itemTypeLabel($item->item_type))
            ->map(fn ($items, string $type): string => sprintf(
                '%s: %d поз., %s',
                $type,
                $items->count(),
                $this->moneyValue($items->sum(fn (EstimateItem $item): float => $this->itemAmount($item)))
            ))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, EstimateItem>  $items
     * @return array<string, array{count: int, amount: float}>
     */
    private function itemTypeTotalsForMetadata(Collection $items): array
    {
        return $items
            ->groupBy(fn (EstimateItem $item): string => $this->stringValue($item->item_type))
            ->map(fn ($items): array => [
                'count' => $items->count(),
                'amount' => (float) $items->sum(fn (EstimateItem $item): float => $this->itemAmount($item)),
            ])
            ->all();
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

    private function numericValue(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function dateValue(mixed $value): string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : '';
    }
}
