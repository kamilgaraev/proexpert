<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportDataClassification;
use InvalidArgumentException;

final readonly class ReportOutputClassification
{
    public array $sensitiveColumnIds;

    public array $auditColumnIds;

    public function __construct(
        public ReportDataClassification $defaultClassification,
        array $sensitiveColumnIds,
        array $auditColumnIds,
        public bool $totalsSensitive,
        public bool $totalsAudit,
        public bool $provenanceAudit,
    ) {
        $this->sensitiveColumnIds = self::normalizeColumnIds($sensitiveColumnIds);
        $this->auditColumnIds = self::normalizeColumnIds($auditColumnIds);
    }

    public function requiresSensitiveForRows(): bool
    {
        return $this->defaultClassification === ReportDataClassification::SENSITIVE
            || $this->totalsSensitive;
    }

    public function requiresAuditForRows(): bool
    {
        return $this->totalsAudit;
    }

    public function requiresSensitiveForColumns(array $columnIds): bool
    {
        $selected = self::normalizeColumnIds($columnIds);

        return $this->defaultClassification === ReportDataClassification::SENSITIVE
            || array_intersect($selected, $this->sensitiveColumnIds) !== [];
    }

    public function requiresAuditForColumns(array $columnIds): bool
    {
        return array_intersect(self::normalizeColumnIds($columnIds), $this->auditColumnIds) !== [];
    }

    public function requiresSensitiveForSummary(): bool
    {
        return $this->defaultClassification === ReportDataClassification::SENSITIVE
            || $this->totalsSensitive;
    }

    public function requiresAuditForSummary(): bool
    {
        return $this->totalsAudit || $this->provenanceAudit;
    }

    private static function normalizeColumnIds(array $columnIds): array
    {
        if (!array_is_list($columnIds)) {
            throw new InvalidArgumentException('report_output_classification_invalid');
        }

        $seen = [];
        foreach ($columnIds as $columnId) {
            if (!is_string($columnId)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $columnId) !== 1
                || isset($seen[$columnId])) {
                throw new InvalidArgumentException('report_output_classification_invalid');
            }

            $seen[$columnId] = true;
        }

        sort($columnIds, SORT_STRING);

        return $columnIds;
    }
}
