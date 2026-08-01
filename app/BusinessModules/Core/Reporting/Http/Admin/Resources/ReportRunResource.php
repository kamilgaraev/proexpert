<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Resources;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCoverage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProvenance;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWarning;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ReportRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        assert($this->resource instanceof ReportRun);

        return [
            'id' => $this->resource->id,
            'report_code' => $this->resource->reportCode,
            'status' => $this->resource->status->value,
            'definition_hash' => $this->resource->definitionHash->value,
            'contract_version' => $this->resource->contractVersion,
            'formula_version' => $this->resource->formulaVersion,
            'source_schema_version' => $this->resource->sourceSchemaVersion,
            'renderer_version' => $this->resource->rendererVersion,
            'query_hash' => $this->resource->queryHash->value,
            'source_hash' => $this->resource->sourceHash?->value,
            'progress' => $this->resource->progress,
            'row_count' => $this->resource->rowCount,
            'freshness' => $this->resource->freshness?->value,
            'totals' => $this->resource->totals,
            'quality' => $this->resource->quality === null ? null : $this->quality($this->resource->quality),
            'provenance' => $this->resource->provenance === null ? null : $this->provenance($this->resource->provenance),
            'created_at' => $this->resource->createdAt->format(DATE_ATOM),
            'updated_at' => $this->resource->updatedAt->format(DATE_ATOM),
            'ready_at' => $this->resource->readyAt?->format(DATE_ATOM),
            'expires_at' => $this->resource->expiresAt->format(DATE_ATOM),
            'cancel_requested_at' => $this->resource->cancelRequestedAt?->format(DATE_ATOM),
            'poll_after_ms' => $this->resource->pollAfterMs,
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

    private function provenance(ReportProvenance $provenance): array
    {
        return [
            'source_of_truth' => $provenance->sourceOfTruth,
            'source_refs' => array_map($this->sourceRef(...), $provenance->sourceRefs),
            'source_hash' => $provenance->sourceHash->value,
            'external_confirmation_role' => $provenance->externalConfirmationRole,
        ];
    }

    private function sourceRef(ReportSourceRef $sourceRef): array
    {
        return [
            'source' => $sourceRef->source,
            'snapshot_kind' => $sourceRef->snapshotKind,
            'snapshot_id' => $sourceRef->snapshotId,
            'schema_version' => $sourceRef->schemaVersion,
            'watermark' => $sourceRef->watermark,
            'row_count' => $sourceRef->rowCount,
            'hash' => $sourceRef->hash->value,
        ];
    }
}
