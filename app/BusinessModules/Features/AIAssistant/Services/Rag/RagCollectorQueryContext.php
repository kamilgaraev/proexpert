<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantCoreBusinessMetadata;
use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantSalesBusinessMetadata;
use Generator;

final class RagCollectorQueryContext
{
    public function __construct(
        private readonly string $sourceType,
        private readonly string $entityType,
    ) {}

    public function iterate(iterable $rows): Generator
    {
        foreach ($rows as $key => $row) {
            yield $key => $row;
        }
    }

    public static function current(): ?array
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 96) as $frame) {
            if (($frame['function'] ?? null) === 'iterate' && ($frame['object'] ?? null) instanceof self) {
                return $frame['object']->identity();
            }
        }

        return null;
    }

    private function identity(): ?array
    {
        $core = AssistantCoreBusinessMetadata::records()[$this->entityType] ?? null;
        $valid = $core !== null && $core['indexed'] === true && $core['source'] === $this->sourceType;
        if (! $valid) {
            $sales = AssistantSalesBusinessMetadata::recordDefinitions()[$this->entityType] ?? null;
            $valid = $sales !== null && $sales['source'] === $this->sourceType;
        }

        return $valid ? ['source_type' => $this->sourceType, 'entity_type' => $this->entityType, 'phase' => 'collection_page'] : null;
    }
}
