<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\Models\ConstructionJournalEntry;
use BackedEnum;
use DateTimeInterface;

final class ConstructionJournalRagSource implements RagSourceCollectorInterface
{
    public function sourceType(): string
    {
        return 'construction_journal';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
        $query = ConstructionJournalEntry::query()
            ->with([
                'journal.project',
                'journal.contract',
                'scheduleTask',
                'estimate',
                'createdBy',
                'approvedBy',
                'workVolumes.workType',
                'workVolumes.measurementUnit',
                'materials.material',
                'workers',
                'equipment',
            ])
            ->whereHas('journal', static function ($query) use ($organizationId, $projectId): void {
                $query->where('organization_id', $organizationId);

                if ($projectId !== null) {
                    $query->where('project_id', $projectId);
                }
            })
            ->orderBy('id');

        foreach ($query->cursor() as $entry) {
            yield $this->chunk($entry);
        }
    }

    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        if ($entityType !== 'construction_journal_entry') {
            return [];
        }

        $entry = ConstructionJournalEntry::query()
            ->with([
                'journal.project',
                'journal.contract',
                'scheduleTask',
                'estimate',
                'createdBy',
                'approvedBy',
                'workVolumes.workType',
                'workVolumes.measurementUnit',
                'materials.material',
                'workers',
                'equipment',
            ])
            ->where('id', $entityId)
            ->whereHas('journal', static fn ($query) => $query->where('organization_id', $organizationId))
            ->first();

        return $entry instanceof ConstructionJournalEntry ? [$this->chunk($entry)] : [];
    }

    private function chunk(ConstructionJournalEntry $entry): RagChunkData
    {
        $workVolumes = $entry->workVolumes
            ->take(6)
            ->map(fn ($volume): string => trim(sprintf(
                '%s %s %s',
                $this->stringValue($volume->workType?->name),
                $this->quantityValue($volume->quantity),
                $this->stringValue($volume->measurementUnit?->name)
            )))
            ->filter()
            ->values()
            ->all();

        $materials = $entry->materials
            ->take(6)
            ->map(fn ($material): string => trim(sprintf(
                '%s %s %s',
                $this->stringValue($material->material_name ?? $material->material?->name),
                $this->quantityValue($material->quantity),
                $this->stringValue($material->measurement_unit)
            )))
            ->filter()
            ->values()
            ->all();

        $workers = $entry->workers
            ->take(4)
            ->map(fn ($worker): string => trim(sprintf(
                '%s %s чел. %s ч.',
                $this->stringValue($worker->specialty),
                $this->quantityValue($worker->workers_count),
                $this->quantityValue($worker->hours_worked)
            )))
            ->filter()
            ->values()
            ->all();

        $equipment = $entry->equipment
            ->take(4)
            ->map(fn ($item): string => trim(sprintf(
                '%s %s ед. %s ч.',
                $this->stringValue($item->equipment_name),
                $this->quantityValue($item->quantity),
                $this->quantityValue($item->hours_used)
            )))
            ->filter()
            ->values()
            ->all();

        $content = $this->lines([
            'Запись журнала работ: '.$this->stringValue($entry->entry_number),
            'Журнал: '.$this->stringValue($entry->journal?->journal_number).' '.$this->stringValue($entry->journal?->name),
            'Проект: '.$this->stringValue($entry->journal?->project?->name),
            'Договор: '.$this->stringValue($entry->journal?->contract?->number),
            'Дата: '.$this->dateValue($entry->entry_date),
            'Статус: '.$this->stringValue($entry->status),
            'Задача графика: '.$this->stringValue($entry->scheduleTask?->name ?? $entry->scheduleTask?->title),
            'Смета: '.$this->stringValue($entry->estimate?->number ?? $entry->estimate?->name),
            'Описание работ: '.$this->stringValue($entry->work_description),
            'Проблемы: '.$this->stringValue($entry->problems_description),
            'Качество: '.$this->stringValue($entry->quality_notes),
            'Безопасность: '.$this->stringValue($entry->safety_notes),
            'Посетители: '.$this->stringValue($entry->visitors_notes),
            'Отказ: '.$this->stringValue($entry->rejection_reason),
            'Объемы: '.implode('; ', $workVolumes),
            'Материалы: '.implode('; ', $materials),
            'Люди: '.implode('; ', $workers),
            'Техника: '.implode('; ', $equipment),
        ]);

        return new RagChunkData(
            organizationId: (int) $entry->journal->organization_id,
            projectId: $entry->journal->project_id !== null ? (int) $entry->journal->project_id : null,
            sourceType: $this->sourceType(),
            entityType: 'construction_journal_entry',
            entityId: (int) $entry->id,
            title: 'Журнал работ: '.$this->stringValue($entry->journal?->journal_number).' / '.$this->stringValue($entry->entry_number),
            content: $content,
            metadata: [
                'journal_id' => $entry->journal_id,
                'project_id' => $entry->journal?->project_id,
                'contract_id' => $entry->journal?->contract_id,
                'status' => $this->scalarValue($entry->status),
                'entry_date' => $this->dateValue($entry->entry_date),
            ],
            updatedAt: $entry->updated_at
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

    private function dateValue(mixed $value): string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : '';
    }
}
