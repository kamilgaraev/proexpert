<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

use ZipArchive;

final class DocxOoxmlTextExtractor
{
    private const WORD_NAMESPACE = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const MAX_ARCHIVE_ENTRIES = 10_000;

    private const MAX_ARCHIVE_BYTES = 128_000_000;

    private const MAX_XML_PART_BYTES = 32_000_000;

    private const MAX_XML_BYTES = 64_000_000;

    private const MAX_NODES = 100_000;

    private const MAX_DEPTH = 128;

    private const MAX_TEXT_BYTES = 2_000_000;

    private const MAX_UNITS = 10_000;

    private const MAX_IMAGE_BYTES = 20_000_000;

    /**
     * @return array{
     *     parsed: bool,
     *     partial: bool,
     *     unsafe: bool,
     *     has_supported_image: bool,
     *     units: array<int, array{type: string, index: int, text: string, provenance: array<string, mixed>}>
     * }
     */
    public function extract(ZipArchive $archive): array
    {
        $parts = [];
        $seenParts = [];
        $hasSupportedImage = false;
        $partial = false;
        $unsafe = false;

        if ($archive->numFiles > self::MAX_ARCHIVE_ENTRIES) {
            return $this->result(false, true, false, false, []);
        }

        $archiveBytes = 0;
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $name = $archive->getNameIndex($index, ZipArchive::FL_UNCHANGED);
            $stat = $archive->statIndex($index, ZipArchive::FL_UNCHANGED);

            if (! is_string($name) || ! is_array($stat)) {
                $partial = true;

                continue;
            }

            $size = (int) ($stat['size'] ?? 0);
            $archiveBytes += $size;
            if ($archiveBytes > self::MAX_ARCHIVE_BYTES) {
                return $this->result(false, true, false, false, []);
            }

            if ($this->isWordXmlPart($name)) {
                if (isset($seenParts[$name])) {
                    $partial = true;
                    if ($name === 'word/document.xml') {
                        $unsafe = true;
                    }

                    continue;
                }

                $seenParts[$name] = true;
                $parts[] = ['index' => $index, 'name' => $name, 'size' => $size];
            }

            if ($this->isSupportedImagePart($name) && $size > 0 && $size <= self::MAX_IMAGE_BYTES) {
                $image = $archive->getFromIndex($index, $size, ZipArchive::FL_UNCHANGED);
                if (is_string($image)) {
                    $details = @getimagesizefromstring($image);
                    $hasSupportedImage = $hasSupportedImage || (is_array($details) && in_array($details['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true));
                }
            }
        }

        if ($unsafe) {
            return $this->result(false, $partial, true, $hasSupportedImage, []);
        }

        usort($parts, fn (array $left, array $right): int => [$this->partOrder($left['name']), $left['name']] <=> [$this->partOrder($right['name']), $right['name']]);

        $hasDocument = false;
        foreach ($parts as $part) {
            if ($part['name'] === 'word/document.xml') {
                $hasDocument = true;
                break;
            }
        }

        if (! $hasDocument) {
            return $this->result(false, true, false, $hasSupportedImage, []);
        }

        if (! class_exists(\XMLReader::class)) {
            return $this->result(false, true, false, $hasSupportedImage, []);
        }

        $xmlBytes = 0;
        $nodeCount = 0;
        $textBytes = 0;
        $units = [];
        $mainPartParsed = false;
        $stop = false;

        foreach ($parts as $part) {
            if ($part['size'] > self::MAX_XML_PART_BYTES || $xmlBytes + $part['size'] > self::MAX_XML_BYTES) {
                $partial = true;
                if ($part['name'] === 'word/document.xml') {
                    $mainPartParsed = false;
                }

                continue;
            }

            $xmlBytes += $part['size'];
            $xml = $archive->getFromIndex($part['index'], $part['size'], ZipArchive::FL_UNCHANGED);
            if (! is_string($xml) || preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml) === 1) {
                if (is_string($xml) && preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml) === 1) {
                    $unsafe = true;
                } else {
                    $partial = true;
                }

                if ($part['name'] === 'word/document.xml') {
                    $mainPartParsed = false;
                }

                if ($unsafe) {
                    break;
                }

                continue;
            }

            $unitCount = count($units);
            $partTextBytes = $textBytes;
            $partParsed = $this->readPart($xml, $part['name'], $units, $textBytes, $nodeCount, $partial, $stop);
            if (! $partParsed) {
                $partial = true;
                array_splice($units, $unitCount);
                $textBytes = $partTextBytes;
            }

            if ($part['name'] === 'word/document.xml') {
                $mainPartParsed = $partParsed;
            }

            if ($stop) {
                break;
            }
        }

        return $this->result($mainPartParsed, $partial || $stop, $unsafe, $hasSupportedImage, $units);
    }

