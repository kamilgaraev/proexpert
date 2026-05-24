<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\Models\Estimate;
use App\Models\EstimateItem;
use BackedEnum;
use DateTimeInterface;

final class EstimateRagSource implements RagSourceCollectorInterface
{
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
            ->with([
                'project',
                'contract',
                'sections',
                'items.section',
                'items.workType',
                'items.measurementUnit',
                'items.normativeRate',
                'items.catalogItem',
                'items.material',
            ])
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id');

        foreach ($query->cursor() as $estimate) {
            yield $this->chunk($estimate);
        }
    }

    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        if ($entityType !== 'estimate') {
            return [];
        }

        $estimate = Estimate::query()
            ->with([
                'project',
                'contract',
                'sections',
                'items.section',
                'items.workType',
                'items.measurementUnit',
                'items.normativeRate',
                'items.catalogItem',
                'items.material',
            ])
            ->where('organization_id', $organizationId)
            ->where('id', $entityId)
            ->first();

        return $estimate instanceof Estimate ? [$this->chunk($estimate)] : [];
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

        $items = $estimate->items
            ->sortByDesc(fn (EstimateItem $item): float => (float) ($item->current_total_amount ?? $item->total_amount ?? 0))
            ->take(8)
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
