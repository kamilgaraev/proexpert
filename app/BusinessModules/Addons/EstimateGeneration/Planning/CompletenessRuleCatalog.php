<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

use InvalidArgumentException;

final readonly class CompletenessRuleCatalog
{
    private const MAX_BYTES = 1048576;

    private const CLASSIFICATIONS = ['document_missing', 'technology_required', 'optional_recommendation'];

    private const UNITS = ['m', 'm2', 'm3', 'item', 'degree', 'ratio'];

    private const REQUIRED_RULE_KEYS = [
        'id', 'version', 'applicability_fact_types', 'conditions', 'satisfaction_fact_type',
        'satisfaction', 'classification', 'severity', 'impact', 'exclusion_policy', 'work_package',
    ];

    private array $rules;

    private function __construct(public string $version, public string $contentHash, array $rules)
    {
        $this->rules = $rules;
    }

    public static function fromArray(array $data): self
    {
        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($encoded) > self::MAX_BYTES || self::depth($data) > 12
            || array_diff(array_keys($data), ['version', 'rules']) !== []
            || array_diff(['version', 'rules'], array_keys($data)) !== []) {
            throw new InvalidArgumentException('Completeness rule catalog exceeds its global contract.');
        }
        $version = is_string($data['version'] ?? null) ? trim($data['version']) : '';
        $rows = $data['rules'] ?? null;
        if (preg_match('/^[a-z0-9._-]{1,64}$/D', $version) !== 1
            || ! is_array($rows) || ! array_is_list($rows) || $rows === [] || count($rows) > 100) {
            throw new InvalidArgumentException('Completeness rule catalog is invalid.');
        }
        $rules = [];
        $normalizedRows = [];
        foreach ($rows as $row) {
            if (! is_array($row) || array_is_list($row)) {
                throw new InvalidArgumentException('Completeness rule entry is invalid.');
            }
            $id = trim((string) ($row['id'] ?? ''));
            $ruleVersion = trim((string) ($row['version'] ?? ''));
            $classification = (string) ($row['classification'] ?? '');
            $applicability = $row['applicability_fact_types'] ?? null;
            $conditions = $row['conditions'] ?? [];
            $satisfaction = $row['satisfaction'] ?? [];
            $package = $row['work_package'] ?? null;
            if (! is_array($applicability) || ! array_is_list($applicability) || $applicability === []
                || count($applicability) > 50) {
                throw new InvalidArgumentException('Completeness applicability contract is invalid.');
            }
            self::uniqueStrings($applicability, 50);
            if ($id === '' || isset($rules[$id]) || $ruleVersion === ''
                || ! in_array($classification, self::CLASSIFICATIONS, true) || ! is_array($package)
                || ! is_array($conditions) || $conditions === [] || ! is_array($satisfaction)
                || ! self::nonEmptyString($row['severity'] ?? null)
                || ! self::nonEmptyString($row['impact'] ?? null)
                || array_diff(array_keys($row), [
                    'id', 'version', 'applicability_fact_types', 'conditions', 'satisfaction_fact_type',
                    'satisfaction', 'classification', 'severity', 'impact', 'exclusion_policy',
                    'work_package', 'technology_requirement',
                ]) !== []
                || array_diff(self::REQUIRED_RULE_KEYS, array_keys($row)) !== []
                || strlen(json_encode($package, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > 1048576) {
                throw new InvalidArgumentException('Completeness rule entry is invalid.');
            }
            self::validateConditions($conditions);
            self::validateSatisfaction($satisfaction, (string) ($row['satisfaction_fact_type'] ?? ''));
            self::validatePackage($package);
            sort($applicability, SORT_STRING);
            $package = self::normalizePackage($package);
            $row['applicability_fact_types'] = $applicability;
            $row['work_package'] = $package;
            $policy = $row['exclusion_policy'] ?? null;
            $policyKeys = ['id', 'version', 'allowed', 'requires_decision', 'requires_actor', 'requires_reason'];
            if (! is_array($policy) || array_is_list($policy)
                || array_diff(array_keys($policy), $policyKeys) !== []
                || array_diff($policyKeys, array_keys($policy)) !== []
                || ! self::nonEmptyString($policy['id'] ?? null)
                || ! self::nonEmptyString($policy['version'] ?? null)
                || ! is_bool($policy['allowed'] ?? null)
                || ! is_bool($policy['requires_decision'] ?? null)
                || ! is_bool($policy['requires_actor'] ?? null)
                || ! is_bool($policy['requires_reason'] ?? null)) {
                throw new InvalidArgumentException('Completeness exclusion policy is invalid.');
            }
            self::validateTechnologyRequirement($row['technology_requirement'] ?? null);
            $canonical = self::canonical($row);
            $normalizedRows[] = $row;
            $rules[$id] = new CompletenessRule(
                $id,
                $ruleVersion,
                hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
                $applicability,
                $conditions,
                (string) ($row['satisfaction_fact_type'] ?? ''),
                $satisfaction,
                $classification,
                (string) ($row['severity'] ?? 'warning'),
                (string) ($row['impact'] ?? ''),
                $row['exclusion_policy'] ?? [],
                $package,
                is_array($row['technology_requirement'] ?? null) ? $row['technology_requirement'] : null,
            );
        }
        $canonical = self::canonical(['version' => $version, 'rules' => $normalizedRows]);

        return new self($version, hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), array_values($rules));
    }

    public function rules(): array
    {
        return $this->rules;
    }

    private static function canonical(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => is_array($item) ? self::canonical($item) : $item, $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::canonical($item);
            }
        }

        return $value;
    }

    private static function normalizePackage(array $package): array
    {
        foreach (['assumptions', 'risks'] as $key) {
            sort($package[$key], SORT_STRING);
        }
        foreach ($package['norm_intents'] as &$intent) {
            sort($intent['candidate_refs'], SORT_STRING);
        }
        unset($intent);
        if (is_array($package['variants'] ?? null)) {
            ksort($package['variants'], SORT_STRING);
            foreach ($package['variants'] as $name => $variant) {
                $package['variants'][$name] = self::normalizePackage($variant);
            }
        }

        return $package;
    }

    private static function validateConditions(array $conditions): void
    {
        if (! array_is_list($conditions) || count($conditions) > 20) {
            throw new InvalidArgumentException('Completeness conditions must be a list.');
        }
        foreach ($conditions as $condition) {
            if (! is_array($condition) || ! self::nonEmptyString($condition['fact_type'] ?? null)
                || ! in_array($condition['operator'] ?? null, ['present', '=', '!=', 'in', '>', '>=', '<', '<='], true)
                || array_diff(array_keys($condition), ['fact_type', 'operator', 'value', 'values', 'unit']) !== []) {
                throw new InvalidArgumentException('Completeness condition is invalid.');
            }
            if (($condition['operator'] ?? null) === 'in' && ! is_array($condition['values'] ?? null)) {
                throw new InvalidArgumentException('Completeness condition values are invalid.');
            }
            $operator = $condition['operator'];
            $required = match ($operator) {
                'present' => ['fact_type', 'operator'],
                'in' => ['fact_type', 'operator', 'values'],
                default => ['fact_type', 'operator', 'value'],
            };
            if (array_diff($required, array_keys($condition)) !== []
                || ($operator === 'present' && count($condition) !== 2)
                || ($operator === 'in' && (array_key_exists('value', $condition)
                    || ! array_is_list($condition['values']) || $condition['values'] === []))
                || ($operator !== 'in' && array_key_exists('values', $condition))
                || (array_key_exists('unit', $condition) && ! in_array($condition['unit'], self::UNITS, true))) {
                throw new InvalidArgumentException('Completeness condition operand contract is invalid.');
            }
            if ($operator === 'in') {
                if (count($condition['values']) > 50
                    || count($condition['values']) !== count(array_unique($condition['values'], SORT_REGULAR))) {
                    throw new InvalidArgumentException('Completeness condition list is invalid.');
                }
                foreach ($condition['values'] as $value) {
                    if (! is_scalar($value) && $value !== null) {
                        throw new InvalidArgumentException('Completeness condition list value is invalid.');
                    }
                }
            } elseif ($operator !== 'present' && ! is_scalar($condition['value']) && $condition['value'] !== null) {
                throw new InvalidArgumentException('Completeness condition value is invalid.');
            }
            if (in_array($operator, ['>', '>=', '<', '<='], true) && ! is_int($condition['value']) && ! is_float($condition['value'])) {
                throw new InvalidArgumentException('Completeness comparison operand is invalid.');
            }
        }
    }

    private static function validateSatisfaction(array $satisfaction, string $factType): void
    {
        $keys = ['fact_type', 'operator', 'false_means_missing'];
        if (array_is_list($satisfaction)
            || array_diff(array_keys($satisfaction), $keys) !== []
            || array_diff($keys, array_keys($satisfaction)) !== []
            || ! self::nonEmptyString($factType)
            || ($satisfaction['fact_type'] ?? null) !== $factType
            || ($satisfaction['operator'] ?? null) !== 'present'
            || ! is_bool($satisfaction['false_means_missing'] ?? null)) {
            throw new InvalidArgumentException('Completeness satisfaction contract is invalid.');
        }
    }

    private static function validateTechnologyRequirement(mixed $requirement): void
    {
        if ($requirement === null) {
            return;
        }
        $keys = ['decision_kind', 'allow_recommended_applicable'];
        if (! is_array($requirement) || array_is_list($requirement)
            || array_diff(array_keys($requirement), $keys) !== []
            || array_diff($keys, array_keys($requirement)) !== []
            || ! self::nonEmptyString($requirement['decision_kind'] ?? null)
            || ! is_bool($requirement['allow_recommended_applicable'] ?? null)) {
            throw new InvalidArgumentException('Completeness technology requirement is invalid.');
        }
    }

    private static function validatePackage(array $package, bool $variant = false): void
    {
        $keys = [
            'id', 'works', 'materials', 'machinery', 'norm_intents', 'quantity_formulas', 'dependencies',
            'regional_price_availability', 'assumptions', 'risks', 'variant_fact_type', 'variants',
        ];
        $requiredKeys = [
            'id', 'works', 'materials', 'machinery', 'norm_intents', 'quantity_formulas', 'dependencies',
            'regional_price_availability', 'assumptions', 'risks',
        ];
        if (array_diff(array_keys($package), $keys) !== []
            || array_diff($requiredKeys, array_keys($package)) !== []
            || ! self::nonEmptyString($package['id'] ?? null)) {
            throw new InvalidArgumentException('Completeness work package is invalid.');
        }
        $hasVariantFact = array_key_exists('variant_fact_type', $package);
        $hasVariants = array_key_exists('variants', $package);
        if ($hasVariantFact !== $hasVariants) {
            throw new InvalidArgumentException('Completeness package variants are incomplete.');
        }
        foreach ([
            'works' => 40,
            'materials' => 40,
            'machinery' => 20,
            'norm_intents' => 20,
            'quantity_formulas' => 20,
            'dependencies' => 80,
        ] as $collection => $limit) {
            if (! is_array($package[$collection] ?? null) || ! array_is_list($package[$collection])
                || count($package[$collection]) > $limit) {
                throw new InvalidArgumentException('Completeness work package collection is invalid.');
            }
        }
        $workIds = [];
        foreach (['works', 'materials', 'machinery', 'norm_intents', 'quantity_formulas'] as $collection) {
            $items = $package[$collection] ?? null;
            if (! is_array($items) || ! array_is_list($items)) {
                throw new InvalidArgumentException('Completeness work package collection is invalid.');
            }
            $ids = [];
            foreach ($items as $item) {
                $id = is_array($item) ? ($item['id'] ?? null) : null;
                $expectedKeys = match ($collection) {
                    'works', 'materials', 'machinery' => ['id', 'name_key'],
                    'norm_intents' => ['id', 'candidate_refs', 'max_candidates'],
                    'quantity_formulas' => ['id', 'expression', 'input_fact', 'unit'],
                };
                if (! self::nonEmptyString($id) || isset($ids[$id]) || ! is_array($item)
                    || array_diff(array_keys($item), $expectedKeys) !== []
                    || array_diff($expectedKeys, array_keys($item)) !== []) {
                    throw new InvalidArgumentException('Completeness work package identifier is invalid.');
                }
                if (in_array($collection, ['works', 'materials', 'machinery'], true)
                    && ! self::nonEmptyString($item['name_key'] ?? null)) {
                    throw new InvalidArgumentException('Completeness work package name is invalid.');
                }
                $ids[$id] = true;
            }
            if ($collection === 'works') {
                $workIds = $ids;
            }
        }
        foreach ($package['quantity_formulas'] as $formula) {
            if (! self::nonEmptyString($formula['expression'] ?? null)
                || ! self::nonEmptyString($formula['input_fact'] ?? null)
                || ! in_array($formula['unit'] ?? null, ['m', 'm2', 'm3', 'item'], true)) {
                throw new InvalidArgumentException('Completeness quantity formula is invalid.');
            }
        }
        foreach ($package['norm_intents'] as $intent) {
            if (! is_array($intent['candidate_refs'] ?? null) || ! array_is_list($intent['candidate_refs'])
                || ! is_int($intent['max_candidates'] ?? null) || $intent['max_candidates'] < 1
                || $intent['max_candidates'] > 5) {
                throw new InvalidArgumentException('Completeness norm intent is invalid.');
            }
            self::uniqueStrings($intent['candidate_refs'], 5);
        }
        $dependencies = [];
        foreach ($package['dependencies'] as $dependency) {
            if (! is_array($dependency) || array_keys($dependency) !== ['from', 'to']
                || ! isset($workIds[(string) ($dependency['from'] ?? '')], $workIds[(string) ($dependency['to'] ?? '')])
                || $dependency['from'] === $dependency['to']) {
                throw new InvalidArgumentException('Completeness dependency is invalid.');
            }
            $identity = $dependency['from'].'>'.$dependency['to'];
            if (isset($dependencies[$identity])) {
                throw new InvalidArgumentException('Completeness dependency is duplicated.');
            }
            $dependencies[$identity] = true;
        }
        self::validateAvailability($package['regional_price_availability']);
        self::uniqueStrings($package['assumptions'], 50);
        self::uniqueStrings($package['risks'], 50);
        if (! $variant && $hasVariantFact && $hasVariants) {
            if (! self::nonEmptyString($package['variant_fact_type']) || ! is_array($package['variants'])
                || array_is_list($package['variants']) || $package['variants'] === [] || count($package['variants']) > 20) {
                throw new InvalidArgumentException('Completeness package variants are invalid.');
            }
            foreach ($package['variants'] as $name => $candidate) {
                if (! self::nonEmptyString($name) || ! is_array($candidate)) {
                    throw new InvalidArgumentException('Completeness package variant is invalid.');
                }
                self::validatePackage($candidate, true);
            }
        } elseif ($variant && (isset($package['variant_fact_type']) || isset($package['variants']))) {
            throw new InvalidArgumentException('Nested completeness package variants are invalid.');
        }
    }

    private static function validateAvailability(mixed $availability): void
    {
        $keys = ['available', 'region', 'source', 'version', 'reason'];
        if (! is_array($availability) || array_is_list($availability)
            || array_diff(array_keys($availability), $keys) !== []
            || array_diff($keys, array_keys($availability)) !== []
            || ! is_bool($availability['available'] ?? null)
            || ! self::nonEmptyString($availability['reason'] ?? null)) {
            throw new InvalidArgumentException('Completeness regional availability is invalid.');
        }
        foreach (['region', 'source', 'version'] as $field) {
            if ($availability[$field] !== null && ! self::nonEmptyString($availability[$field])) {
                throw new InvalidArgumentException('Completeness regional availability source is invalid.');
            }
        }
    }

    private static function uniqueStrings(mixed $values, int $limit): void
    {
        if (! is_array($values) || ! array_is_list($values) || count($values) > $limit
            || count($values) !== count(array_unique($values, SORT_REGULAR))) {
            throw new InvalidArgumentException('Completeness string collection is invalid.');
        }
        foreach ($values as $value) {
            if (! self::nonEmptyString($value)) {
                throw new InvalidArgumentException('Completeness string value is invalid.');
            }
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
