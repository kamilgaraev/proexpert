<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Exports;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExportSource;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportArtifactStream;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportLimits;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportRenderer;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfDocumentBuilder;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportExportData;
use App\BusinessModules\Core\Reporting\Application\Rows\ReportRowChunk;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use Throwable;

final class CsvReportExportRenderer implements ReportExportRenderer
{
    public const MIME_TYPE = 'text/csv; charset=UTF-8';

    private readonly ReportExportLimits $limits;

    public function __construct(
        ?ReportExportLimits $limits = null,
        private readonly ?string $definitionHash = null,
        private readonly ?string $rendererVersion = null,
    ) {
        $this->limits = $limits ?? ReportExportLimits::csv();
    }

    public function forDefinition(PublishedReportDefinition $definition): self
    {
        return new self(
            $this->limits,
            $definition->definitionHash->value,
            $definition->definition->rendererVersion,
        );
    }

    public function render(
        ReportRunExportSource $source,
        CreateReportExportData $data,
        iterable $chunks,
        ReportArtifactStream $stream,
    ): int {
        $this->assertRequest($source, $data);
        $delimiter = str_starts_with($data->locale, 'ru') ? ';' : ',';
        $writtenBytes = 0;
        $rowCount = 0;
        $startedAt = hrtime(true);

        $this->write($stream, "\xEF\xBB\xBF", $writtenBytes);
        $headers = $this->headers($source, $data);
        $this->write($stream, $this->line($headers, $delimiter), $writtenBytes);

        foreach ($chunks as $chunk) {
            $this->assertChunk($source, $chunk, $stream, $startedAt);
            $rowCount += count($chunk->rows);
            if ($rowCount > $this->limits->maxRows) {
                throw $this->limit();
            }

            foreach ($chunk->rows as $row) {
                $cells = [];
                foreach ($data->columns as $columnId) {
                    if (! array_key_exists($columnId, $row->values)) {
                        throw $this->limit();
                    }

                    $cells[] = $this->cell($row->values[$columnId], $data, true);
                }
                $this->write($stream, $this->line($cells, $delimiter), $writtenBytes);
            }
            unset($row);
            unset($chunk);
        }

        if ($rowCount !== $source->result->metadata->rowCount) {
            throw $this->limit();
        }

        if ($source->result->totals !== []) {
            $totals = [];
            $labelPlaced = false;
            foreach ($data->columns as $columnId) {
                if (array_key_exists($columnId, $source->result->totals)) {
                    $value = $source->result->totals[$columnId];
                } elseif (! $labelPlaced) {
                    $value = $this->totalLabel($data);
                    $labelPlaced = true;
                } else {
                    $value = null;
                }
                $totals[] = $this->cell($value, $data, true);
            }
            $this->write($stream, $this->line($totals, $delimiter), $writtenBytes);
        }

        return $rowCount;
    }

    private function assertRequest(ReportRunExportSource $source, CreateReportExportData $data): void
    {
        $schemaIds = array_fill_keys(array_column($source->result->rowSchema, 'id'), true);
        if ($data->format !== 'csv'
            || count($data->columns) > $this->limits->maxColumns
            || ($this->definitionHash !== null
                && (! hash_equals($this->definitionHash, $source->run->definitionHash->value)
                    || ! hash_equals((string) $this->rendererVersion, $source->rendererVersion)))) {
            throw $this->limit();
        }

        foreach ($data->columns as $columnId) {
            if (! isset($schemaIds[$columnId])) {
                throw $this->limit();
            }
        }
    }

    private function assertChunk(
        ReportRunExportSource $source,
        mixed $chunk,
        ReportArtifactStream $stream,
        int $startedAt,
    ): void {
        if ($stream->cancellationRequested()
            || ! $chunk instanceof ReportRowChunk
            || count($chunk->rows) > $this->limits->maxChunkRows
            || ! hash_equals($source->snapshot->id, $chunk->snapshotId)
            || ! hash_equals($source->run->queryHash->value, $chunk->queryHash->value)
            || ! hash_equals($source->snapshot->sourceHash->value, $chunk->sourceHash->value)
            || (hrtime(true) - $startedAt) > $this->limits->maxElapsedSeconds * 1_000_000_000) {
            throw $this->limit();
        }
    }

    private function headers(ReportRunExportSource $source, CreateReportExportData $data): array
    {
        $schema = [];
        foreach ($source->result->rowSchema as $column) {
            $schema[$column['id']] = $column;
        }

        return array_map(
            static function (string $columnId) use ($schema, $data): string {
                $column = $schema[$columnId];
                $labels = $column['labels'] ?? null;
                $label = is_array($labels) && isset($labels[$data->locale])
                    ? $labels[$data->locale]
                    : ($column['label'] ?? $columnId);

                return is_string($label) && trim($label) !== '' ? $label : $columnId;
            },
            $data->columns,
        );
    }

    private function cell(mixed $value, CreateReportExportData $data, bool $neutralize): string
    {
        $cell = ReportPdfDocumentBuilder::normalizeCell($value, $data->timezone);
        $numeric = preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/D', $cell) === 1;

        if ($numeric && str_starts_with($data->locale, 'ru')) {
            $cell = str_replace('.', ',', $cell);
        }

        if ($neutralize && ! $numeric && preg_match('/^[=+\-@\t\r]/u', $cell) === 1) {
            return "'".$cell;
        }

        return $cell;
    }

    private function line(array $cells, string $delimiter): string
    {
        $handle = fopen('php://temp/maxmemory:1048576', 'w+b');
        if ($handle === false) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_DEPENDENCY_FAILED);
        }

        try {
            if (fputcsv($handle, $cells, $delimiter, '"', '', "\r\n") === false || rewind($handle) === false) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_DEPENDENCY_FAILED);
            }
            $line = stream_get_contents($handle);
            if (! is_string($line)) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_DEPENDENCY_FAILED);
            }

            return $line;
        } finally {
            fclose($handle);
        }
    }

    private function write(ReportArtifactStream $stream, string $bytes, int &$writtenBytes): void
    {
        $projected = $writtenBytes + strlen($bytes);
        if ($projected > $this->limits->maxBytes) {
            throw $this->limit();
        }
        try {
            $stream->write($bytes);
        } catch (ReportContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_DEPENDENCY_FAILED,
                previous: $exception,
            );
        }
        $writtenBytes = $projected;
    }

    private function totalLabel(CreateReportExportData $data): string
    {
        return trans_message('reports.exports.total_label', [], explode('-', $data->locale)[0]);
    }

    public static function format(): string
    {
        return 'csv';
    }

    private function limit(): ReportContractException
    {
        return ReportContractException::fromCode(ReportErrorCode::REPORT_EXPORT_LIMIT_EXCEEDED);
    }
}
