<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use InvalidArgumentException;

final readonly class ReportSourceBackfillContext
{
    public array $projectIds;

    public function __construct(
        public int $organizationId,
        public string $reportCode,
        array $projectIds,
        public string $scopeHash,
        public string $sourceWatermark,
    ) {
        if ($organizationId < 1
            || preg_match('/^[a-z][a-z0-9_]{2,95}$/D', $reportCode) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $scopeHash) !== 1
            || preg_match('/^[a-z][a-z0-9_-]{0,31}:[A-Za-z0-9._-]{1,160}$/D', $sourceWatermark) !== 1
            || ! array_is_list($projectIds)
            || $projectIds === []
        ) {
            throw new InvalidArgumentException('report_source_backfill_context_invalid');
        }

        $normalized = [];
        foreach ($projectIds as $projectId) {
            if (! is_int($projectId) || $projectId < 1 || isset($normalized[$projectId])) {
                throw new InvalidArgumentException('report_source_backfill_context_invalid');
            }
            $normalized[$projectId] = $projectId;
        }
        $projectIds = array_values($normalized);
        sort($projectIds, SORT_NUMERIC);
        $this->projectIds = $projectIds;
    }
}
