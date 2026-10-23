<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use App\BusinessModules\Features\AIAssistant\Services\AssistantEntityAccessCatalog;
use App\Services\Monitoring\RagSourceBatchIdentityMetadata;

final class RagSourceBatchQueryContext
{
    public function __construct(
        private readonly int $ordinal,
        private readonly bool $expectedProjection,
        private readonly ?array $sourceTypes,
        private readonly ?string $entityType,
        private readonly ?array $candidateIdentities = null,
    ) {}

    public function run(callable $operation): mixed
    {
        return $operation();
    }

    public static function current(): ?array
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 96) as $frame) {
            if (($frame['function'] ?? null) === 'run' && ($frame['object'] ?? null) instanceof self) {
                return $frame['object']->metadata();
            }
        }

        return null;
    }

    private function metadata(): ?array
    {
        if ($this->ordinal < 1 || ($this->sourceTypes !== null && count($this->sourceTypes) > 8)) {
            return null;
        }
        $definitions = AssistantEntityAccessCatalog::entityDefinitions();
        $allowedSources = ['file_document'];
        foreach ($definitions as $entity => $definition) {
            $allowedSources = array_merge($allowedSources, AssistantEntityAccessCatalog::indexedSourceTypes($entity, $definition[0]));
        }
        foreach ($this->sourceTypes ?? [] as $source) {
            if (! is_string($source) || ! in_array($source, $allowedSources, true)) {
                return null;
            }
        }
        if ($this->entityType !== null) {
            $canonical = $definitions[$this->entityType][0] ?? null;
            $entitySources = $this->entityType === 'assistant_document' ? ['file_document']
                : (is_string($canonical) ? AssistantEntityAccessCatalog::indexedSourceTypes($this->entityType, $canonical) : []);
            if ($entitySources === [] || ($this->sourceTypes !== null && array_diff($this->sourceTypes, $entitySources) !== [])) {
                return null;
            }
        }

        $metadata = ['aggregation_ordinal' => $this->ordinal, 'mode' => $this->expectedProjection ? 'expected' : 'stored',
            'phase' => 'source_batch_consume', 'source_types' => $this->sourceTypes === null ? null : array_values($this->sourceTypes),
            'entity_type' => $this->entityType];
        if ($this->candidateIdentities !== null) {
            try {
                [$pairs, $complete] = RagSourceBatchIdentityMetadata::fromIdentities($this->candidateIdentities);
                $scopedPairs = array_values(array_filter($pairs, fn (array $pair): bool =>
                    ($this->sourceTypes === null || in_array($pair['source_type'], $this->sourceTypes, true))
                        && ($this->entityType === null || $pair['entity_type'] === $this->entityType)));
            } catch (\Throwable) {
                $pairs = $scopedPairs = [];
                $complete = false;
            }
            $metadata['candidate_identities'] = $scopedPairs;
            $metadata['candidate_identities_complete'] = $complete && count($pairs) === count($scopedPairs);
        }

        return $metadata;
    }
}
