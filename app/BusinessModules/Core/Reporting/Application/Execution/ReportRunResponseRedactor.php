<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Execution;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportOutputClassification;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;

final readonly class ReportRunResponseRedactor
{
    public function redact(
        ReportRun $run,
        ReportOutputClassification $classification,
        ReportVisibility $visibility,
    ): ReportRun {
        $protectedColumnIds = [
            ...($visibility->canViewSensitive ? [] : $classification->sensitiveColumnIds),
            ...($visibility->canViewAudit ? [] : $classification->auditColumnIds),
        ];
        if ($protectedColumnIds === []) {
            return $run;
        }

        return $run->withTotals($this->redactArray($run->totals, $protectedColumnIds));
    }

    private function redactArray(array $value, array $protectedColumnIds): array
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && $this->isProtectedKey($key, $protectedColumnIds)) {
                unset($value[$key]);
                continue;
            }

            if (is_array($item)) {
                $value[$key] = $this->redactArray($item, $protectedColumnIds);
            }
        }

        return $value;
    }

    private function isProtectedKey(string $key, array $protectedColumnIds): bool
    {
        if (in_array($key, $protectedColumnIds, true)) {
            return true;
        }

        return str_ends_with($key, '_minor')
            && in_array(substr($key, 0, -6), $protectedColumnIds, true);
    }
}
