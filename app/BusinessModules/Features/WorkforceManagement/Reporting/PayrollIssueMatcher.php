<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting;

final readonly class PayrollIssueMatcher
{
    public function forSourceRow(iterable $issues, int $sourceRowId): array
    {
        $matches = [];
        foreach ($issues as $issue) {
            if ($issue->source_row_id !== null && (int) $issue->source_row_id === $sourceRowId) {
                $matches[] = $issue;
            }
        }

        return $matches;
    }
}
