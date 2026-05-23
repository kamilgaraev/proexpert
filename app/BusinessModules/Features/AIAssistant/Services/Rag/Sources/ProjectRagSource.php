<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\Models\Project;
use BackedEnum;
use DateTimeInterface;
use Throwable;

final class ProjectRagSource implements RagSourceCollectorInterface
{
    public function sourceType(): string
    {
        return 'project';
    }

    public function enabled(): bool
    {
        try {
            return (bool) config('ai-assistant.rag.enabled', false);
        } catch (Throwable) {
            return false;
        }
    }

    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
        $query = Project::query()
            ->where('organization_id', $organizationId)
            ->when($projectId !== null, static fn ($query) => $query->where('id', $projectId))
            ->orderBy('id');

        foreach ($query->cursor() as $project) {
            yield $this->chunk($project);
        }
    }

    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        if ($entityType !== 'project') {
            return [];
        }

        $project = Project::query()
            ->where('organization_id', $organizationId)
            ->where('id', $entityId)
            ->first();

        return $project instanceof Project ? [$this->chunk($project)] : [];
    }

    private function chunk(Project $project): RagChunkData
    {
        $content = implode("\n", array_filter([
            "Проект: {$project->name}",
            'Статус: '.$this->stringValue($project->status),
            'Адрес: '.$this->stringValue($project->address),
            'Заказчик: '.$this->stringValue($project->customer ?? $project->customer_organization),
            'Бюджет: '.$this->moneyValue($project->budget_amount),
            'Период: '.$this->dateValue($project->start_date).' - '.$this->dateValue($project->end_date),
            'Описание: '.$this->stringValue($project->description),
        ], static fn (string $line): bool => ! str_ends_with($line, ': ') && ! str_ends_with($line, ': -')));

        return new RagChunkData(
            organizationId: (int) $project->organization_id,
            projectId: (int) $project->id,
            sourceType: $this->sourceType(),
            entityType: 'project',
            entityId: (int) $project->id,
            title: 'Проект: '.$project->name,
            content: $content,
            metadata: [
                'status' => $this->scalarValue($project->status),
                'external_code' => $project->external_code,
                'customer' => $project->customer ?? $project->customer_organization,
            ],
            updatedAt: $project->updated_at
        );
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

    private function moneyValue(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 2, '.', ' ') : '';
    }

    private function dateValue(mixed $value): string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : '';
    }
}
