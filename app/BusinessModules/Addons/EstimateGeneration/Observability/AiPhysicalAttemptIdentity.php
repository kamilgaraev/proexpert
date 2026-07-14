<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use InvalidArgumentException;

final class AiPhysicalAttemptIdentity
{
    public static function fromParts(string $logicalAttemptId, string $model, int $ordinal, string $contract): string
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $logicalAttemptId) !== 1 || $model === '' || $ordinal < 1 || $contract === '') {
            throw new InvalidArgumentException('Invalid physical attempt identity parts.');
        }

        return AiOperationContext::deterministicId(json_encode([
            'logical_attempt_id' => strtolower($logicalAttemptId),
            'model' => $model,
            'ordinal' => $ordinal,
            'contract' => $contract,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
