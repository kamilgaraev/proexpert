<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

use InvalidArgumentException;

final readonly class TechnologySystemCatalog
{
    public function __construct(
        public string $version,
        public string $contentHash,
        public array $systems,
        public array $requiredFacts,
    ) {}

    public static function fromArray(array $data): self
    {
        $version = $data['version'] ?? null;
        $systems = $data['systems'] ?? null;
        $requiredFacts = $data['recommendation_required_facts'] ?? null;
        if (! is_string($version) || preg_match('/^[a-z0-9._-]{1,64}$/D', $version) !== 1
            || ! is_array($systems) || ! array_is_list($systems) || $systems === [] || count($systems) > 50
            || ! is_array($requiredFacts) || ! array_is_list($requiredFacts) || $requiredFacts === []) {
            throw new InvalidArgumentException('Technology system catalog is invalid.');
        }
        $mapped = [];
        foreach ($systems as $system) {
            if (! is_array($system) || array_is_list($system)) {
                throw new InvalidArgumentException('Technology system catalog entry is invalid.');
            }
            $item = new TechnologySystem(
                id: self::string($system, 'id'),
                nameKey: self::string($system, 'name_key'),
                applicability: self::list($system, 'applicability'),
                requiredFacts: self::list($system, 'required_facts'),
                materials: self::list($system, 'materials'),
                works: self::list($system, 'works'),
                machinery: self::list($system, 'machinery'),
                normIntents: self::list($system, 'norm_intents'),
                quantityFormulas: self::list($system, 'quantity_formulas'),
                regionalPriceAvailability: self::map($system, 'regional_price_availability'),
                costPreview: self::map($system, 'cost_preview'),
                risks: self::list($system, 'risks'),
                assumptions: self::list($system, 'assumptions'),
                scoreRules: self::list($system, 'score_rules'),
                provenance: self::list($system, 'provenance'),
            );
            if (isset($mapped[$item->id])) {
                throw new InvalidArgumentException('Technology system catalog contains a duplicate identifier.');
            }
            $mapped[$item->id] = $item;
        }
        ksort($mapped, SORT_STRING);
        $canonical = self::canonicalize($data);

        return new self(
            $version,
            hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)),
            array_values($mapped),
            $requiredFacts,
        );
    }

    private static function string(array $data, string $key): string
    {
        if (! is_string($data[$key] ?? null)) {
            throw new InvalidArgumentException('Technology system catalog field is invalid.');
        }

        return $data[$key];
    }

    private static function list(array $data, string $key): array
    {
        if (! is_array($data[$key] ?? null) || ! array_is_list($data[$key])) {
            throw new InvalidArgumentException('Technology system catalog list is invalid.');
        }

        return $data[$key];
    }

    private static function map(array $data, string $key): array
    {
        if (! is_array($data[$key] ?? null) || array_is_list($data[$key])) {
            throw new InvalidArgumentException('Technology system catalog map is invalid.');
        }

        return $data[$key];
    }

    private static function canonicalize(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => is_array($item) ? self::canonicalize($item) : $item, $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalize($item);
            }
        }

        return $value;
    }
}
