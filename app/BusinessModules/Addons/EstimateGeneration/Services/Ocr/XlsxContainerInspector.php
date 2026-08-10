<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions\OcrProviderException;
use DOMDocument;
use DOMXPath;
use ZipArchive;

final class XlsxContainerInspector
{
    public function inspect(string $path): XlsxContainerMetadata
    {
        $maxEntries = max(1, (int) config('estimate-generation.ocr.max_spreadsheet_zip_entries', 2048));
        $maxUncompressed = max(1, (int) config('estimate-generation.ocr.max_spreadsheet_uncompressed_bytes', 20_000_000));
        $maxEntryBytes = max(1, min($maxUncompressed, (int) config(
            'estimate-generation.ocr.max_spreadsheet_zip_entry_bytes',
            5_000_000,
        )));
        $maxRatio = max(1, (int) config('estimate-generation.ocr.max_spreadsheet_compression_ratio', 100));
        $maxMerges = max(1, (int) config('estimate-generation.ocr.max_spreadsheet_merges', 20_000));
        $maxSheets = max(1, (int) config('estimate-generation.ocr.max_spreadsheet_sheets', 32));
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            $this->invalid('spreadsheet_container_invalid');
        }

        try {
            $names = $this->assertSafeEntries($zip, $maxEntries, $maxUncompressed, $maxEntryBytes, $maxRatio);
            foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels'] as $required) {
                if (! isset($names[strtolower($required)])) {
                    $this->invalid('spreadsheet_container_invalid');
                }
            }

            return $this->metadata($zip, $names, $maxEntryBytes, $maxMerges, $maxSheets);
        } finally {
            $zip->close();
        }
    }

    /** @return array<string, string> */
    private function assertSafeEntries(
        ZipArchive $zip,
        int $maxEntries,
        int $maxUncompressed,
        int $maxEntryBytes,
        int $maxRatio,
    ): array {
        if ($zip->numFiles < 1 || $zip->numFiles > $maxEntries) {
            $this->invalid('spreadsheet_container_limit_exceeded');
        }
        $total = 0;
        $names = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
            if (! is_array($stat)) {
                $this->invalid('spreadsheet_container_invalid');
            }
            $name = (string) ($stat['name'] ?? '');
            $normalized = str_replace('\\', '/', $name);
            $size = max(0, (int) ($stat['size'] ?? 0));
            $compressed = max(0, (int) ($stat['comp_size'] ?? 0));
            $total += $size;
            $ratio = $compressed === 0 ? ($size === 0 ? 1.0 : INF) : $size / $compressed;
            $folded = strtolower($normalized);
            if ($name === '' || $normalized !== $name || str_contains($name, "\0")
                || in_array('..', explode('/', $normalized), true)
                || str_starts_with($normalized, '/') || str_ends_with($normalized, '/.')
                || preg_match('/^[A-Za-z]:\//', $normalized) === 1
                || isset($names[$folded]) || $size > $maxEntryBytes
                || $total > $maxUncompressed || $ratio > $maxRatio) {
                $this->invalid('spreadsheet_container_limit_exceeded');
            }
            $names[$folded] = $normalized;
        }

        return $names;
    }

    /** @param array<string, string> $names */
    private function metadata(
        ZipArchive $zip,
        array $names,
        int $maxEntryBytes,
        int $maxMerges,
        int $maxSheets,
    ): XlsxContainerMetadata {
        $workbook = $this->xml($zip, $names['xl/workbook.xml'], $maxEntryBytes);
        $relationships = $this->xml($zip, $names['xl/_rels/workbook.xml.rels'], $maxEntryBytes);
        $relationTargets = [];
        $relations = new DOMXPath($relationships);
        foreach ($relations->query('//*[local-name()="Relationship"]') ?: [] as $relationship) {
            $id = $relationship->attributes?->getNamedItem('Id')?->nodeValue;
            $target = $relationship->attributes?->getNamedItem('Target')?->nodeValue;
            if (is_string($id) && is_string($target)) {
                $relationTargets[$id] = $this->worksheetEntry($target);
            }
        }

        $mergesBySheet = [];
        $limitations = [];
        $sheets = new DOMXPath($workbook);
        $mergeCount = 0;
        foreach ($sheets->query('//*[local-name()="sheet"]') ?: [] as $sheetIndex => $sheet) {
            if ($sheetIndex >= $maxSheets) {
                break;
            }
            $name = $sheet->attributes?->getNamedItem('name')?->nodeValue;
            $relationId = $sheet->getAttributeNS(
                'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
                'id',
            );
            $entry = $relationTargets[$relationId] ?? null;
            if (! is_string($name) || $name === '' || ! is_string($entry) || ! isset($names[strtolower($entry)])) {
                $this->invalid('spreadsheet_container_invalid');
            }
            $sheetXml = $this->xml($zip, $names[strtolower($entry)], $maxEntryBytes);
            $xpath = new DOMXPath($sheetXml);
            $ranges = [];
            foreach ($xpath->query('//*[local-name()="mergeCell"]') ?: [] as $merge) {
                $reference = $merge->attributes?->getNamedItem('ref')?->nodeValue;
                if (! is_string($reference) || preg_match('/^[A-Z]{1,3}[1-9][0-9]*:[A-Z]{1,3}[1-9][0-9]*$/', $reference) !== 1) {
                    $this->invalid('spreadsheet_container_invalid');
                }
                if ($mergeCount >= $maxMerges) {
                    $limitations[$name] = ['xlsx_merges_truncated'];
                    break;
                }
                $ranges[] = $reference;
                $mergeCount++;
            }
            $mergesBySheet[$name] = $ranges;
        }

        return new XlsxContainerMetadata($mergesBySheet, $limitations);
    }

    private function xml(ZipArchive $zip, string $entry, int $maxBytes): DOMDocument
    {
        $body = $zip->getFromName($entry, 0, ZipArchive::FL_UNCHANGED);
        if (! is_string($body) || $body === '' || strlen($body) > $maxBytes
            || stripos($body, '<!DOCTYPE') !== false || stripos($body, '<!ENTITY') !== false) {
            $this->invalid('spreadsheet_container_invalid');
        }
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $document->loadXML($body, LIBXML_NONET | LIBXML_COMPACT)) {
                $this->invalid('spreadsheet_container_invalid');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }

    private function worksheetEntry(string $target): string
    {
        $target = str_replace('\\', '/', $target);
        if ($target === '' || str_starts_with($target, '/') || in_array('..', explode('/', $target), true)) {
            $this->invalid('spreadsheet_container_invalid');
        }

        return str_starts_with($target, 'xl/') ? $target : 'xl/'.ltrim($target, '/');
    }

    private function invalid(string $providerCode): never
    {
        throw new OcrProviderException(
            'estimate_generation.spreadsheet_parse_error',
            providerCode: $providerCode,
        );
    }
}
