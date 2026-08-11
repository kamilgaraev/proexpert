<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

use InvalidArgumentException;

final readonly class TechnologySystemCatalog
{
    private const MAX_BYTES = 1048576;

    private const UNITS = ['m', 'm2', 'm3', 'item', 'degree', 'ratio'];

    private const SYSTEM_KEYS = [
        'id', 'name_key', 'applicability', 'required_facts', 'materials', 'works', 'machinery',
        'norm_intents', 'quantity_formulas', 'regional_price_availability', 'cost_preview',
        'risks', 'assumptions', 'score_rules', 'provenance',
    ];

    public function __construct(
        public string $version,
        public string $contentHash,
        public array $systems,
        public array $requiredFacts,
    ) {}

    public static function fromArray(array $data): self
    {
        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($encoded) > self::MAX_BYTES || self::depth($data) > 12
            || array_diff(array_keys($data), ['version', 'recommendation_required_facts', 'systems']) !== []
            || array_diff(['version', 'recommendation_required_facts', 'systems'], array_keys($data)) !== []) {
            throw new InvalidArgumentException('Technology system catalog exceeds its global contract.');
        }
        $version = $data['version'] ?? null;
        $systems = $data['systems'] ?? null;
        $requiredFacts = $data['recommendation_required_facts'] ?? null;
        if (! is_string($version) || preg_match('/^[a-z0-9._-]{1,64}$/D', $version) !== 1
            || ! is_array($systems) || ! array_is_list($systems) || $systems === [] || count($systems) > 50
            || ! is_array($requiredFacts) || ! array_is_list($requiredFacts) || $requiredFacts === []) {
            throw new InvalidArgumentException('Technology system catalog is invalid.');
        }
        self::uniqueStrings($requiredFacts, 100);
        sort($requiredFacts, SORT_STRING);
        $mapped = [];
        foreach ($systems as $system) {
            if (! is_array($system) || array_is_list($system)) {
                throw new InvalidArgumentException('Technology system catalog entry is invalid.');
            }
            self::validateSystem($system);
            $system = self::normalizeSystem($system);
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
        $canonical = self::canonicalize([
            'version' => $version,
            'recommendation_required_facts' => $requiredFacts,
            'systems' => array_map(
                static fn (TechnologySystem $system): array => $system->toArray(),
                array_values($mapped),
            ),
        ]);

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

    private static function normalizeSystem(array $system): array
    {
        foreach (['required_facts', 'risks', 'assumptions'] as $key) {
            sort($system[$key], SORT_STRING);
        }

        return $system;
    }

    private static function validateSystem(array $system): void
    {
        if (array_diff(array_keys($system), self::SYSTEM_KEYS) !== []
            || array_diff(self::SYSTEM_KEYS, array_keys($system)) !== []
            || preg_match('/^[a-z][a-z0-9._-]{2,127}$/D', (string) ($system['id'] ?? '')) !== 1
            || preg_match('/^[a-z][a-z0-9._-]{2,190}$/D', (string) ($system['name_key'] ?? '')) !== 1) {
            throw new InvalidArgumentException('Technology system contains unknown fields.');
        }
        foreach ([
            'applicability' => 20,
            'materials' => 40,
            'works' => 40,
            'machinery' => 20,
            'norm_intents' => 20,
            'quantity_formulas' => 20,
            'score_rules' => 50,
            'provenance' => 20,
        ] as $collection => $limit) {
            if (! is_array($system[$collection] ?? null) || ! array_is_list($system[$collection])
                || count($system[$collection]) > $limit) {
                throw new InvalidArgumentException('Technology system collection is invalid.');
            }
        }
        self::uniqueStrings($system['required_facts'], 50);
        self::uniqueStrings($system['risks'], 50);
        self::uniqueStrings($system['assumptions'], 50);
        foreach ($system['applicability'] as $condition) {
            if (! is_array($condition) || array_is_list($condition) || count($condition) !== 1
                || array_diff(array_keys($condition), [
                    'roof_type', 'minimum_slope_degrees', 'maximum_slope_degrees', 'substrate_requirement',
                    'climate_zone', 'region', 'building_purpose',
                ]) !== []) {
                throw new InvalidArgumentException('Technology applicability condition is invalid.');
            }
            $value = reset($condition);
            if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 128) {
                throw new InvalidArgumentException('Technology applicability value is invalid.');
            }
            $kind = array_key_first($condition);
            if (in_array($kind, ['minimum_slope_degrees', 'maximum_slope_degrees'], true)
                && ! is_numeric($value)) {
                throw new InvalidArgumentException('Technology slope applicability value is invalid.');
            }
        }
        self::uniqueItems($system['materials'] ?? [], ['id', 'intent']);
        self::uniqueItems($system['works'] ?? [], ['id', 'intent']);
        self::uniqueItems($system['machinery'] ?? [], ['id', 'intent']);
        self::uniqueItems($system['norm_intents'] ?? [], ['id', 'stable_intent', 'max_candidates']);
        self::uniqueItems($system['quantity_formulas'] ?? [], ['id', 'expression', 'result_unit', 'operands']);
        foreach (['materials', 'works', 'machinery'] as $collection) {
            foreach ($system[$collection] as $item) {
                if (! self::nonEmptyString($item['intent'] ?? null)) {
                    throw new InvalidArgumentException('Technology item intent is invalid.');
                }
            }
        }
        foreach ($system['norm_intents'] as $intent) {
            if (! self::nonEmptyString($intent['stable_intent'] ?? null)
                || ! str_starts_with($intent['stable_intent'], 'fsnb.')
                || ! is_int($intent['max_candidates']) || $intent['max_candidates'] < 1 || $intent['max_candidates'] > 10
                || isset($intent['code'], $intent['selected_norm'])) {
                throw new InvalidArgumentException('Technology norm intent is invalid.');
            }
        }
        foreach ($system['score_rules'] as $rule) {
            if (! is_array($rule) || ! self::nonEmptyString($rule['fact_type'] ?? null)
                || ! is_int($rule['score'] ?? null) || abs($rule['score']) > 1000
                || ! self::nonEmptyString($rule['reason'] ?? null)
                || array_diff(array_keys($rule), ['fact_type', 'values', 'min', 'max', 'score', 'reason']) !== []
                || (! isset($rule['values']) && ! array_key_exists('min', $rule) && ! array_key_exists('max', $rule))) {
                throw new InvalidArgumentException('Technology score rule is invalid.');
            }
            if (isset($rule['values'])) {
                self::uniqueStrings($rule['values'], 50);
                if (array_key_exists('min', $rule) || array_key_exists('max', $rule)) {
                    throw new InvalidArgumentException('Technology score rule mixes value and range operands.');
                }
            } else {
                foreach (['min', 'max'] as $boundary) {
                    if (array_key_exists($boundary, $rule)
                        && ! is_int($rule[$boundary]) && ! is_float($rule[$boundary])) {
                        throw new InvalidArgumentException('Technology score range is invalid.');
                    }
                }
                if (array_key_exists('min', $rule) && array_key_exists('max', $rule)
                    && $rule['min'] > $rule['max']) {
                    throw new InvalidArgumentException('Technology score range is inverted.');
                }
            }
        }
        foreach ($system['provenance'] as $provenance) {
            if (! is_array($provenance) || count($provenance) !== 1
                || array_diff(array_keys($provenance), ['source', 'catalog_section']) !== []
                || ! self::nonEmptyString(reset($provenance))) {
                throw new InvalidArgumentException('Technology provenance is invalid.');
            }
        }
        self::validateAvailability($system['regional_price_availability'], true);
        self::validateAvailability($system['cost_preview'], false);
        $requiredFacts = array_fill_keys($system['required_facts'] ?? [], true);
        foreach ($system['quantity_formulas'] as $formula) {
            if (! in_array($formula['result_unit'] ?? null, self::UNITS, true)) {
                throw new InvalidArgumentException('Technology formula result unit is invalid.');
            }
            if (! is_string($formula['expression'] ?? null) || trim($formula['expression']) === ''
                || strlen($formula['expression']) > 1000 || ($formula['operands'] ?? []) === []) {
                throw new InvalidArgumentException('Technology formula is invalid.');
            }
            if (! is_array($formula['operands'] ?? null) || ! array_is_list($formula['operands'])
                || $formula['operands'] === [] || count($formula['operands']) > 50) {
                throw new InvalidArgumentException('Technology formula operands are invalid.');
            }
            $operands = [];
            foreach ($formula['operands'] as $operand) {
                if (! is_array($operand) || array_diff(array_keys($operand), ['name', 'type', 'unit']) !== []
                    || ! is_string($operand['name'] ?? null)
                    || ! in_array($operand['type'] ?? null, ['fact', 'parameter'], true)
                    || ! in_array($operand['unit'] ?? null, self::UNITS, true)
                    || isset($operands[$operand['name']])) {
                    throw new InvalidArgumentException('Technology formula operand is invalid.');
                }
                if ($operand['type'] === 'fact' && ! isset($requiredFacts[$operand['name']])) {
                    throw new InvalidArgumentException('Technology formula fact operand is not required.');
                }
                $operands[$operand['name']] = true;
            }
            preg_match_all('/\b[a-z][a-z0-9_]*\b/', (string) ($formula['expression'] ?? ''), $matches);
            foreach (array_unique($matches[0]) as $reference) {
                if (! isset($operands[$reference])) {
                    throw new InvalidArgumentException('Technology formula references an unknown operand.');
                }
            }
        }
    }

    private static function uniqueStrings(mixed $items, int $limit): void
    {
        if (! is_array($items) || ! array_is_list($items) || count($items) > $limit
            || count($items) !== count(array_unique($items, SORT_REGULAR))) {
            throw new InvalidArgumentException('Technology string collection is invalid.');
        }
        foreach ($items as $item) {
            if (! is_string($item) || trim($item) === '' || mb_strlen($item) > 191) {
                throw new InvalidArgumentException('Technology string value is invalid.');
            }
        }
    }

    private static function validateAvailability(mixed $value, bool $price): void
    {
        $keys = $price
            ? ['available', 'region', 'source', 'version', 'reason']
            : ['available', 'currency', 'region', 'source', 'version', 'amount_minor', 'reason'];
        if (! is_array($value) || array_is_list($value) || array_diff(array_keys($value), $keys) !== []
            || array_diff($keys, array_keys($value)) !== [] || ! is_bool($value['available'] ?? null)
            || ! self::nonEmptyString($value['reason'] ?? null)) {
            throw new InvalidArgumentException('Technology availability boundary is invalid.');
        }
        foreach (['region', 'source', 'version'] as $field) {
            if ($value[$field] !== null && ! self::nonEmptyString($value[$field])) {
                throw new InvalidArgumentException('Technology availability source is invalid.');
            }
        }
        if (! $price) {
            if ($value['currency'] !== null && preg_match('/^[A-Z]{3}$/D', (string) $value['currency']) !== 1) {
                throw new InvalidArgumentException('Technology cost currency is invalid.');
            }
            if ($value['amount_minor'] !== null && (! is_int($value['amount_minor']) || $value['amount_minor'] < 0)) {
                throw new InvalidArgumentException('Technology cost amount is invalid.');
            }
        }
    }

    private static function uniqueItems(mixed $items, array $allowedKeys): void
    {
        if (! is_array($items) || ! array_is_list($items)) {
            throw new InvalidArgumentException('Technology nested collection is invalid.');
        }
        $ids = [];
        foreach ($items as $item) {
            if (! is_array($item) || array_diff(array_keys($item), $allowedKeys) !== []
                || array_diff($allowedKeys, array_keys($item)) !== []
                || ! self::nonEmptyString($item['id'] ?? null) || isset($ids[$item['id']])) {
                throw new InvalidArgumentException('Technology nested identifier is invalid.');
            }
            $ids[$item['id']] = true;
        }
    }

    private static function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '' && mb_strlen($value) <= 191;
    }

    private static function depth(array $value, int $depth = 1): int
    {
        $maximum = $depth;
        foreach ($value as $item) {
            if (is_array($item)) {
                $maximum = max($maximum, self::depth($item, $depth + 1));
            }
        }

        return $maximum;
    }
}