    /**
     * @param  array<int, array{type: string, index: int, text: string, provenance: array<string, mixed>}>  $units
     */
    private function readPart(string $xml, string $part, array &$units, int &$textBytes, int &$nodeCount, bool &$partial, bool &$stop): bool
    {
        $expectedRoot = $this->expectedRoot($part);
        $rootValid = false;
        $rootSeen = false;
        $paragraphDepth = null;
        $paragraphIndex = 0;
        $paragraph = '';
        $inText = false;
        $partParsed = false;
        $previousErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new \XMLReader;

        try {
            if (! $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
                $partial = true;

                return false;
            }

            $reader->setParserProperty(\XMLReader::LOADDTD, false);
            $reader->setParserProperty(\XMLReader::SUBST_ENTITIES, false);
            $reader->setParserProperty(\XMLReader::VALIDATE, false);

            while ($reader->read()) {
                $nodeCount++;
                if ($nodeCount > self::MAX_NODES || $reader->depth > self::MAX_DEPTH) {
                    $partial = true;
                    $stop = true;
                    break;
                }

                if ($reader->nodeType === \XMLReader::DOC_TYPE) {
                    $partial = true;
                    $stop = true;
                    break;
                }

                if ($reader->nodeType === \XMLReader::ELEMENT) {
                    if (! $rootSeen) {
                        $rootSeen = true;
                        $rootValid = $reader->localName === $expectedRoot && $reader->namespaceURI === self::WORD_NAMESPACE;
                    }

                    if ($reader->namespaceURI !== self::WORD_NAMESPACE) {
                        continue;
                    }

                    if ($reader->localName === 'p' && $paragraphDepth === null) {
                        $paragraphDepth = $reader->depth;
                        $paragraph = '';
                    } elseif ($paragraphDepth !== null && $reader->localName === 't') {
                        $inText = true;
                    } elseif ($paragraphDepth !== null && in_array($reader->localName, ['tab'], true)) {
                        if (! $this->appendParagraphText($paragraph, "\t", $textBytes, $partial)) {
                            $stop = true;
                            break;
                        }
                    } elseif ($paragraphDepth !== null && in_array($reader->localName, ['br', 'cr'], true)) {
                        if (! $this->appendParagraphText($paragraph, "\n", $textBytes, $partial)) {
                            $stop = true;
                            break;
                        }
                    }
                } elseif (in_array($reader->nodeType, [\XMLReader::TEXT, \XMLReader::CDATA, \XMLReader::SIGNIFICANT_WHITESPACE], true) && $inText) {
                    if (! $this->appendParagraphText($paragraph, $reader->value, $textBytes, $partial)) {
                        $stop = true;
                        break;
                    }
                } elseif ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->namespaceURI === self::WORD_NAMESPACE) {
                    if ($reader->localName === 't') {
                        $inText = false;
                    } elseif ($reader->localName === 'p' && $paragraphDepth === $reader->depth) {
                        $paragraphIndex++;
                        if (! $this->appendUnit($part, $paragraphIndex, $paragraph, $units, $textBytes, $partial)) {
                            $stop = true;
                            break;
                        }

                        $paragraphDepth = null;
                        $paragraph = '';
                    }
                }
            }

            if ($paragraphDepth !== null && $paragraph !== '') {
                $paragraphIndex++;
                $this->appendUnit($part, $paragraphIndex, $paragraph, $units, $textBytes, $partial);
            }

            if (libxml_get_errors() !== []) {
                $partial = true;
            }

            $partParsed = $rootSeen && $rootValid;

            return $partParsed;
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    private function appendParagraphText(string &$paragraph, string $text, int $textBytes, bool &$partial): bool
    {
        $remaining = self::MAX_TEXT_BYTES - $textBytes - strlen($paragraph);
        if ($remaining <= 0) {
            $partial = true;

            return false;
        }

        if (strlen($text) > $remaining) {
            $paragraph .= mb_strcut($text, 0, $remaining, 'UTF-8');
            $partial = true;

            return false;
        }

        $paragraph .= $text;

        return true;
    }

    /**
     * @param  array<int, array{type: string, index: int, text: string, provenance: array<string, mixed>}>  $units
     */
    private function appendUnit(string $part, int $paragraphIndex, string $paragraph, array &$units, int &$textBytes, bool &$partial): bool
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', ' ', trim($paragraph)) ?? '';
        if ($text === '') {
            return true;
        }

        if (count($units) >= self::MAX_UNITS) {
            $partial = true;

            return false;
        }

        $remaining = self::MAX_TEXT_BYTES - $textBytes - ($units === [] ? 0 : 1);
        if ($remaining <= 0) {
            $partial = true;

            return false;
        }

        if (strlen($text) > $remaining) {
            $text = mb_strcut($text, 0, $remaining, 'UTF-8');
            $partial = true;
        }

        if ($text === '') {
            return false;
        }

        $textBytes += strlen($text) + ($units === [] ? 0 : 1);
        $units[] = [
            'type' => 'paragraph',
            'index' => count($units) + 1,
            'text' => $text,
            'provenance' => ['part' => $part, 'paragraph' => $paragraphIndex],
        ];

        return true;
    }

