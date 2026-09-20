<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use XMLReader;
use ZipArchive;

final class WorkVolumeTableReader
{
    private const MAX_FILE_BYTES = 10 * 1024 * 1024;
    private const MAX_ZIP_BYTES = 32 * 1024 * 1024;
    private const MAX_ZIP_ENTRIES = 1000;
    private const MAX_ROWS = 10000;
    private const MAX_COLUMNS = 128;
    private const MAX_CELL_CHARS = 10000;

    public function read(string $path, string $extension, ?string $sheetName = null): array
    {
        if (!is_file($path) || !is_readable($path) || (filesize($path) ?: 0) > self::MAX_FILE_BYTES) {
            throw new InvalidArgumentException('wvs_table_file_limit');
        }
        $extension = strtolower(ltrim($extension, '.'));
        if ($extension === 'csv') {
            return $this->readCsv($path);
        }
        if ($extension === 'xlsx') {
            return $this->readXlsx($path, $sheetName);
        }
        throw new InvalidArgumentException('wvs_table_extension_invalid');
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new InvalidArgumentException('wvs_table_file_unreadable');
        }
        $first = fgets($handle);
        if ($first === false) {
            fclose($handle);
            return ['sheet_name' => null, 'sheet_names' => [], 'rows' => []];
        }
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first) ?? $first;
        $delimiter = $this->detectDelimiter($first);
        rewind($handle);
        $rows = [];
        $rowNumber = 0;
        $decodedBytes = 0;
        try {
        while (($values = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if ($rowNumber === 0 && isset($values[0])) {
                $values[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $values[0]) ?? $values[0];
            }
            $this->assertRow($values, ++$rowNumber);
                $decodedValues = array_map(fn ($value): string => $this->cellString($value), $values);
                foreach ($decodedValues as $decodedValue) {
                    $decodedBytes += strlen($decodedValue);
                    if ($decodedBytes > self::MAX_ZIP_BYTES) {
                        throw new InvalidArgumentException('wvs_table_decoded_size_limit');
                    }
                }
                $rows[] = ['row_number' => $rowNumber, 'values' => $decodedValues, 'formula_columns' => []];
        }
        } finally {
            fclose($handle);
        }
        return ['sheet_name' => null, 'sheet_names' => [], 'rows' => $rows];
    }

    private function detectDelimiter(string $sample): string
    {
        $scores = [];
        foreach ([',', ';', "\t"] as $delimiter) {
            $count = 0;
            $quoted = false;
            $length = strlen($sample);
            for ($i = 0; $i < $length; $i++) {
                if ($sample[$i] === '"' && $quoted && $i + 1 < $length && $sample[$i + 1] === '"') {
                    $i++;
                } elseif ($sample[$i] === '"') {
                    $quoted = !$quoted;
                } elseif (!$quoted && $sample[$i] === $delimiter) {
                    $count++;
                }
            }
            $scores[$delimiter] = $count;
        }
        arsort($scores);
        return (string) array_key_first($scores);
    }

    private function readXlsx(string $path, ?string $sheetName): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true || $zip->numFiles > self::MAX_ZIP_ENTRIES) {
            throw new InvalidArgumentException('wvs_table_zip_invalid');
        }
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) ($stat['name'] ?? '');
            if ($name === '' || str_starts_with($name, '/') || str_contains($name, '../') || str_contains($name, '..\\')) {
                $zip->close();
                throw new InvalidArgumentException('wvs_table_zip_entry_invalid');
            }
            $total += (int) ($stat['size'] ?? 0);
            if ($total > self::MAX_ZIP_BYTES) {
                $zip->close();
                throw new InvalidArgumentException('wvs_table_zip_size_limit');
            }
        }
        try {
            foreach ($this->zipXmlNames($zip) as $name) {
                $xml = $zip->getFromName($name) ?: '';
                $this->assertSafeXml($xml);
                if (str_ends_with(strtolower($name), '.rels') && preg_match('/TargetMode\s*=\s*["\']External["\']|Target\s*=\s*["\'][a-z][a-z0-9+.-]*:/i', $xml) === 1) {
                    throw new InvalidArgumentException('wvs_table_external_relationship');
                }
            }
            $this->preflightElementCount($zip, 'xl/workbook.xml', 'sheet');
            $this->preflightElementCount($zip, 'xl/_rels/workbook.xml.rels', 'Relationship');
            $workbook = $this->xml($zip, 'xl/workbook.xml');
            $rels = $this->xml($zip, 'xl/_rels/workbook.xml.rels');
            $shared = $this->sharedStrings($zip);
            $sheets = [];
            foreach ($workbook->getElementsByTagNameNS('*', 'sheet') as $sheet) {
                if (!$sheet instanceof DOMElement) {
                    continue;
                }
                $name = $sheet->getAttribute('name');
                $rid = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
                $target = $this->relationshipTarget($rels, $rid);
                $sheets[] = ['name' => $name, 'path' => $this->normalizeZipPath(str_starts_with($target, '/') ? $target : 'xl/'.$target)];
            }
            if ($sheets === []) throw new InvalidArgumentException('wvs_table_sheet_invalid');
            $selected = $sheetName === null ? $sheets[0] : null;
            foreach ($sheets as $sheet) {
                if ($sheetName !== null && $sheet['name'] === $sheetName) {
                    $selected = $sheet;
                }
            }
            if ($selected === null) throw new InvalidArgumentException('wvs_table_sheet_not_found');
            $this->preflightWorksheet($zip, $selected['path']);
            return ['sheet_name' => $selected['name'], 'sheet_names' => array_column($sheets, 'name'), 'rows' => $this->worksheetRowsFromStream($zip, $selected['path'], $shared)];
        } finally {
            $zip->close();
        }
    }

    private function xml(ZipArchive $zip, string $name): DOMDocument
    {
        $contents = $zip->getFromName($name);
        if (!is_string($contents)) throw new InvalidArgumentException('wvs_table_xml_missing');
        $this->assertSafeXml($contents);
        $document = new DOMDocument();
        if (@$document->loadXML($contents, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT) !== true) {
            throw new InvalidArgumentException('wvs_table_xml_invalid');
        }
        if ($document->doctype !== null) {
            throw new InvalidArgumentException('wvs_table_xml_unsafe');
        }
        return $document;
    }

    private function assertSafeXml(string $xml): void
    {
        if (str_contains($xml, "\0") || str_contains($xml, "\xFF\xFE") || str_contains($xml, "\xFE\xFF")) {
            throw new InvalidArgumentException('wvs_table_xml_encoding');
        }
        if (preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml) === 1) {
            throw new InvalidArgumentException('wvs_table_xml_unsafe');
        }
    }

    private function zipXmlNames(ZipArchive $zip): array
    {
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) ($zip->getNameIndex($i) ?: '');
            if (str_ends_with(strtolower($name), '.xml') || str_ends_with(strtolower($name), '.rels')) $names[] = $name;
        }
        return $names;
    }

    private function relationshipTarget(DOMDocument $rels, string $id): string
    {
        foreach ($rels->getElementsByTagNameNS('*', 'Relationship') as $relationship) {
            if (!$relationship instanceof DOMElement) {
                continue;
            }
            $target = $relationship->getAttribute('Target');
            if (strtolower($relationship->getAttribute('TargetMode')) === 'external' || preg_match('/^(?:[a-z]+:|\\\\)/i', $target) === 1) throw new InvalidArgumentException('wvs_table_external_relationship');
            if ($relationship->getAttribute('Id') === $id) return $target;
        }
        throw new InvalidArgumentException('wvs_table_relationship_missing');
    }

    private function normalizeZipPath(string $path): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') { array_pop($parts); continue; }
            $parts[] = $part;
        }
        return implode('/', $parts);
    }

    private function sharedStrings(ZipArchive $zip): array
    {
        $contents = $zip->getFromName('xl/sharedStrings.xml');
        if (!is_string($contents)) return [];
        $result = [];
        $this->assertSafeXml($contents);
        $reader = new XMLReader();
        if (@$reader->XML($contents, null, LIBXML_NONET | LIBXML_COMPACT) !== true) {
            throw new InvalidArgumentException('wvs_table_xml_invalid');
        }
        $text = '';
        $inShared = false;
        try {
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                    $text = '';
                    $inShared = true;
                } elseif ($reader->nodeType === XMLReader::TEXT && $inShared) {
                    $text .= $reader->value;
                } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'si') {
                    $result[] = $this->cellString($text);
                    $inShared = false;
                }
            }
        } finally {
            $reader->close();
        }
        return $result;
    }

    private function preflightWorksheet(ZipArchive $zip, string $name): void
    {
        $contents = $zip->getFromName($name);
        if (!is_string($contents)) {
            throw new InvalidArgumentException('wvs_table_xml_missing');
        }
        $this->assertSafeXml($contents);
        $reader = new XMLReader();
        if (@$reader->XML($contents, null, LIBXML_NONET | LIBXML_COMPACT) !== true) {
            throw new InvalidArgumentException('wvs_table_xml_invalid');
        }
        $rowNumber = 0;
        $columnCount = 0;
        $cellDepth = null;
        $cellCharacters = 0;
        try {
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'row') {
                    $rowNumber++;
                    $columnCount = 0;
                    if ($rowNumber > self::MAX_ROWS) {
                        throw new InvalidArgumentException('wvs_table_row_limit');
                    }
                } elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'c') {
                    $columnCount++;
                    if ($columnCount > self::MAX_COLUMNS) {
                        throw new InvalidArgumentException('wvs_table_column_limit');
                    }
                    $cellDepth = $reader->depth;
                    $cellCharacters = 0;
                } elseif ($cellDepth !== null && ($reader->nodeType === XMLReader::TEXT || $reader->nodeType === XMLReader::CDATA)) {
                    $cellCharacters += mb_strlen($reader->value, 'UTF-8');
                    if ($cellCharacters > self::MAX_CELL_CHARS) {
                        throw new InvalidArgumentException('wvs_table_cell_limit');
                    }
                } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'c') {
                    $cellDepth = null;
                }
            }
        } finally {
            $reader->close();
        }
    }

    private function preflightElementCount(ZipArchive $zip, string $name, string $elementName): void
    {
        $contents = $zip->getFromName($name);
        if (!is_string($contents)) {
            throw new InvalidArgumentException('wvs_table_xml_missing');
        }
        $this->assertSafeXml($contents);
        $reader = new XMLReader();
        if (@$reader->XML($contents, null, LIBXML_NONET | LIBXML_COMPACT) !== true) {
            throw new InvalidArgumentException('wvs_table_xml_invalid');
        }
        $count = 0;
        try {
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === $elementName && ++$count > self::MAX_ZIP_ENTRIES) {
                    throw new InvalidArgumentException('wvs_table_zip_entry_limit');
                }
            }
        } finally {
            $reader->close();
        }
    }

    private function worksheetRowsFromStream(ZipArchive $zip, string $name, array $shared): array
    {
        $contents = $zip->getFromName($name);
        if (!is_string($contents)) {
            throw new InvalidArgumentException('wvs_table_xml_missing');
        }
        $this->assertSafeXml($contents);
        $reader = new XMLReader();
        if (@$reader->XML($contents, null, LIBXML_NONET | LIBXML_COMPACT) !== true) {
            throw new InvalidArgumentException('wvs_table_xml_invalid');
        }
        $rows = [];
        $rowNumber = 0;
        $rowValues = [];
        $rowFormulas = [];
        $decodedBytes = 0;
        $rowActive = false;
        $cell = null;
        $capture = null;
        try {
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'row') {
                    $rowActive = true;
                    $rowNumber = (int) ($reader->getAttribute('r') ?? 0);
                    if ($rowNumber < 1) {
                        $rowNumber = count($rows) + 1;
                    }
                    $rowValues = [];
                    $rowFormulas = [];
                } elseif ($rowActive && $reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'c') {
                    $reference = $reader->getAttribute('r') ?? '';
                    preg_match('/^([A-Z]+)\d+$/i', $reference, $match);
                    $column = $match === [] ? count($rowValues) : $this->columnIndex($match[1]);
                    if ($column >= self::MAX_COLUMNS) {
                        throw new InvalidArgumentException('wvs_table_column_limit');
                    }
                    $cell = ['column' => $column, 'type' => $reader->getAttribute('t') ?? '', 'formula' => '', 'value' => ''];
                } elseif ($cell !== null && $reader->nodeType === XMLReader::ELEMENT && in_array($reader->localName, ['f', 't', 'v'], true)) {
                    $capture = $reader->localName === 'f' ? 'formula' : 'value';
                } elseif ($cell !== null && ($reader->nodeType === XMLReader::TEXT || $reader->nodeType === XMLReader::CDATA) && $capture !== null) {
                    $cell[$capture] .= $reader->value;
                    if (mb_strlen($cell[$capture], 'UTF-8') > self::MAX_CELL_CHARS) {
                        throw new InvalidArgumentException('wvs_table_cell_limit');
                    }
                } elseif ($cell !== null && $reader->nodeType === XMLReader::END_ELEMENT && in_array($reader->localName, ['f', 't', 'v'], true)) {
                    $capture = null;
                } elseif ($cell !== null && $reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'c') {
                    $value = $cell['formula'] !== '' ? $this->cellString('='.$cell['formula']) : $cell['value'];
                    if ($cell['type'] === 'inlineStr') {
                        $value = $cell['value'];
                    } elseif ($cell['type'] === 's') {
                        if (preg_match('/^\d+$/D', $cell['value']) !== 1 || !array_key_exists((int) $cell['value'], $shared)) {
                            throw new InvalidArgumentException('wvs_table_shared_string_invalid');
                        }
                        $value = $shared[(int) $cell['value']];
                    }
                    $column = $cell['column'];
                    $value = $this->cellString($value);
                    $decodedBytes += strlen($value);
                    if ($decodedBytes > self::MAX_ZIP_BYTES) {
                        throw new InvalidArgumentException('wvs_table_decoded_size_limit');
                    }
                    $rowValues[$column] = $value;
                    if ($cell['formula'] !== '') {
                        $rowFormulas[] = $column;
                    }
                    $cell = null;
                    $capture = null;
                } elseif ($rowActive && $reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'row') {
                    if ($rowValues !== []) {
                        $maxColumn = max(array_keys($rowValues));
                        $denseValues = [];
                        for ($column = 0; $column <= $maxColumn; $column++) {
                            $denseValues[] = $rowValues[$column] ?? '';
                        }
                        $this->assertRow($denseValues, $rowNumber);
                        $rows[] = ['row_number' => $rowNumber, 'values' => $denseValues, 'formula_columns' => $rowFormulas];
                    }
                    $rowActive = false;
                    if (count($rows) > self::MAX_ROWS) {
                        throw new InvalidArgumentException('wvs_table_row_limit');
                    }
                }
            }
        } finally {
            $reader->close();
        }
        return $rows;
    }

    private function worksheetRows(DOMDocument $document, array $shared): array
    {
        $rows = [];
        foreach ($document->getElementsByTagNameNS('*', 'row') as $row) {
            if (!$row instanceof DOMElement) {
                continue;
            }
            $rowNumber = (int) $row->getAttribute('r');
            if ($rowNumber < 1) $rowNumber = count($rows) + 1;
            $values = [];
            $formulas = [];
            $maxColumn = -1;
            foreach ($row->childNodes as $child) {
                if (!$child instanceof DOMElement || $child->localName !== 'c') {
                    continue;
                }
                $address = $child->getAttribute('r');
                preg_match('/^([A-Z]+)\d+$/i', $address, $match);
                $column = $match === [] ? count($values) : $this->columnIndex($match[1]);
                if ($column >= self::MAX_COLUMNS) throw new InvalidArgumentException('wvs_table_column_limit');
                $value = $this->cellValue($child, $shared);
                $values[$column] = $value;
                $maxColumn = max($maxColumn, $column);
                if ($child->getElementsByTagNameNS('*', 'f')->length > 0) {
                    $formulas[] = $column;
                }
            }
            if ($maxColumn < 0) continue;
            $denseValues = [];
            for ($column = 0; $column <= $maxColumn; $column++) $denseValues[] = $values[$column] ?? '';
            $values = $denseValues;
            $this->assertRow($values, $rowNumber);
            $rows[] = ['row_number' => $rowNumber, 'values' => $values, 'formula_columns' => $formulas];
            if (count($rows) > self::MAX_ROWS) throw new InvalidArgumentException('wvs_table_row_limit');
        }
        return $rows;
    }

    private function cellValue(DOMElement $cell, array $shared): string
    {
        $formula = $cell->getElementsByTagNameNS('*', 'f')->item(0);
        if ($formula !== null) {
            return $this->cellString('='.$formula->textContent);
        }
        $type = $cell->getAttribute('t');
        if ($type === 'inlineStr') {
            $text = '';
            foreach ($cell->getElementsByTagNameNS('*', 't') as $node) {
                $text .= $node->textContent;
            }
            return $this->cellString($text);
        }
        $value = $cell->getElementsByTagNameNS('*', 'v')->item(0)?->textContent ?? '';
        if ($type === 's') {
            if (preg_match('/^\d+$/D', $value) !== 1 || !array_key_exists((int) $value, $shared)) {
                throw new InvalidArgumentException('wvs_table_shared_string_invalid');
            }
            $value = $shared[(int) $value];
        }
        return $this->cellString($value);
    }

    private function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split(strtoupper($letters)) as $letter) {
            $index = $index * 26 + ord($letter) - 64;
        }
        return $index - 1;
    }

    private function assertRow(array $values, int $rowNumber): void
    {
        if ($rowNumber > self::MAX_ROWS || count($values) > self::MAX_COLUMNS) throw new InvalidArgumentException($rowNumber > self::MAX_ROWS ? 'wvs_table_row_limit' : 'wvs_table_column_limit');
        foreach ($values as $value) {
            $this->cellString($value);
        }
    }

    private function cellString(mixed $value): string
    {
        $value = (string) $value;
        if (function_exists('mb_check_encoding') && !mb_check_encoding($value, 'UTF-8')) throw new InvalidArgumentException('wvs_table_invalid_utf8');
        if (mb_strlen($value, 'UTF-8') > self::MAX_CELL_CHARS) throw new InvalidArgumentException('wvs_table_cell_limit');
        return $value;
    }
}
