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
        'norm',
        'norma',
        'rate',
        'item',
        'position',
        'work',
        'работа',
        'норма',
        'расценка',
        'позиция',
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

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || !$this->shouldParseNormElement($reader)) {
                    continue;
                }

                $nodeName = $reader->name;
                $dto = $this->parseNormElement($reader, $collectionType);

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

    private function parseNormElement(XMLReader $reader, string $collectionType): ?FsnbNormDTO
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
        $name = $this->firstValue($node, ['name', 'title', 'description', 'наименование', 'название', 'описание']);

        if ($code === null || $name === null) {
            return null;
        }

        return new FsnbNormDTO(
            collectionType: $collectionType,
            code: $code,
            name: $name,
            unit: $this->firstValue($node, ['unit', 'measure', 'measurement', 'единица', 'едизм', 'измеритель']),
            section: $this->firstValue($node, ['section', 'chapter', 'part', 'раздел', 'сборник', 'глава']),
            resources: $this->extractResources($node),
            rawData: [
                'source_tag' => $node->getName(),
                'attributes' => $this->attributesToArray($node),
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

            $name = $this->firstValue($child, ['name', 'title', 'description', 'наименование', 'название', 'описание']);

            if ($name === null) {
                continue;
            }

            $resources[] = new FsnbNormResourceDTO(
                code: $this->firstValue($child, ['code', 'cipher', 'id', 'number', 'код', 'шифр', 'номер']),
                name: $name,
                unit: $this->firstValue($child, ['unit', 'measure', 'measurement', 'единица', 'едизм', 'измеритель']),
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

        return $this->readerHasAliasAttribute($reader, ['code', 'cipher', 'id', 'number', 'код', 'шифр', 'номер'])
            && $this->readerHasAliasAttribute($reader, ['name', 'title', 'description', 'наименование', 'название']);
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
