<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationLearningExample;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationLearningSourceTrustPolicy;
use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourcePrunerInterface;
use DateTimeInterface;

final class EstimateGenerationLearningRagSource implements RagSourceCollectorInterface, RagSourcePrunerInterface
{
    public function sourceType(): string
    {
        return 'estimate_generation_learning';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
        $query = EstimateGenerationLearningExample::query()
            ->where('organization_id', $organizationId)
            ->whereIn('source_type', EstimateGenerationLearningSourceTrustPolicy::trustedSourceTypes())
            ->when($projectId !== null, static function ($query) use ($projectId): void {
                $query->where(static function ($query) use ($projectId): void {
                    $query->where('project_id', $projectId)
                        ->orWhereNull('project_id');
                });
            })
            ->orderBy('id');

        foreach ($query->cursor() as $example) {
            if ($this->indexable($example)) {
                yield $this->chunk($example);
            }
        }
    }

    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        if ($entityType !== 'estimate_generation_learning_example') {
            return [];
        }

        $example = EstimateGenerationLearningExample::query()
            ->where('organization_id', $organizationId)
            ->whereIn('source_type', EstimateGenerationLearningSourceTrustPolicy::trustedSourceTypes())
            ->whereKey($entityId)
            ->first();

        return $example instanceof EstimateGenerationLearningExample && $this->indexable($example)
            ? [$this->chunk($example)]
            : [];
    }

    public function pruneForOrganization(int $organizationId, ?int $projectId = null): int
    {
        $sources = RagSource::query()
            ->where('organization_id', $organizationId)
            ->where('source_type', $this->sourceType())
            ->where('entity_type', 'estimate_generation_learning_example')
            ->when($projectId !== null, static function ($query) use ($projectId): void {
                $query->where(static function ($query) use ($projectId): void {
                    $query->where('project_id', $projectId)
                        ->orWhereNull('project_id');
                });
            })
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
        $examples = EstimateGenerationLearningExample::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $entityIds)
            ->get()
            ->keyBy(static fn (EstimateGenerationLearningExample $example): string => (string) $example->id);
        $pruned = 0;

        foreach ($sources as $source) {
            $example = $examples->get((string) $source->entity_id);

            if ($example instanceof EstimateGenerationLearningExample && $this->indexable($example)) {
                continue;
            }

            $source->chunks()->delete();
            $source->delete();
            $pruned++;
        }

        return $pruned;
    }

    private function chunk(EstimateGenerationLearningExample $example): RagChunkData
    {
        $intent = is_array($example->work_intent) ? $example->work_intent : [];
        $context = is_array($example->context_payload) ? $example->context_payload : [];
        $content = $this->lines([
            'work_name=' . $this->stringValue($example->work_name),
            'work_unit=' . $this->stringValue($example->work_unit),
            'work_quantity=' . $this->quantityValue($example->work_quantity),
            'scope=' . $this->stringValue($intent['scope'] ?? null),
            'action=' . $this->stringValue($intent['action'] ?? null),
            'system=' . $this->stringValue($intent['system'] ?? null),
            'normative_code=' . $this->stringValue($example->norm_code),
            'normative_name=' . $this->stringValue($example->normative_name),
            'normative_unit=' . $this->stringValue($example->normative_unit),
            'decision_status=' . $this->stringValue($example->decision_status),
            'source_type=' . $this->stringValue($example->source_type),
            'evidence=' . ((bool) $example->is_positive ? 'positive' : 'negative'),
            'section=' . $this->stringValue($context['section_name'] ?? $context['section_title'] ?? null),
        ]);

        return new RagChunkData(
            organizationId: (int) $example->organization_id,
            projectId: $example->project_id !== null ? (int) $example->project_id : null,
            sourceType: $this->sourceType(),
            entityType: 'estimate_generation_learning_example',
            entityId: (int) $example->id,
            title: 'FSNB learning: ' . $this->stringValue($example->norm_code),
            content: $content,
            metadata: [
                'learning_example_id' => (int) $example->id,
                'source_type' => (string) $example->source_type,
                'normative_code' => (string) $example->norm_code,
                'work_intent' => $intent,
                'is_positive' => (bool) $example->is_positive,
                'decision_status' => (string) $example->decision_status,
                'source_quality_score' => $this->numericValue($example->source_quality_score),
            ],
            updatedAt: $this->updatedAt($example),
        );
    }

    private function indexable(EstimateGenerationLearningExample $example): bool
    {
        return EstimateGenerationLearningSourceTrustPolicy::isIndexable($example);
    }

    /**
     * @param array<int, string> $lines
     */
    private function lines(array $lines): string
    {
        return implode("\n", array_filter($lines, static fn (string $line): bool => !str_ends_with($line, '=')));
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function quantityValue(mixed $value): string
    {
        return is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.') : '';
    }

    private function numericValue(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function updatedAt(EstimateGenerationLearningExample $example): ?DateTimeInterface
    {
        return $example->indexed_at ?? $example->updated_at;
    }
}
