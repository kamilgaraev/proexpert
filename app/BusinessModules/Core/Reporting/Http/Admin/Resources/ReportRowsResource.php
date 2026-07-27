<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Resources;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCoverage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWarning;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ReportRowsResource extends JsonResource
{
    public function __construct(ReportPage $resource)
    {
        parent::__construct($resource);
        $this->additional([
            'limit' => $resource->limit,
            'next_cursor' => $resource->nextCursor,
            'has_more' => $resource->hasMore,
            'sort' => ['field' => $resource->sort->field, 'direction' => $resource->sort->direction->value],
        ]);
    }

    public function toArray(Request $request): array
    {
        assert($this->resource instanceof ReportPage);

        return [
            'rows' => $this->resource->rows,
            'totals' => $this->resource->totals,
            'freshness' => $this->resource->freshness->value,
            'quality' => $this->quality($this->resource->quality),
        ];
    }

    private function quality(ReportQuality $quality): array
    {
        return [
            'status' => $quality->status->value,
            'coverage' => $quality->coverage === null ? null : $this->coverage($quality->coverage),
            'warnings' => array_map($this->warning(...), $quality->warnings),
            'unmatched_count' => $quality->unmatchedCount,
            'reconciliation' => $quality->reconciliation->value,
            'unknown_metrics' => $quality->unknownMetrics,
            'excluded_sources' => $quality->excludedSources,
        ];
    }

    private function coverage(ReportCoverage $coverage): array
    {
        return ['numerator' => $coverage->numerator, 'denominator' => $coverage->denominator, 'ratio' => $coverage->ratio];
    }

    private function warning(ReportWarning $warning): array
    {
        return ['code' => $warning->code, 'severity' => $warning->severity->value, 'metric' => $warning->metric, 'affected_row_count' => $warning->affectedRowCount];
    }
}
