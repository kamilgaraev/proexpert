<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Import;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTOs\FsnbNormDTO;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTOs\FsnbNormResourceDTO;
use RuntimeException;
use SimpleXMLElement;
use Throwable;
use XMLReader;

final class FsnbXmlParser
{
    private const NORM_TAGS = [
        'work',
        'работа',
    ];

    private const RESOURCE_TAGS = [
        'resource',
        'res',
        'material',
        'machine',
        'worker',
        'equipment',
        'ресурс',
        'материал',
        'машина',
        'оборудование',
        'рабочий',
    ];

    public function parse(string $filePath): iterable
    {
        $collectionType = $this->detectCollectionType($filePath);
        $reader = $this->openReader($filePath);
        $sections = [];
        $nameGroups = [];

        try {
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $this->isSectionElement($reader)) {
                    $sections[] = [
                        'code' => $this->readerAttribute($reader, ['code', 'код']),
                        'name' => $this->readerAttribute($reader, ['name', 'title', 'наименование', 'название']),
                        'type' => $this->readerAttribute($reader, ['type', 'тип']),
                    ];

                    continue;
                }

                if ($reader->nodeType === XMLReader::END_ELEMENT && $this->normalizeName($reader->localName ?: $reader->name) === 'section') {
                    array_pop($sections);

                    continue;
                }

                if ($reader->nodeType === XMLReader::ELEMENT && $this->normalizeName($reader->localName ?: $reader->name) === 'namegroup') {
                    $nameGroups[] = [
                        'begin_name' => $this->readerAttribute($reader, ['beginname', 'begin_name', 'name', 'начало']),
                    ];

                    continue;
                }

                if ($reader->nodeType === XMLReader::END_ELEMENT && $this->normalizeName($reader->localName ?: $reader->name) === 'namegroup') {
                    array_pop($nameGroups);

                    continue;
                }

                if ($reader->nodeType !== XMLReader::ELEMENT || !$this->shouldParseNormElement($reader)) {
                    continue;
                }

                $nodeName = $reader->name;
                $dto = $this->parseNormElement($reader, $collectionType, $sections, $nameGroups);

                if ($dto !== null) {
                    yield $dto;
                }

                $reader->next($nodeName);
            }
        } finally {
            $reader->close();
        }
    }

    public function detectCollectionType(string $filePath): string
    {
        $name = mb_strtolower(pathinfo($filePath, PATHINFO_FILENAME));

        return match (true) {
            str_contains($name, 'гэснмр') => 'gesnmr',
            str_contains($name, 'гэснм') => 'gesnm',
            str_contains($name, 'гэснп') => 'gesnp',
            str_contains($name, 'гэснр') => 'gesnr',
            str_contains($name, 'гэсн') => 'gesn',
            default => 'unknown',
        };
    }

    /**
     * @param array<int, array{code: ?string, name: ?string, type: ?string}> $sections
     * @param array<int, array{begin_name: ?string}> $nameGroups
     */
    private function parseNormElement(XMLReader $reader, string $collectionType, array $sections, array $nameGroups): ?FsnbNormDTO
    {
        $xml = $reader->readOuterXML();

        if ($xml === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $node = new SimpleXMLElement($xml);
        } catch (Throwable) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            return null;
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $code = $this->firstValue($node, ['code', 'cipher', 'id', 'number', 'код', 'шифр', 'обоснование', 'номер']);
        $name = $this->buildWorkName(
            $sections,
            $nameGroups,
            $this->firstValue($node, ['name', 'title', 'description', 'наименование', 'название', 'описание']),
            $this->firstValue($node, ['endname', 'end_name', 'окончание'])
        );

        if ($code === null || $name === null) {
            return null;
        }

        $tableSection = $this->lastTableSection($sections);

        return new FsnbNormDTO(
            collectionType: $collectionType,
            code: $code,
            name: $name,
            unit: $this->firstValue($node, ['measureunit', 'measure_unit', 'unit', 'measure', 'measurement', 'единица', 'едизм', 'измеритель']),
            section: $tableSection['name'] ?? $this->lastSectionName($sections),
            resources: $this->extractResources($node),
            rawData: [
                'source_tag' => $node->getName(),
                'attributes' => $this->attributesToArray($node),
                'section_code' => $tableSection['code'] ?? null,
                'sections' => $sections,
                'name_group' => end($nameGroups) ?: null,
                'content' => $this->extractContent($node),
                'nr_sp' => $this->extractNrSp($node),
            ],
        );
    }

    private function extractResources(SimpleXMLElement $node): array
    {
        $resources = [];

        foreach ($this->descendants($node) as $child) {
            if (!$this->isResourceNode($child)) {
                continue;
            }

            $code = $this->firstValue($child, ['code', 'cipher', 'id', 'number', 'код', 'шифр', 'номер']);
            $name = $this->firstValue($child, ['endname', 'end_name', 'name', 'title', 'description', 'наименование', 'название', 'описание']);

            if ($name === null) {
                $name = $code;
            }

            if ($name === null) {
                continue;
            }

            $resources[] = new FsnbNormResourceDTO(
                code: $code,
                name: $name,
                unit: $this->firstValue($child, ['measureunit', 'measure_unit', 'unit', 'measure', 'measurement', 'единица', 'едизм', 'измеритель']),
                quantity: $this->toFloat($this->firstValue($child, ['quantity', 'qty', 'volume', 'count', 'количество', 'объем', 'объём'])),
                resourceType: $this->firstValue($child, ['type', 'kind', 'resource_type', 'тип', 'вид']),
                rawData: [
                    'source_tag' => $child->getName(),
                    'attributes' => $this->attributesToArray($child),
                ],
            );
        }

        return $resources;
    }

    private function shouldParseNormElement(XMLReader $reader): bool
    {
        $tag = $this->normalizeName($reader->localName ?: $reader->name);

        if (in_array($tag, self::RESOURCE_TAGS, true)) {
            return false;
        }

        if (in_array($tag, self::NORM_TAGS, true)) {
            return true;
        }

        return false;
    }

    private function isSectionElement(XMLReader $reader): bool
    {
        return $this->normalizeName($reader->localName ?: $reader->name) === 'section';
    }

    private function isResourceNode(SimpleXMLElement $node): bool
    {
        $tag = $this->normalizeName($node->getName());

        if (in_array($tag, self::RESOURCE_TAGS, true)) {
            return true;
        }

        return $this->firstValue($node, ['name', 'title', 'description', 'наименование', 'название']) !== null
            && (
                $this->firstValue($node, ['quantity', 'qty', 'volume', 'count', 'количество', 'объем', 'объём']) !== null
                || $this->firstValue($node, ['resource_type', 'type', 'kind', 'тип', 'вид']) !== null
            );
    }

    /**
     * @param array<int, array{code: ?string, name: ?string, type: ?string}> $sections
     * @param array<int, array{begin_name: ?string}> $nameGroups
     */
    private function buildWorkName(array $sections, array $nameGroups, ?string $name, ?string $endName): ?string
    {
        $parts = [];
        $nameGroup = end($nameGroups);

        if (is_array($nameGroup) && ($nameGroup['begin_name'] ?? null) !== null) {
            $parts[] = rtrim((string) $nameGroup['begin_name'], " \t\n\r\0\x0B:");
        } elseif ($name !== null) {
            $parts[] = $name;
        } else {
            $tableSection = $this->lastTableSection($sections);

            if (($tableSection['name'] ?? null) !== null) {
                $parts[] = (string) $tableSection['name'];
            }
        }

        if ($endName !== null) {
            $parts[] = $endName;
        }

        $result = trim(implode(': ', array_filter($parts, static fn (string $part): bool => trim($part) !== '')));

        return $result === '' ? null : $result;
    }

    /**
     * @param array<int, array{code: ?string, name: ?string, type: ?string}> $sections
     * @return array{code: ?string, name: ?string, type: ?string}|null
     */
    private function lastTableSection(array $sections): ?array
    {
        for ($index = count($sections) - 1; $index >= 0; $index--) {
            $type = mb_strtolower((string) ($sections[$index]['type'] ?? ''));

            if ($type === 'таблица') {
                return $sections[$index];
            }
        }

        return end($sections) ?: null;
    }

    /**
     * @param array<int, array{code: ?string, name: ?string, type: ?string}> $sections
     */
    private function lastSectionName(array $sections): ?string
    {
        $section = end($sections);

        return is_array($section) ? ($section['name'] ?? null) : null;
    }

    /**
     * @return array<int, string>
     */
    private function extractContent(SimpleXMLElement $node): array
    {
        $items = [];

        foreach ($node->xpath('.//*[local-name()="Content"]/*[local-name()="Item"]') ?: [] as $item) {
            $text = $this->firstValue($item, ['text', 'текст']);

            if ($text !== null) {
                $items[] = $text;
            }
        }

        return $items;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function extractNrSp(SimpleXMLElement $node): array
    {
        $items = [];

        foreach ($node->xpath('.//*[local-name()="NrSp"]/*[local-name()="ReasonItem"]') ?: [] as $item) {
            $attributes = $this->attributesToArray($item);

            if ($attributes !== []) {
                $items[] = $attributes;
            }
        }

        return $items;
    }

    private function firstValue(SimpleXMLElement $node, array $aliases): ?string
    {
        $normalizedAliases = array_map(fn (string $alias): string => $this->normalizeName($alias), $aliases);

        foreach ($node->attributes() as $name => $value) {
            if (in_array($this->normalizeName((string) $name), $normalizedAliases, true)) {
                return $this->cleanValue((string) $value);
            }
        }

        foreach ($this->descendants($node, true) as $child) {
            if (in_array($this->normalizeName($child->getName()), $normalizedAliases, true)) {
                return $this->cleanValue((string) $child);
            }
        }

        return null;
    }

    private function descendants(SimpleXMLElement $node, bool $includeDirectOnly = false): iterable
    {
        foreach ($node->children() as $child) {
            yield $child;

            if (!$includeDirectOnly) {
                yield from $this->descendants($child);
            }
        }

        foreach ($node->getDocNamespaces(true) as $namespace) {
            foreach ($node->children($namespace) as $child) {
                yield $child;

                if (!$includeDirectOnly) {
                    yield from $this->descendants($child);
                }
            }
        }
    }

    private function readerHasAliasAttribute(XMLReader $reader, array $aliases): bool
    {
        if (!$reader->hasAttributes) {
            return false;
        }

        $normalizedAliases = array_map(fn (string $alias): string => $this->normalizeName($alias), $aliases);

        $reader->moveToFirstAttribute();

        do {
            if (in_array($this->normalizeName($reader->localName ?: $reader->name), $normalizedAliases, true)) {
                $reader->moveToElement();

                return true;
            }
        } while ($reader->moveToNextAttribute());

        $reader->moveToElement();

        return false;
    }

    private function readerAttribute(XMLReader $reader, array $aliases): ?string
    {
        if (!$reader->hasAttributes) {
            return null;
        }

        $normalizedAliases = array_map(fn (string $alias): string => $this->normalizeName($alias), $aliases);
        $reader->moveToFirstAttribute();

        do {
            if (in_array($this->normalizeName($reader->localName ?: $reader->name), $normalizedAliases, true)) {
                $value = $this->cleanValue($reader->value);
                $reader->moveToElement();

                return $value;
            }
        } while ($reader->moveToNextAttribute());

        $reader->moveToElement();

        return null;
    }

    private function attributesToArray(SimpleXMLElement $node): array
    {
        $attributes = [];

        foreach ($node->attributes() as $name => $value) {
            $attributes[(string) $name] = (string) $value;
        }

        return $attributes;
    }

    private function toFloat(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $normalized = str_replace([' ', ','], ['', '.'], $value);

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function cleanValue(string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return $value === '' ? null : $value;
    }

    private function normalizeName(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return preg_replace('/[^a-zа-яё0-9]+/u', '', $value) ?? $value;
    }

    private function openReader(string $filePath): XMLReader
    {
        $reader = new XMLReader();

        if (!$reader->open($filePath, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw new RuntimeException('Cannot open XML file: ' . $filePath);
        }

        return $reader;
    }
}
