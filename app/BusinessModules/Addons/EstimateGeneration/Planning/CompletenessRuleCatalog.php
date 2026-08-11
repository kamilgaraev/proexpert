<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

use InvalidArgumentException;

final readonly class CompletenessRuleCatalog
{
    private const MAX_BYTES = 1048576;

    private const CLASSIFICATIONS = ['document_missing', 'technology_required', 'optional_recommendation'];

    private array $rules;

    private function __construct(public string $version, public string $contentHash, array $rules)
    {
        $this->rules = $rules;
    }

    public static function fromArray(array $data): self
    {
        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($encoded) > self::MAX_BYTES || self::depth($data) > 12
            || array_diff(array_keys($data), ['version', 'rules']) !== []) {
            throw new InvalidArgumentException('Completeness rule catalog exceeds its global contract.');
        }
        $version = trim((string) ($data['version'] ?? ''));
        $rows = $data['rules'] ?? null;
        if ($version === '' || ! is_array($rows) || $rows === [] || count($rows) > 100) {
            throw new InvalidArgumentException('Completeness rule catalog is invalid.');
        }
        $rules = [];
        foreach ($rows as $row) {
            $id = trim((string) ($row['id'] ?? ''));
            $ruleVersion = trim((string) ($row['version'] ?? ''));
            $classification = (string) ($row['classification'] ?? '');
            $applicability = array_values(array_unique($row['applicability_fact_types'] ?? []));
            $conditions = $row['conditions'] ?? [];
            $satisfaction = $row['satisfaction'] ?? [];
            $package = $row['work_package'] ?? null;
            if ($id === '' || isset($rules[$id]) || $ruleVersion === '' || $applicability === []
                || ! in_array($classification, self::CLASSIFICATIONS, true) || ! is_array($package)
                || ! is_array($conditions) || $conditions === [] || ! is_array($satisfaction)
                || array_diff(array_keys($row), [
                    'id', 'version', 'applicability_fact_types', 'conditions', 'satisfaction_fact_type',
                    'satisfaction', 'classification', 'severity', 'impact', 'exclusion_policy',
                    'work_package', 'technology_requirement',
                ]) !== []
                || count($package['works'] ?? []) > 40 || count($package['materials'] ?? []) > 40
                || count($package['machinery'] ?? []) > 20 || count($package['norm_intents'] ?? []) > 20
                || count($package['quantity_formulas'] ?? []) > 20
                || strlen(json_encode($package, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > 1048576) {
                throw new InvalidArgumentException('Completeness rule entry is invalid.');
            }
            self::validateConditions($conditions);
            self::validatePackage($package);
            $policy = $row['exclusion_policy'] ?? null;
            if (! is_array($policy) || ! is_string($policy['id'] ?? null)
                || ! is_string($policy['version'] ?? null) || ! is_bool($policy['allowed'] ?? null)) {
                throw new InvalidArgumentException('Completeness exclusion policy is invalid.');
            }
            foreach ($package['norm_intents'] ?? [] as $intent) {
                if (($intent['max_candidates'] ?? 0) < 1 || $intent['max_candidates'] > 5
                    || count($intent['candidate_refs'] ?? []) > 5) {
                    throw new InvalidArgumentException('Completeness norm candidates are invalid.');
                }
            }
            $canonical = self::canonical($row);
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
        $canonical = self::canonical(['version' => $version, 'rules' => $rows]);

        return new self($version, hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), array_values($rules));
    }

    public function rules(): array
    {
        return $this->rules;
    }

    private static function canonical(array $value): array
    {
        if (array_is_list($value)) {
            $canonical = array_map(static fn (mixed $item): mixed => is_array($item) ? self::canonical($item) : $item, $value);
            $dependencies = $canonical !== [] && is_array($canonical[0])
                && array_keys($canonical[0]) === ['from', 'to'];
            if (! $dependencies) {
                usort($canonical, static fn (mixed $left, mixed $right): int => json_encode($left, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                    <=> json_encode($right, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            }

            return $canonical;
        }
        ksort($value, SORT_STRING);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::canonical($item);
            }
        }

        return $value;
    }

    private static function validateConditions(array $conditions): void
    {
        foreach ($conditions as $condition) {
            if (! is_array($condition) || ! is_string($condition['fact_type'] ?? null)
                || ! in_array($condition['operator'] ?? null, ['present', '=', '!=', 'in', '>', '>=', '<', '<='], true)
                || array_diff(array_keys($condition), ['fact_type', 'operator', 'value', 'values', 'unit']) !== []) {
                throw new InvalidArgumentException('Completeness condition is invalid.');
            }
            if (($condition['operator'] ?? null) === 'in' && ! is_array($condition['values'] ?? null)) {
                throw new InvalidArgumentException('Completeness condition values are invalid.');
            }
        }
    }

    private static function validatePackage(array $package, bool $variant = false): void
    {
        $keys = [
            'id', 'works', 'materials', 'machinery', 'norm_intents', 'quantity_formulas', 'dependencies',
            'regional_price_availability', 'assumptions', 'risks', 'variant_fact_type', 'variants',
        ];
        if (array_diff(array_keys($package), $keys) !== [] || ! is_string($package['id'] ?? null)) {
            throw new InvalidArgumentException('Completeness work package is invalid.');
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
                if (! is_string($id) || isset($ids[$id]) || ! is_array($item)
                    || array_diff(array_keys($item), $expectedKeys) !== []
                    || array_diff($expectedKeys, array_keys($item)) !== []) {
                    throw new InvalidArgumentException('Completeness work package identifier is invalid.');
                }
                if (in_array($collection, ['works', 'materials', 'machinery'], true)
                    && ! is_string($item['name_key'] ?? null)) {
                    throw new InvalidArgumentException('Completeness work package name is invalid.');
                }
                $ids[$id] = true;
            }
            if ($collection === 'works') {
                $workIds = $ids;
            }
        }
        foreach ($package['quantity_formulas'] as $formula) {
            if (! is_string($formula['expression'] ?? null)
                || ! is_string($formula['input_fact'] ?? null)
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
        }
        foreach ($package['dependencies'] ?? [] as $dependency) {
            if (! is_array($dependency) || array_keys($dependency) !== ['from', 'to']
                || ! isset($workIds[(string) ($dependency['from'] ?? '')], $workIds[(string) ($dependency['to'] ?? '')])) {
                throw new InvalidArgumentException('Completeness dependency is invalid.');
            }
        }
        if (! $variant && isset($package['variant_fact_type'], $package['variants'])) {
            if (! is_string($package['variant_fact_type']) || ! is_array($package['variants']) || $package['variants'] === []) {
                throw new InvalidArgumentException('Completeness package variants are invalid.');
            }
            foreach ($package['variants'] as $name => $candidate) {
                if (! is_string($name) || ! is_array($candidate)) {
                    throw new InvalidArgumentException('Completeness package variant is invalid.');
                }
                self::validatePackage($candidate, true);
            }
        } elseif ($variant && (isset($package['variant_fact_type']) || isset($package['variants']))) {
            throw new InvalidArgumentException('Nested completeness package variants are invalid.');
        }
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
