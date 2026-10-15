<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use RuntimeException;
use XMLReader;
use ZipArchive;

final class BoundedSpreadsheetMetadata
{
    public function inspect(string $path, string $format): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('spreadsheet_container_invalid');
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > 2048) {
                throw new RuntimeException('spreadsheet_container_limit_exceeded');
            }
            $total = 0;
            $names = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (!is_array($stat)) {
                    throw new RuntimeException('spreadsheet_container_invalid');
                }
                $name = (string) $stat['name'];
                $size = (int) $stat['size'];
                $compressed = (int) $stat['comp_size'];
                $total += $size;
                if ($size > 5_000_000 || $total > 20_000_000 || $size > max(1, $compressed) * 100
                    || isset($names[strtolower($name)])) {
                    throw new RuntimeException('spreadsheet_container_limit_exceeded');
                }
                $names[strtolower($name)] = true;
            }
            $entry = $format === 'xlsx' ? 'xl/workbook.xml' : 'content.xml';
            $stream = $zip->getStream($entry);
            if (!is_resource($stream)) {
                throw new RuntimeException('spreadsheet_container_invalid');
            }
            try {
                $body = stream_get_contents($stream, 5_000_001);
            } finally {
                fclose($stream);
            }
            if (!is_string($body) || strlen($body) > 5_000_000
                || stripos($body, '<!DOCTYPE') !== false || stripos($body, '<!ENTITY') !== false) {
                throw new RuntimeException('spreadsheet_container_invalid');
            }
            $reader = new XMLReader;
            if (!$reader->XML($body, null, LIBXML_NONET)) {
                throw new RuntimeException('spreadsheet_container_invalid');
            }
            $titles = [];
            try {
                while ($reader->read()) {
                    if ($reader->nodeType !== XMLReader::ELEMENT) {
                        continue;
                    }
                    $isSheet = $format === 'xlsx'
                        ? $reader->localName === 'sheet' && $reader->namespaceURI === 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'
                        : $reader->localName === 'table' && $reader->namespaceURI === 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
                    if (!$isSheet) {
                        continue;
                    }
                    $name = $format === 'xlsx' ? $reader->getAttribute('name')
                        : $reader->getAttributeNs('name', 'urn:oasis:names:tc:opendocument:xmlns:table:1.0');
                    if (!is_string($name) || strlen($name) > 1024 || count($titles) >= 32) {
                        throw new RuntimeException('spreadsheet_container_limit_exceeded');
                    }
                    $titles[] = $name;
                }
            } finally {
                $reader->close();
            }

            return $titles;
        } finally {
            $zip->close();
        }
    }
}
