<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

final class DocumentTextExtractor
{
    /** @return array{status: string, coverage: string, text: string, units: array<int, array{type: string, index: int, text: string, provenance: array<string,mixed>}>} */
    public function extract(string $content, string $mimeType, string $filename): array
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $xmlMime = strtolower(trim(explode(';', $mimeType, 2)[0]));
        if ($extension === 'xml' || in_array($xmlMime, ['application/xml', 'text/xml'], true) || preg_match('/^application\/[a-z0-9.+-]+\+xml$/D', $xmlMime) === 1) return $this->xml($content);
        if ($extension === 'json' || $mimeType === 'application/json') return $this->json($content);
        if ($extension === 'csv') return $this->csv($content);
        if (in_array($extension, ['txt', 'csv'], true) || str_starts_with($mimeType, 'text/')) {
            return $this->result('ready', mb_strlen($content) > 2_000_000 ? 'partial' : 'full', $this->safeText($content), [['type' => 'text', 'index' => 0, 'text' => $this->safeText($content), 'provenance' => ['kind' => 'text']]]);
        }
        if ($extension === 'pdf' || $mimeType === 'application/pdf') {
            try {
                $pages = (new PdfParser)->parseContent($content)->getPages(); $units = [];
                foreach ($pages as $index => $page) { $text = $this->safeText($page->getText()); if ($text !== '') $units[] = ['type' => 'page', 'index' => $index + 1, 'text' => $text, 'provenance' => ['page' => $index + 1, 'kind' => 'pdf_text_layer']]; }
                $count = count($pages);
                if ($count === 0) return $this->result('damaged', 'unavailable', '', []);
                $complete = count($units) === $count;
                if (! $complete && $count > AssistantDocumentOcrClient::MAX_PAGES) return $this->result('unsupported', 'ocr_page_limit', implode("\n", array_column($units, 'text')), $units) + ['page_count' => $count];
                return $this->result($complete ? 'ready' : 'ocr_quote_required', $units === [] ? 'text_layer_empty' : ($complete ? 'full' : 'partial'), implode("\n", array_column($units, 'text')), $units) + ['page_count' => $count];
            } catch (Throwable) { return $this->result('damaged', 'unavailable', '', []); }
        }
        if (in_array($extension, ['xlsx', 'xls'], true)) return $this->spreadsheet($content, $extension);
        if (in_array($extension, ['docx', 'doc'], true)) return $this->word($content, $extension);
        if (in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $image = @getimagesizefromstring($content);
            if ($image === false || $image['mime'] !== $mimeType) return $this->result('damaged', 'unavailable', '', []);
            return $this->result('ocr_quote_required', 'image_ocr_required', '', []) + ['page_count' => 1];
        }
        return $this->result('unsupported', 'unsupported', '', []);
    }

    private function spreadsheet(string $content, string $extension): array
    {
        $path = $this->temporary($content, $extension);
        try {
            $this->assertArchiveBounded($path, $extension);
            $reader = SpreadsheetIOFactory::createReader($extension === 'xlsx' ? 'Xlsx' : 'Xls');
            $reader->setReadDataOnly(true);
            $book = $reader->load($path); $units = []; $index = 0;
            foreach ($book->getWorksheetIterator() as $sheet) foreach ($sheet->getRowIterator() as $row) {
                $cells = [];
                $iterator = $row->getCellIterator();
                $iterator->setIterateOnlyExistingCells(true);
                foreach ($iterator as $cell) {
                    $value = $cell->getValue();
                    if ($value !== null && $value !== '') $cells[$cell->getColumn()] = (string) $value;
                }
                if ($cells === []) continue;
                $text = implode(' | ', array_map(static fn ($column, $value): string => $column.': '.$value, array_keys($cells), $cells));
                $units[] = ['type' => 'table_row', 'index' => ++$index, 'text' => $this->safeText($text), 'provenance' => ['sheet' => $sheet->getTitle(), 'row' => $row->getRowIndex(), 'cells' => array_keys($cells)]];
                if ($index >= 10000) return $this->result('ready', 'partial', implode("\n", array_column($units, 'text')), $units);
            }
            return $units === [] ? $this->result('ready', 'empty', '', []) : $this->result('ready', 'full', implode("\n", array_column($units, 'text')), $units);
        } catch (Throwable) { return $this->result('damaged', 'unavailable', '', []); } finally { @unlink($path); }
    }

    private function xml(string $content): array
    {
        if (strlen($content) > 8_000_000) return $this->result('unsupported', 'xml_input_limit', '', []);
        if (str_starts_with($content, "\xEF\xBB\xBF")) $content = substr($content, 3);
        $encoding = 'UTF-8';
        if (preg_match('/^<\?xml\s[^?]*\bencoding\s*=\s*[\'"]([^\'"]+)[\'"]/i', $content, $match) === 1) $encoding = strtoupper($match[1]);
        if (! in_array($encoding, ['UTF-8', 'WINDOWS-1251', 'CP1251'], true)) return $this->result('unsupported', 'xml_encoding_unsupported', '', []);
        if (! mb_check_encoding($content, $encoding)) return $this->result('damaged', 'unavailable', '', []);
        if ($encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
            $content = preg_replace('/^(<\?xml\s[^?]*\bencoding\s*=\s*[\'"])[^\'"]+([\'"])/i', '${1}UTF-8${2}', $content) ?? '';
        }
        if (preg_match('/<!\s*(DOCTYPE|ENTITY)\b/i', $content) === 1) return $this->result('unsupported', 'xml_entities_unsupported', '', []);
        if (! class_exists(\XMLReader::class)) return $this->result('unsupported', 'xml_parser_unavailable', '', []);
        $previousErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new \XMLReader;
        try {
            if (! $reader->XML($content, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT)) return $this->result('damaged', 'unavailable', '', []);
            $reader->setParserProperty(\XMLReader::LOADDTD, false);
            $reader->setParserProperty(\XMLReader::SUBST_ENTITIES, false);
            $reader->setParserProperty(\XMLReader::VALIDATE, false);
            $units = []; $paths = []; $siblings = []; $skipped = []; $bytes = 0; $nodes = 0; $partial = false; $rootSeen = false;
            while ($reader->read()) {
                if (++$nodes > 100_000 || $reader->depth > 128) $partial = true;
                if ($reader->nodeType === \XMLReader::ELEMENT) {
                    $rootSeen = true;
                    $depth = $reader->depth;
                    $siblings[$depth] ??= [];
                    $position = ($siblings[$depth][$reader->name] ?? 0) + 1;
                    $siblings[$depth][$reader->name] = $position;
                    $paths[$depth] = ($depth > 0 ? $paths[$depth - 1] : '').'/'.$reader->name.'['.$position.']';
                    $siblings[$depth + 1] = [];
                    $skipped[$depth] = ($depth > 0 && ($skipped[$depth - 1] ?? false)) || $reader->namespaceURI === 'http://www.w3.org/2001/XInclude';
                    $attributes = [];
                    if ($reader->moveToFirstAttribute()) {
                        do { $attributes[$reader->name] = $reader->value; } while ($reader->moveToNextAttribute());
                        $reader->moveToElement();
                    }
                    $binary = in_array(strtolower($reader->localName), ['image', 'imagedata', 'bindata', 'binary', 'embeddedimage'], true);
                    foreach ($attributes as $name => $value) {
                        if (in_array(strtolower($name), ['encoding', 'contentencoding'], true) && strtolower($value) === 'base64') $binary = true;
                        if (in_array(strtolower($name), ['mime', 'mimetype', 'contenttype'], true) && str_starts_with(strtolower($value), 'image/')) $binary = true;
                    }
                    $skipped[$depth] = $skipped[$depth] || $binary;
                    if ($skipped[$depth]) { $partial = true; continue; }
                    if ($nodes > 100_000 || $depth > 128) continue;
                    foreach ($attributes as $name => $value) {
                        if ($name === 'xmlns' || str_starts_with($name, 'xmlns:')) continue;
                        $this->xmlUnit($paths[$depth].'/@'.$name, $value, $units, $bytes, $partial);
                    }
                } elseif (in_array($reader->nodeType, [\XMLReader::TEXT, \XMLReader::CDATA, \XMLReader::SIGNIFICANT_WHITESPACE], true)) {
                    $parent = $reader->depth - 1;
                    if ($nodes <= 100_000 && $reader->depth <= 128 && ! ($skipped[$parent] ?? false)) $this->xmlUnit(($paths[$parent] ?? '').'/text()', $reader->value, $units, $bytes, $partial);
                }
            }
            if (! $rootSeen || libxml_get_errors() !== []) return $this->result('damaged', 'unavailable', '', []);
            return $this->result('ready', $partial ? 'partial' : ($units === [] ? 'empty' : 'full'), implode("\n", array_column($units, 'text')), $units);
        } catch (Throwable) {
            return $this->result('damaged', 'unavailable', '', []);
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    private function xmlUnit(string $path, string $value, array &$units, int &$bytes, bool &$partial): void
    {
        $value = trim($value);
        if ($value === '') return;
        $text = $path.': '.$value;
        $remaining = 2_000_000 - $bytes - ($units === [] ? 0 : 1);
        if (count($units) >= 10_000 || $remaining <= strlen($path) + 2) { $partial = true; return; }
        if (strlen($text) > $remaining) { $text = mb_strcut($text, 0, $remaining, 'UTF-8'); $partial = true; }
        $bytes += strlen($text) + ($units === [] ? 0 : 1);
        $units[] = ['type' => 'xml', 'index' => count($units) + 1, 'text' => $text, 'provenance' => ['kind' => 'xml', 'path' => $path]];
    }

    private function json(string $content): array
    {
        if (! mb_check_encoding($content, 'UTF-8')) return $this->result('damaged', 'unavailable', '', []);
        try { json_decode($content, true, 512, JSON_THROW_ON_ERROR); }
        catch (Throwable) { return $this->result('damaged', 'unavailable', '', []); }
        $text = $this->safeText(mb_strcut($content, 0, 2_000_000, 'UTF-8'));
        return $this->result('ready', strlen($content) > 2_000_000 ? 'partial' : 'full', $text,
            [['type' => 'json', 'index' => 0, 'text' => $text, 'provenance' => ['kind' => 'json', 'path' => '$']]]);
    }

    private function csv(string $content): array
    {
        if (! mb_check_encoding($content, 'UTF-8')) return $this->result('damaged', 'unavailable', '', []);
        if (str_starts_with($content, "\xEF\xBB\xBF")) $content = substr($content, 3);
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) throw new \RuntimeException('document_temp_unavailable');
        try {
            fwrite($stream, $content); rewind($stream); $units = []; $index = 0;
            $separator = ',';
            $columns = 0;
            foreach ([',', ';'] as $candidate) {
                rewind($stream);
                $firstRow = fgetcsv($stream, 0, $candidate, '"', '');
                if (is_array($firstRow) && count($firstRow) > $columns) {
                    $separator = $candidate;
                    $columns = count($firstRow);
                }
            }
            rewind($stream);
            while (($row = fgetcsv($stream, 0, $separator, '"', '')) !== false) {
                $index++;
                if ($row === [null]) continue;
                $text = $this->safeText(implode(' | ', $row));
                $units[] = ['type' => 'table_row', 'index' => $index, 'text' => $text, 'provenance' => ['row' => $index, 'kind' => 'csv']];
                if ($index >= 10000) return $this->result('ready', 'partial', implode("\n", array_column($units, 'text')), $units);
            }
            return $this->result('ready', $units === [] ? 'empty' : 'full', implode("\n", array_column($units, 'text')), $units);
        } finally { fclose($stream); }
    }

    private function word(string $content, string $extension): array
    {
        if ($extension === 'doc') return $this->result('unsupported', 'legacy_doc_unsupported', '', []);
        $path = $this->temporary($content, $extension);
        try {
            $this->assertArchiveBounded($path, $extension);
            $document = WordIOFactory::load($path); $units = []; $index = 0;
            foreach ($document->getSections() as $section) foreach ($this->wordTexts($section->getElements()) as $text) {
                if ($text !== '') $units[] = ['type' => 'paragraph', 'index' => ++$index, 'text' => $text, 'provenance' => ['paragraph' => $index]];
            }
            return $units === [] ? $this->result('ready', 'empty', '', []) : $this->result('ready', 'full', implode("\n", array_column($units, 'text')), $units);
        } catch (Throwable) { return $this->result('damaged', 'unavailable', '', []); } finally { @unlink($path); }
    }

    private function wordTexts(array $elements): iterable
    {
        foreach ($elements as $element) {
            if (method_exists($element, 'getElements')) yield from $this->wordTexts($element->getElements());
            elseif (method_exists($element, 'getRows')) foreach ($element->getRows() as $row) foreach ($row->getCells() as $cell) yield from $this->wordTexts($cell->getElements());
            elseif (method_exists($element, 'getText')) yield $this->safeText((string) $element->getText());
        }
    }
    private function assertArchiveBounded(string $path, string $extension): void
    {
        if (! in_array($extension, ['docx', 'xlsx'], true)) return;
        $archive = new \ZipArchive;
        if ($archive->open($path) !== true) throw new \RuntimeException('document_archive_invalid');
        try {
            if ($archive->numFiles > 10000) throw new \RuntimeException('document_archive_too_large');
            $bytes = 0;
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = $archive->statIndex($index);
                $bytes += is_array($entry) ? (int) ($entry['size'] ?? 0) : 0;
                if ($bytes > 128_000_000) throw new \RuntimeException('document_archive_too_large');
            }
        } finally { $archive->close(); }
    }
    private function temporary(string $content, string $extension): string { $path = tempnam(sys_get_temp_dir(), 'most-doc-'); if ($path === false) throw new \RuntimeException('document_temp_unavailable'); $target = $path.'.'.$extension; if (!rename($path, $target) || file_put_contents($target, $content, LOCK_EX) !== strlen($content)) { @unlink($path); @unlink($target); throw new \RuntimeException('document_temp_unavailable'); } return $target; }
    private function safeText(string $text): string { $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', ' ', $text) ?? ''; return trim(mb_substr($text, 0, 2_000_000)); }
    /** @param array<int, array{type: string, index: int, text: string, provenance: array<string,mixed>}> $units */
    private function result(string $status, string $coverage, string $text, array $units): array { return compact('status', 'coverage', 'text', 'units'); }
}
