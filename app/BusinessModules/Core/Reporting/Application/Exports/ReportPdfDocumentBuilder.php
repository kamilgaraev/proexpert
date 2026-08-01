<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Exports;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExportSource;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportExportData;
use App\BusinessModules\Core\Reporting\Application\Rows\ReportRowChunk;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeZone;
use Throwable;

final readonly class ReportPdfDocumentBuilder
{
    private const HTML_FIXED_OVERHEAD_BYTES = 16_384;

    private const HTML_ROW_OVERHEAD_BYTES = 96;

    private const HTML_CELL_OVERHEAD_BYTES = 128;

    private const HTML_ESCAPE_EXPANSION_FACTOR = 6;

    private const RETAINED_ROW_OVERHEAD_BYTES = 256;

    private const RETAINED_CELL_OVERHEAD_BYTES = 160;

    public function __construct(private ReportExportLimits $limits)
    {
    }

    /** @param iterable<ReportRowChunk> $chunks */
    public function build(
        ReportRunExportSource $source,
        CreateReportExportData $data,
        iterable $chunks,
        ReportPdfRenderBudget $budget,
        ReportArtifactStream $stream,
    ): ReportPdfDocument {
        $memoryAtStart = memory_get_usage(true);
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }

        $this->assertColumns($source, $data);
        $projectedHtmlBytes = self::HTML_FIXED_OVERHEAD_BYTES;
        $projectedRetainedBytes = self::HTML_FIXED_OVERHEAD_BYTES;
        $this->assertProjectedBudget($projectedHtmlBytes, $projectedRetainedBytes, $memoryAtStart, $budget);

        $headers = $this->headers($source, $data);
        $this->reserveProjection(
            $headers,
            $projectedHtmlBytes,
            $projectedRetainedBytes,
            $memoryAtStart,
            $budget,
        );
        $metadata = $this->metadata($source, $data);
        $this->reserveProjection(
            $metadata,
            $projectedHtmlBytes,
            $projectedRetainedBytes,
            $memoryAtStart,
            $budget,
        );
        $totals = [];
        foreach ($data->columns as $columnId) {
            if (!array_key_exists($columnId, $source->result->totals)) {
                continue;
            }

            $total = self::normalizeCell($source->result->totals[$columnId], $data->timezone);
            $this->reserveScalar(
                $total,
                $projectedHtmlBytes,
                $projectedRetainedBytes,
                $memoryAtStart,
                $budget,
            );
            $totals[$columnId] = $total;
        }

        $sourceRowCount = $source->result->metadata->rowCount;

        if ($sourceRowCount > $budget->maxDetailRows
            || $sourceRowCount > $this->limits->maxRows
            || 1 + $sourceRowCount + ($totals === [] ? 0 : 1) > $this->limits->maxWorksheetRows) {
            throw $this->limit();
        }
        $rows = [];
        $rowCount = 0;
        $startedAt = hrtime(true);

        foreach ($chunks as $chunk) {
            $this->assertChunk($source, $chunk, $stream, $startedAt, $memoryAtStart, $budget);
            $projectedRows = $rowCount + count($chunk->rows);
            if ($projectedRows > $budget->maxDetailRows || $projectedRows > $this->limits->maxRows) {
                throw $this->limit();
            }

            foreach ($chunk->rows as $row) {
                $cells = [];
                $rowHtmlBytes = self::HTML_ROW_OVERHEAD_BYTES;
                $rowRetainedBytes = self::RETAINED_ROW_OVERHEAD_BYTES;
                foreach ($data->columns as $columnId) {
                    if (!array_key_exists($columnId, $row->values)) {
                        throw $this->limit();
                    }

                    $cell = self::normalizeCell($row->values[$columnId], $data->timezone);
                    $rowHtmlBytes += self::HTML_CELL_OVERHEAD_BYTES
                        + strlen($cell) * self::HTML_ESCAPE_EXPANSION_FACTOR;
                    $rowRetainedBytes += self::RETAINED_CELL_OVERHEAD_BYTES + strlen($cell);
                    $this->assertProjectedBudget(
                        $projectedHtmlBytes + $rowHtmlBytes,
                        $projectedRetainedBytes + $rowRetainedBytes,
                        $memoryAtStart,
                        $budget,
                    );
                    $cells[] = $cell;
                }

                $projectedHtmlBytes += $rowHtmlBytes;
                $projectedRetainedBytes += $rowRetainedBytes;
                $rows[] = $cells;
            }
            unset($row);
            $rowCount = $projectedRows;
            unset($chunk);
        }

        if ($rowCount !== $source->result->metadata->rowCount) {
            throw $this->limit();
        }

        try {
            $this->assertProjectedBudget(
                $projectedHtmlBytes,
                $projectedRetainedBytes,
                $memoryAtStart,
                $budget,
            );

            return new ReportPdfDocument(
                $headers,
                $rows,
                $totals,
                $metadata,
                $projectedHtmlBytes,
                $projectedRetainedBytes,
                $memoryAtStart,
                max(0, memory_get_peak_usage(true) - $memoryAtStart),
            );
        } catch (Throwable $exception) {
            throw $this->limit($exception);
        }
    }

    public static function normalizeCell(mixed $value, DateTimeZone $timezone): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_string($value)) {
            return (string) $value;
        }
        if (is_float($value) && is_finite($value)) {
            return json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        }
        if (is_array($value)) {
            try {
                return CanonicalJson::encode($value);
            } catch (Throwable) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_EXPORT_LIMIT_EXCEEDED);
            }
        }
        throw ReportContractException::fromCode(ReportErrorCode::REPORT_EXPORT_LIMIT_EXCEEDED);
    }

    private function assertColumns(ReportRunExportSource $source, CreateReportExportData $data): void
    {
        if (count($data->columns) > $this->limits->maxColumns) {
            throw $this->limit();
        }

        $schemaIds = array_fill_keys(array_column($source->result->rowSchema, 'id'), true);
        foreach ($data->columns as $columnId) {
            if (!isset($schemaIds[$columnId])) {
                throw $this->limit();
            }
        }
    }

    private function assertChunk(
        ReportRunExportSource $source,
        mixed $chunk,
        ReportArtifactStream $stream,
        int $startedAt,
        int $memoryAtStart,
        ReportPdfRenderBudget $budget,
    ): void {
        if ($stream->cancellationRequested()
            || !$chunk instanceof ReportRowChunk
            || count($chunk->rows) > $this->limits->maxChunkRows
            || !hash_equals($source->snapshot->id, $chunk->snapshotId)
            || !hash_equals($source->run->queryHash->value, $chunk->queryHash->value)
            || !hash_equals($source->snapshot->sourceHash->value, $chunk->sourceHash->value)
            || max(0, memory_get_usage(true) - $memoryAtStart) > $budget->maxMemoryDeltaBytes
            || (hrtime(true) - $startedAt) > $this->limits->maxElapsedSeconds * 1_000_000_000) {
            throw $this->limit();
        }
    }

    private function assertProjectedBudget(
        int $projectedHtmlBytes,
        int $projectedRetainedBytes,
        int $memoryAtStart,
        ReportPdfRenderBudget $budget,
    ): void {
        $actualMemoryDelta = max(0, memory_get_peak_usage(true) - $memoryAtStart);
        if ($projectedHtmlBytes > $budget->maxHtmlBytes
            || $projectedHtmlBytes > $this->limits->maxBytes
            || $projectedRetainedBytes > $budget->maxMemoryDeltaBytes
            || $this->sumExceeds(
                [$actualMemoryDelta, $projectedHtmlBytes, $projectedRetainedBytes, $budget->maxPdfBytes],
                $budget->maxMemoryDeltaBytes,
            )) {
            throw $this->limit();
        }
    }

    private function reserveProjection(
        mixed $value,
        int &$projectedHtmlBytes,
        int &$projectedRetainedBytes,
        int $memoryAtStart,
        ReportPdfRenderBudget $budget,
    ): void {
        if (!is_array($value)) {
            $this->reserveScalar(
                self::normalizeCell($value, new DateTimeZone('UTC')),
                $projectedHtmlBytes,
                $projectedRetainedBytes,
                $memoryAtStart,
                $budget,
            );

            return;
        }

        $projectedHtmlBytes += self::HTML_ROW_OVERHEAD_BYTES;
        $projectedRetainedBytes += self::RETAINED_ROW_OVERHEAD_BYTES;
        $this->assertProjectedBudget(
            $projectedHtmlBytes,
            $projectedRetainedBytes,
            $memoryAtStart,
            $budget,
        );
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $this->reserveScalar(
                    $key,
                    $projectedHtmlBytes,
                    $projectedRetainedBytes,
                    $memoryAtStart,
                    $budget,
                );
            }
            $this->reserveProjection(
                $item,
                $projectedHtmlBytes,
                $projectedRetainedBytes,
                $memoryAtStart,
                $budget,
            );
        }
    }

    private function reserveScalar(
        string $value,
        int &$projectedHtmlBytes,
        int &$projectedRetainedBytes,
        int $memoryAtStart,
        ReportPdfRenderBudget $budget,
    ): void {
        $length = strlen($value);
        if ($length > intdiv(PHP_INT_MAX - self::HTML_CELL_OVERHEAD_BYTES, self::HTML_ESCAPE_EXPANSION_FACTOR)) {
            throw $this->limit();
        }

        $projectedHtmlBytes += self::HTML_CELL_OVERHEAD_BYTES
            + $length * self::HTML_ESCAPE_EXPANSION_FACTOR;
        $projectedRetainedBytes += self::RETAINED_CELL_OVERHEAD_BYTES + $length;
        $this->assertProjectedBudget(
            $projectedHtmlBytes,
            $projectedRetainedBytes,
            $memoryAtStart,
            $budget,
        );
    }

    /** @param list<int> $values */
    private function sumExceeds(array $values, int $limit): bool
    {
        $remaining = $limit;
        foreach ($values as $value) {
            if ($value < 0 || $value > $remaining) {
                return true;
            }

            $remaining -= $value;
        }

        return false;
    }

    private function headers(ReportRunExportSource $source, CreateReportExportData $data): array
    {
        $schema = [];
        foreach ($source->result->rowSchema as $column) {
            $schema[$column['id']] = $column;
        }

        return array_map(
            static function (string $columnId) use ($schema, $data): array {
                $column = $schema[$columnId];
                $localizedLabels = $column['labels'] ?? null;
                $label = is_array($localizedLabels) && isset($localizedLabels[$data->locale])
                    ? $localizedLabels[$data->locale]
                    : ($column['label'] ?? $columnId);

                return ['id' => $columnId, 'label' => is_string($label) && trim($label) !== '' ? $label : $columnId];
            },
            $data->columns,
        );
    }

    private function metadata(ReportRunExportSource $source, CreateReportExportData $data): array
    {
        $seal = $source->snapshot->seal;

        return [
            'report_code' => $source->run->reportCode,
            'run_id' => $source->run->id,
            'definition_hash' => $source->run->definitionHash->value,
            'query_hash' => $source->run->queryHash->value,
            'source_hash' => $source->snapshot->sourceHash->value,
            'result_hash' => $source->resultHash->value,
            'snapshot' => [
                'kind' => $source->snapshot->kind,
                'id' => $source->snapshot->id,
                'classification' => $source->snapshot->classification->value,
                'seal' => $seal === null ? null : [
                    'key_id' => $seal->keyId,
                    'algorithm' => $seal->algorithm,
                    'sealed_payload_hash' => $seal->sealedPayloadHash->value,
                    'signature' => $seal->signature,
                    'sealed_at' => $seal->sealedAt->format('Y-m-d\TH:i:s.uP'),
                ],
            ],
            'data_classification' => $source->dataClassification->value,
            'output_classification' => [
                'default' => $source->outputClassification->defaultClassification->value,
                'sensitive_columns' => $source->outputClassification->sensitiveColumnIds,
                'audit_columns' => $source->outputClassification->auditColumnIds,
                'totals_sensitive' => $source->outputClassification->totalsSensitive,
                'totals_audit' => $source->outputClassification->totalsAudit,
                'provenance_audit' => $source->outputClassification->provenanceAudit,
            ],
            'columns' => $data->columns,
            'locale' => $data->locale,
            'timezone' => $data->timezone->getName(),
            'contract_version' => $source->contractVersion,
            'formula_version' => $source->formulaVersion,
            'source_schema_version' => $source->sourceSchemaVersion,
            'renderer_version' => $source->rendererVersion,
        ];
    }

    private function limit(?Throwable $previous = null): ReportContractException
    {
        return ReportContractException::fromCode(
            ReportErrorCode::REPORT_EXPORT_LIMIT_EXCEEDED,
            previous: $previous,
        );
    }
}
