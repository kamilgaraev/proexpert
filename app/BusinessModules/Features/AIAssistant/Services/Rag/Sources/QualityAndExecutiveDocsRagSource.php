<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use BackedEnum;
use DateTimeInterface;

final class QualityAndExecutiveDocsRagSource implements RagSourceCollectorInterface
{
    public function sourceType(): string
    {
        return 'quality_executive_docs';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
        foreach ($this->qualityDefects($organizationId, $projectId)->cursor() as $defect) {
            yield $this->qualityDefectChunk($defect);
        }

        foreach ($this->documentSets($organizationId, $projectId)->cursor() as $set) {
            yield $this->documentSetChunk($set);
        }

        foreach ($this->documents($organizationId, $projectId)->cursor() as $document) {
            yield $this->documentChunk($document);
        }
    }

    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        return match ($entityType) {
            'quality_defect' => $this->collectQualityDefect($organizationId, $entityId),
            'executive_document_set' => $this->collectDocumentSet($organizationId, $entityId),
            'executive_document' => $this->collectDocument($organizationId, $entityId),
            default => [],
        };
    }

    private function collectQualityDefect(int $organizationId, string|int $entityId): array
    {
        $defect = $this->qualityDefects($organizationId, null)
            ->where('id', $entityId)
            ->first();

        return $defect instanceof QualityDefect ? [$this->qualityDefectChunk($defect)] : [];
    }

    private function collectDocumentSet(int $organizationId, string|int $entityId): array
    {
        $set = $this->documentSets($organizationId, null)
            ->where('id', $entityId)
            ->first();

        return $set instanceof ExecutiveDocumentSet ? [$this->documentSetChunk($set)] : [];
    }

    private function collectDocument(int $organizationId, string|int $entityId): array
    {
        $document = $this->documents($organizationId, null)
            ->where('id', $entityId)
            ->first();

        return $document instanceof ExecutiveDocument ? [$this->documentChunk($document)] : [];
    }

    private function qualityDefects(int $organizationId, ?int $projectId)
    {
        return QualityDefect::query()
            ->with(['project', 'contractor', 'assignedUser', 'createdBy'])
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id');
    }

    private function documentSets(int $organizationId, ?int $projectId)
    {
        return ExecutiveDocumentSet::query()
            ->with(['project', 'createdBy', 'documents', 'transmittal'])
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id');
    }

    private function documents(int $organizationId, ?int $projectId)
    {
        return ExecutiveDocument::query()
            ->with([
                'project',
                'documentSet',
                'createdBy',
                'workType',
                'journalEntry.journal',
                'openRemarks',
            ])
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id');
    }

    private function qualityDefectChunk(QualityDefect $defect): RagChunkData
    {
        $content = $this->lines([
            'Дефект качества: '.$this->stringValue($defect->defect_number).' '.$this->stringValue($defect->title),
            'Проект: '.$this->stringValue($defect->project?->name),
            'Подрядчик: '.$this->stringValue($defect->contractor?->name),
            'Статус: '.$this->stringValue($defect->status),
            'Критичность: '.$this->stringValue($defect->severity),
            'Локация: '.$this->stringValue($defect->location_name),
            'Ответственный: '.$this->stringValue($defect->assignedUser?->name),
            'Срок устранения: '.$this->dateValue($defect->due_date),
            'Требует проверки: '.($defect->inspection_required ? 'да' : 'нет'),
            'Устранен: '.$this->dateValue($defect->resolved_at),
            'Проверен: '.$this->dateValue($defect->verified_at),
            'Описание: '.$this->stringValue($defect->description),
        ]);

        return new RagChunkData(
            organizationId: (int) $defect->organization_id,
            projectId: $defect->project_id !== null ? (int) $defect->project_id : null,
            sourceType: $this->sourceType(),
            entityType: 'quality_defect',
            entityId: (int) $defect->id,
            title: 'Дефект: '.$this->stringValue($defect->defect_number ?? $defect->title),
            content: $content,
            metadata: [
                'project_id' => $defect->project_id,
                'status' => $this->scalarValue($defect->status),
                'severity' => $this->scalarValue($defect->severity),
                'assigned_to' => $defect->assigned_to,
                'due_date' => $this->dateValue($defect->due_date),
            ],
            updatedAt: $defect->updated_at
        );
    }

    private function documentSetChunk(ExecutiveDocumentSet $set): RagChunkData
    {
        $content = $this->lines([
            'Комплект исполнительной документации: '.$this->stringValue($set->set_number).' '.$this->stringValue($set->title),
            'Проект: '.$this->stringValue($set->project?->name),
            'Статус: '.$this->stringValue($set->status),
            'Этап: '.$this->stringValue($set->stage_name),
            'Зона: '.$this->stringValue($set->zone_name),
            'Плановая передача: '.$this->dateValue($set->planned_transmittal_date),
            'Передан: '.$this->dateValue($set->transmitted_at),
            'Документов: '.$this->stringValue($set->documents->count()),
        ]);

        return new RagChunkData(
            organizationId: (int) $set->organization_id,
            projectId: $set->project_id !== null ? (int) $set->project_id : null,
            sourceType: $this->sourceType(),
            entityType: 'executive_document_set',
            entityId: (int) $set->id,
            title: 'Комплект ИД: '.$this->stringValue($set->set_number ?? $set->title),
            content: $content,
            metadata: [
                'project_id' => $set->project_id,
                'status' => $this->scalarValue($set->status),
                'documents_count' => $set->documents->count(),
                'planned_transmittal_date' => $this->dateValue($set->planned_transmittal_date),
            ],
            updatedAt: $set->updated_at
        );
    }

    private function documentChunk(ExecutiveDocument $document): RagChunkData
    {
        $remarks = $document->openRemarks
            ->take(5)
            ->map(fn ($remark): string => trim(sprintf(
                '%s %s',
                $this->stringValue($remark->severity),
                $this->stringValue($remark->body)
            )))
            ->filter()
            ->values()
            ->all();

        $content = $this->lines([
            'Исполнительный документ: '.$this->stringValue($document->title),
            'Комплект: '.$this->stringValue($document->documentSet?->set_number).' '.$this->stringValue($document->documentSet?->title),
            'Проект: '.$this->stringValue($document->project?->name),
            'Тип: '.$this->stringValue($document->document_type),
            'Статус: '.$this->stringValue($document->status),
            'Вид работ: '.$this->stringValue($document->work_type_name ?? $document->workType?->name),
            'Раздел: '.$this->stringValue($document->section_name),
            'Дата документа: '.$this->dateValue($document->document_date),
            'Дата освидетельствования: '.$this->dateValue($document->inspection_date),
            'Журнал: '.$this->stringValue($document->journalEntry?->journal?->journal_number).' / '.$this->stringValue($document->journalEntry?->entry_number),
            'Подан: '.$this->dateValue($document->submitted_at),
            'Утвержден: '.$this->dateValue($document->approved_at),
            'Открытые замечания: '.implode('; ', $remarks),
        ]);

        return new RagChunkData(
            organizationId: (int) $document->organization_id,
            projectId: $document->project_id !== null ? (int) $document->project_id : null,
            sourceType: $this->sourceType(),
            entityType: 'executive_document',
            entityId: (int) $document->id,
            title: 'Исполнительный документ: '.$this->stringValue($document->title),
            content: $content,
            metadata: [
                'project_id' => $document->project_id,
                'document_set_id' => $document->document_set_id,
                'status' => $this->scalarValue($document->status),
                'document_type' => $this->scalarValue($document->document_type),
                'open_remarks_count' => $document->openRemarks->count(),
            ],
            updatedAt: $document->updated_at
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

    private function dateValue(mixed $value): string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : '';
    }
}
