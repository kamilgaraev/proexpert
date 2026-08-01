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
use ZipArchive;

final class XlsxReportExportRenderer implements ReportExportRenderer
{
    public const MIME_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private readonly ReportExportLimits $limits;

    public function __construct(
        ?ReportExportLimits $limits = null,
        private readonly ?string $definitionHash = null,
        private readonly ?string $rendererVersion = null,
    ) {
        $this->limits = $limits ?? ReportExportLimits::xlsx();
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
        $hasTotals = $source->result->totals !== [];
        if (1 + $source->result->metadata->rowCount + ($hasTotals ? 1 : 0) > $this->limits->maxWorksheetRows) {
            throw $this->limit();
        }

        $sheetPath = tempnam(sys_get_temp_dir(), 'most-report-sheet-');
        $zipPath = tempnam(sys_get_temp_dir(), 'most-report-xlsx-');
        if (! is_string($sheetPath) || ! is_string($zipPath)) {
            throw $this->dependency();
        }

        try {
            return $this->buildAndStream($source, $data, $chunks, $stream, $sheetPath, $zipPath);
        } finally {
            @unlink($sheetPath);
            @unlink($zipPath);
        }
    }

    private function buildAndStream(
        ReportRunExportSource $source,
        CreateReportExportData $data,
        iterable $chunks,
        ReportArtifactStream $stream,
        string $sheetPath,
        string $zipPath,
    ): int {
        $sheet = fopen($sheetPath, 'w+b');
        if ($sheet === false) {
            throw $this->dependency();
        }

        $xmlBytes = 0;
        $rowCount = 0;
        $worksheetRow = 1;
        $startedAt = hrtime(true);

        try {
            $this->writeSheet($sheet, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>', $xmlBytes);
            $this->writeSheet($sheet, $this->xmlRow($worksheetRow++, $this->headers($source, $data), true), $xmlBytes);

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
                        $cells[] = $row->values[$columnId];
                    }
                    $this->writeSheet($sheet, $this->xmlRow($worksheetRow++, $cells, false), $xmlBytes);
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
                        $totals[] = $source->result->totals[$columnId];
                    } elseif (! $labelPlaced) {
                        $totals[] = trans_message('reports.exports.total_label', [], explode('-', $data->locale)[0]);
                        $labelPlaced = true;
                    } else {
                        $totals[] = null;
                    }
                }
                $this->writeSheet($sheet, $this->xmlRow($worksheetRow, $totals, true), $xmlBytes);
            }

            $this->writeSheet($sheet, '</sheetData></worksheet>', $xmlBytes);
        } finally {
            fclose($sheet);
        }

        $this->createArchive($sheetPath, $zipPath);
        $size = filesize($zipPath);
        if (! is_int($size) || $size < 1 || $size > $this->limits->maxBytes) {
            throw $this->limit();
        }
        $this->copyArchive($zipPath, $stream);

        return $rowCount;
    }

    private function createArchive(string $sheetPath, string $zipPath): void
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw $this->dependency();
        }

        $entries = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                .'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                .'</Relationships>',
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<fonts count="2"><font/><font><b/></font></fonts><fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
                .'<borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs>'
                .'<cellXfs count="2"><xf xfId="0"/><xf xfId="0" fontId="1" applyFont="1"/></cellXfs></styleSheet>',
        ];

        try {
            foreach ($entries as $name => $content) {
                if (! $zip->addFromString($name, $content)) {
                    throw $this->dependency();
                }
                $zip->setMtimeName($name, 315532800);
            }
            if (! $zip->addFile($sheetPath, 'xl/worksheets/sheet1.xml')) {
                throw $this->dependency();
            }
            $zip->setMtimeName('xl/worksheets/sheet1.xml', 315532800);
        } finally {
            if (! $zip->close()) {
                throw $this->dependency();
            }
        }
    }

    private function copyArchive(string $zipPath, ReportArtifactStream $stream): void
    {
        $handle = fopen($zipPath, 'rb');
        if ($handle === false) {
            throw $this->dependency();
        }

        try {
            while (! feof($handle)) {
                $bytes = fread($handle, 65_536);
                if ($bytes === false) {
                    throw $this->dependency();
                }
                if ($bytes !== '') {
                    $stream->write($bytes);
                }
            }
        } catch (ReportContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->dependency($exception);
        } finally {
            fclose($handle);
        }
    }

    private function xmlRow(int $rowNumber, array $values, bool $bold): string
    {
        $cells = '';
        foreach ($values as $index => $value) {
            $reference = $this->columnName($index + 1).$rowNumber;
            $style = $bold ? ' s="1"' : '';
            if (is_int($value) || (is_float($value) && is_finite($value))) {
                $number = is_int($value)
                    ? (string) $value
                    : json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
                $cells .= '<c r="'.$reference.'"'.$style.' t="n"><v>'.$number.'</v></c>';

                continue;
            }
            if (is_string($value) && preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/D', $value) === 1) {
                $cells .= '<c r="'.$reference.'"'.$style.' t="n"><v>'.$value.'</v></c>';

                continue;
            }
            $text = ReportPdfDocumentBuilder::normalizeCell($value, new \DateTimeZone('UTC'));
            $preserve = trim($text) !== $text ? ' xml:space="preserve"' : '';
            $cells .= '<c r="'.$reference.'"'.$style.' t="inlineStr"><is><t'.$preserve.'>'
                .$this->escape($text).'</t></is></c>';
        }

        return '<row r="'.$rowNumber.'">'.$cells.'</row>';
    }

    private function writeSheet($handle, string $xml, int &$writtenBytes): void
    {
        $projected = $writtenBytes + strlen($xml);
        if ($projected > $this->limits->maxBytes || fwrite($handle, $xml) !== strlen($xml)) {
            throw $this->limit();
        }
        $writtenBytes = $projected;
    }

    private function assertRequest(ReportRunExportSource $source, CreateReportExportData $data): void
    {
        $schemaIds = array_fill_keys(array_column($source->result->rowSchema, 'id'), true);
        if ($data->format !== 'xlsx'
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

    private function columnName(int $column): string
    {
        $name = '';
        while ($column > 0) {
            $column--;
            $name = chr(65 + ($column % 26)).$name;
            $column = intdiv($column, 26);
        }

        return $name;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    public static function format(): string
    {
        return 'xlsx';
    }

    private function limit(): ReportContractException
    {
        return ReportContractException::fromCode(ReportErrorCode::REPORT_EXPORT_LIMIT_EXCEEDED);
    }

    private function dependency(?Throwable $previous = null): ReportContractException
    {
        return ReportContractException::fromCode(
            ReportErrorCode::REPORT_DEPENDENCY_FAILED,
            previous: $previous,
        );
    }
}
