<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;

final class SheetAnalysisOperationIdentity
{
    public static function primary(int $sessionId, int $documentId, int $unitId, string $sourceVersion, string $derivativeHash): string
    {
        return AiOperationContext::deterministicId(implode('|', [
            'vision-unit', $sessionId, $documentId, $unitId, $sourceVersion, $derivativeHash, 'vision-contract:v3',
        ]));
    }

    /** @param array{role: string, reanalysis_reason: ?string} $routing */
    public static function targeted(int $sessionId, int $documentId, int $unitId, string $sourceVersion, string $derivativeHash, array $routing): string
    {
        return AiOperationContext::deterministicId(implode('|', [
            'sheet-targeted', $sessionId, $documentId, $unitId, $sourceVersion, $derivativeHash,
            'sheet-routing:v1', $routing['role'], $routing['reanalysis_reason'] ?? '', 'vision-contract:v3',
        ]));
    }
}
