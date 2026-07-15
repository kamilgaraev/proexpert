<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

final class DocumentProcessingProgress
{
    /** @param array<string, mixed> $summary */
    public static function fromSummary(array $summary, int $storedProgress): int
    {
        $total = max(0, (int) ($summary['total'] ?? 0));
        if ($total === 0) {
            return max(0, min(100, $storedProgress));
        }

        $terminal = min($total, max(0,
            (int) ($summary['ready'] ?? 0)
            + (int) ($summary['action_required'] ?? 0)
            + (int) ($summary['ignored'] ?? 0),
        ));
        $projected = 5 + (int) round(30 * $terminal / $total);

        return max(5, min(35, max($storedProgress, $projected)));
    }
}
