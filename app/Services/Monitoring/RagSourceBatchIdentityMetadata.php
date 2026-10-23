<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\BusinessModules\Features\AIAssistant\Services\AssistantEntityAccessCatalog;

final class RagSourceBatchIdentityMetadata
{
    private const MAX_BATCH_IDENTITIES = 32;

    public static function fromIdentities(array $identities): array
    {
        $definitions = AssistantEntityAccessCatalog::entityDefinitions();
        $pairs = [];
        $complete = true;
        $entitiesExamined = 0;
        $pairsExamined = 0;
        foreach ($identities as $entityType => $sources) {
            if (++$entitiesExamined > 128) { $complete = false; break; }
            if (! is_string($entityType) || preg_match('/^[a-z][a-z0-9_]{0,127}$/D', $entityType) !== 1
                || ! is_array($sources)) { $complete = false; continue; }
            $canonicalSource = $definitions[$entityType][0] ?? null;
            $allowedSources = $entityType === 'assistant_document' ? ['file_document']
                : (is_string($canonicalSource) ? AssistantEntityAccessCatalog::indexedSourceTypes($entityType, $canonicalSource) : []);
            foreach ($sources as $sourceType => $_) {
                if (++$pairsExamined > 128) { $complete = false; break 2; }
                if (! is_string($sourceType) || preg_match('/^[a-z][a-z0-9_]{0,127}$/D', $sourceType) !== 1
                    || ! in_array($sourceType, $allowedSources, true)) { $complete = false; continue; }
                if (count($pairs) >= self::MAX_BATCH_IDENTITIES) { $complete = false; break 2; }
                $pairs[] = ['entity_type' => $entityType, 'source_type' => $sourceType];
            }
        }

        return [$pairs, $complete];
    }
}
