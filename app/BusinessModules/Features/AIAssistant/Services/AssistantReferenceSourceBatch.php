<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantFinanceTenderSourceSchema;
use Closure;

final class AssistantReferenceSourceBatch
{
    private const ACCESS_FIELDS = ['organization_id', 'source_type', 'entity_type', 'entity_id', 'project_id'];

    private function __construct(
        private readonly int $organizationId,
        private readonly array $ids,
        private readonly array $sources,
        private readonly ?Closure $checkpoint,
    ) {}

    public static function load(int $organizationId, array $referenceSets, ?callable $checkpoint): self
    {
        $checkpoint = $checkpoint === null ? null : Closure::fromCallable($checkpoint);
        $ids = [];
        foreach ($referenceSets as $references) {
            if (! is_array($references)) { continue; }
            foreach ($references as $reference) {
                if (! is_array($reference)) { continue; }
                $id = self::sourceId($reference);
                if ($id !== null) { $ids[(string) $id] = $id; }
            }
        }
        $sources = [];
        foreach (array_chunk(array_values($ids), 250) as $batch) {
            $checkpoint?->__invoke();
            $sources += RagSource::query()->where('organization_id', $organizationId)->whereKey($batch)->get()->keyBy('id')->all();
        }

        return new self($organizationId, $ids, $sources, $checkpoint);
    }

    public function lookup(mixed $id): mixed
    {
        if ((is_int($id) || is_string($id)) && array_key_exists((string) $id, $this->ids)) {
            return $this->sources[(string) $id] ?? null;
        }

        return RagSource::query()->where('organization_id', $this->organizationId)->find($id);
    }

    public function invalidIds(): array
    {
        $valid = [];
        foreach (array_chunk(array_values($this->ids), 250) as $batch) {
            $this->checkpoint?->__invoke();
            foreach (RagSource::query()->where('organization_id', $this->organizationId)->whereKey($batch)->get() as $source) {
                $previous = $this->sources[(string) $source->id] ?? null;
                if ($previous !== null && $previous->only(self::ACCESS_FIELDS) === $source->only(self::ACCESS_FIELDS)
                    && AssistantFinanceTenderSourceSchema::allowsSource($source->toArray())) {
                    $valid[(string) $source->id] = true;
                }
            }
        }

        return array_diff_key($this->ids, $valid);
    }

    public static function sourceId(array $reference): string|int|null
    {
        $type = $reference['entity_type'] ?? $reference['entityType'] ?? $reference['type'] ?? null;
        if (in_array($type, ['live_project_financial_projection', 'published_report_financial_projection'], true)) { return null; }
        $id = $reference['source_id'] ?? null;

        return is_int($id) || (is_string($id) && (string) (int) $id === $id) ? $id : null;
    }
}
