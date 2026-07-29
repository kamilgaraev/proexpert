<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Exports;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;

final readonly class ReportPdfDocument
{
    /**
     * @param non-empty-list<array{id: string, label: string}> $headers
     * @param list<list<string>> $rows
     * @param array<string, string> $totals
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public array $headers,
        public array $rows,
        public array $totals,
        public array $metadata,
    ) {
        if (!array_is_list($headers) || $headers === [] || !array_is_list($rows)) {
            throw new InvalidArgumentException('report_pdf_document_invalid');
        }

        $columnIds = [];
        foreach ($headers as $header) {
            if (!is_array($header)
                || array_keys($header) !== ['id', 'label']
                || !is_string($header['id'])
                || !is_string($header['label'])
                || trim($header['label']) === ''
                || isset($columnIds[$header['id']])) {
                throw new InvalidArgumentException('report_pdf_document_invalid');
            }

            $columnIds[$header['id']] = true;
        }

        foreach ($rows as $row) {
            if (!array_is_list($row) || count($row) !== count($headers)) {
                throw new InvalidArgumentException('report_pdf_document_invalid');
            }

            foreach ($row as $cell) {
                if (!is_string($cell)) {
                    throw new InvalidArgumentException('report_pdf_document_invalid');
                }
            }
        }

        if (!self::isStringMap($totals)) {
            throw new InvalidArgumentException('report_pdf_document_invalid');
        }

        CanonicalJson::encode($metadata);
    }

    public function detailRowCount(): int
    {
        return count($this->rows);
    }

    private static function isStringMap(array $value): bool
    {
        if (array_is_list($value) && $value !== []) {
            return false;
        }

        foreach ($value as $key => $item) {
            if (!is_string($key) || !is_string($item)) {
                return false;
            }
        }

        return true;
    }
}
