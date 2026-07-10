<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourcePrunerInterface;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use BackedEnum;
use DateTimeInterface;

final class SiteRequestRagSource implements RagSourceCollectorInterface, RagSourcePrunerInterface
{
    public function sourceType(): string
    {
        return 'site_request';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
        $query = SiteRequest::query()
            ->with(['project', 'user', 'assignedUser'])
            ->where('organization_id', $organizationId)
            ->where('status', '!=', SiteRequestStatusEnum::DRAFT->value)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('id');

        foreach ($query->cursor() as $request) {
            yield $this->chunk($request);
        }
    }

    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        if ($entityType !== 'site_request') {
            return [];
        }

        $request = SiteRequest::query()
            ->with(['project', 'user', 'assignedUser'])
            ->where('organization_id', $organizationId)
            ->where('status', '!=', SiteRequestStatusEnum::DRAFT->value)
            ->where('id', $entityId)
            ->first();

        return $request instanceof SiteRequest ? [$this->chunk($request)] : [];
    }

    public function pruneForOrganization(int $organizationId, ?int $projectId = null): int
    {
        $sources = RagSource::query()
            ->where('organization_id', $organizationId)
            ->where('source_type', $this->sourceType())
            ->where('entity_type', 'site_request')
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->get();

        if ($sources->isEmpty()) {
            return 0;
        }

        $entityIds = $sources
            ->pluck('entity_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        $indexableIds = SiteRequest::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $entityIds)
            ->where('status', '!=', SiteRequestStatusEnum::DRAFT->value)
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
        $indexableLookup = array_fill_keys($indexableIds, true);
        $pruned = 0;

        foreach ($sources as $source) {
            if (isset($indexableLookup[(string) $source->entity_id])) {
                continue;
            }

            $source->chunks()->delete();
            $source->delete();
            $pruned++;
        }

        return $pruned;
    }

    private function chunk(SiteRequest $request): RagChunkData
    {
        $content = $this->lines([
            'Заявка с объекта: '.$this->stringValue($request->title),
            'Проект: '.$this->stringValue($request->project?->name),
            'Тип: '.$this->stringValue($request->request_type),
            'Статус: '.$this->stringValue($request->status),
            'Приоритет: '.$this->stringValue($request->priority),
            'Требуется к дате: '.$this->dateValue($request->required_date),
            'Материал: '.$this->stringValue($request->material_name),
            'Количество материала: '.$this->quantityValue($request->material_quantity).' '.$this->stringValue($request->material_unit),
            'Исполнитель: '.$this->stringValue($request->assignedUser?->name),
            'Описание: '.$this->stringValue($request->description),
            'Примечания: '.$this->stringValue($request->notes),
        ]);

        return new RagChunkData(
            organizationId: (int) $request->organization_id,
            projectId: (int) $request->project_id,
            sourceType: $this->sourceType(),
            entityType: 'site_request',
            entityId: (int) $request->id,
            title: 'Заявка: '.$this->stringValue($request->title),
            content: $content,
            metadata: [
                'status' => $this->scalarValue($request->status),
                'priority' => $this->scalarValue($request->priority),
                'request_type' => $this->scalarValue($request->request_type),
                'assigned_to' => $request->assigned_to,
            ],
            updatedAt: $request->updated_at
        );
    }

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
