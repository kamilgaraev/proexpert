<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;

final class SheetAnalysisOperationIdentity
{
    public static function primary(
        int $sessionId,
        int $documentId,
        int $unitId,
        string $sourceVersion,
        string $derivativeHash,
        string $processingAttemptId,
    ): string {
        return AiOperationContext::deterministicId(implode('|', [
            'vision-unit', $sessionId, $documentId, $unitId, $sourceVersion, $derivativeHash,
            'processing-attempt:v1', $processingAttemptId, 'vision-contract:v3',
        ]));
    }

    /** @param array{role: string, reanalysis_reason: ?string, targeted_scope?: array<string, mixed>} $routing */
    public static function targeted(
        int $sessionId,
        int $documentId,
        int $unitId,
        string $sourceVersion,
        string $derivativeHash,
        string $processingAttemptId,
        array $routing,
    ): string {
        return AiOperationContext::deterministicId(implode('|', [
            'sheet-targeted', $sessionId, $documentId, $unitId, $sourceVersion, $derivativeHash,
            'processing-attempt:v1', $processingAttemptId,
            'sheet-routing:v1', $routing['role'], $routing['reanalysis_reason'] ?? '',
            hash('sha256', json_encode($routing['targeted_scope'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'vision-contract:v3',
        ]));
    }
}