    private function isWordXmlPart(string $name): bool
    {
        return $name === 'word/document.xml'
            || preg_match('~^word/(?:header|footer|footnotes|endnotes|comments)\d*\.xml$~D', $name) === 1;
    }

    private function isSupportedImagePart(string $name): bool
    {
        return preg_match('~^word/media/[^/]+\.(?:jpe?g|png|webp)$~iD', $name) === 1;
    }

    private function partOrder(string $name): int
    {
        if ($name === 'word/document.xml') {
            return 0;
        }

        if (str_starts_with($name, 'word/header')) {
            return 1;
        }

        if (str_starts_with($name, 'word/footer')) {
            return 2;
        }

        if (str_starts_with($name, 'word/footnotes')) {
            return 3;
        }

        if (str_starts_with($name, 'word/endnotes')) {
            return 4;
        }

        return 5;
    }

    private function expectedRoot(string $part): string
    {
        return match (true) {
            $part === 'word/document.xml' => 'document',
            str_starts_with($part, 'word/header') => 'hdr',
            str_starts_with($part, 'word/footer') => 'ftr',
            str_starts_with($part, 'word/footnotes') => 'footnotes',
            str_starts_with($part, 'word/endnotes') => 'endnotes',
            default => 'comments',
        };
    }

    /**
     * @param  array<int, array{type: string, index: int, text: string, provenance: array<string, mixed>}>  $units
     * @return array{
     *     parsed: bool,
     *     partial: bool,
     *     unsafe: bool,
     *     has_supported_image: bool,
     *     units: array<int, array{type: string, index: int, text: string, provenance: array<string, mixed>}>
     * }
     */
    private function result(bool $parsed, bool $partial, bool $unsafe, bool $hasSupportedImage, array $units): array
    {
        return [
            'parsed' => $parsed,
            'partial' => $partial,
            'unsafe' => $unsafe,
            'has_supported_image' => $hasSupportedImage,
            'units' => $units,
        ];
    }
}
