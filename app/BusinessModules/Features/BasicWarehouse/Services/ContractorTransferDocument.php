<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

final class ContractorTransferDocument
{
    public static function number(int $organizationId, string $idempotencyKey, ?string $provided): string
    {
        if ($provided !== null && trim($provided) !== '') {
            return trim($provided);
        }

        // Один номер для обеих сторон и всех повторов одного запроса.
        return 'М-15-'.strtoupper(substr(hash('sha256', $organizationId.':'.$idempotencyKey), 0, 16));
    }
}
