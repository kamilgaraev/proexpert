<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use Closure;
use InvalidArgumentException;

final class TechnologyWorkPackageBuilder
{
    private Closure $translate;

    public function __construct(?Closure $translate = null)
    {
        $this->translate = $translate ?? static fn (string $key): string => $key;
    }

    public function build(CompletenessRule $rule, array $factsByType): TechnologyWorkPackage
    {
        $package = $rule->workPackage;
        $variantFactType = $package['variant_fact_type'] ?? null;
        if (is_string($variantFactType)) {
            $variant = $factsByType[$variantFactType][0]->value ?? null;
            $selected = is_string($variant) ? ($package['variants'][$variant] ?? null) : null;
            if (! is_array($selected)) {
                throw new InvalidArgumentException('Technology work package variant is unresolved.');
            }
            $package = $selected;
        }
        $works = $this->labels($this->unique($package['works'] ?? []));
        $dependencies = $package['dependencies'] ?? [];
        $this->assertDag($works, $dependencies);
        $formulas = [];
        foreach ($package['quantity_formulas'] ?? [] as $formula) {
            $input = (string) ($formula['input_fact'] ?? '');
            $fact = $factsByType[$input][0] ?? null;
            $resolved = $fact instanceof Fact && is_scalar($fact->value) && is_numeric((string) $fact->value);
            $formulas[] = [
                'id' => (string) $formula['id'],
                'expression' => (string) $formula['expression'],
                'unit' => (string) $formula['unit'],
                'operands' => $resolved ? [[
                    'fact_id' => $fact->id,
                    'fact_type' => $input,
                    'value' => (string) $fact->value,
                    'unit' => $fact->unit,
                    'version' => $fact->version,
                    'status' => $fact->status,
                ]] : [],
                ...($resolved ? ['resolved_value' => (string) $fact->value] : ['unresolved_inputs' => [$input]]),
            ];
        }

        return new TechnologyWorkPackage(
            id: (string) ($package['id'] ?? 'package:'.$rule->id),
            works: $works,
            materials: $this->labels($this->unique($package['materials'] ?? [])),
            machinery: $this->labels($this->unique($package['machinery'] ?? [])),
            normIntents: array_slice($this->unique($package['norm_intents'] ?? []), 0, 20),
            quantityFormulas: $formulas,
            dependencies: $dependencies,
            regionalPriceAvailability: $package['regional_price_availability'] ?? ['available' => false, 'reason' => 'price_check_required'],
            assumptions: $package['assumptions'] ?? [],
            risks: $package['risks'] ?? [],
            provenance: ['rule_id' => $rule->id, 'rule_version' => $rule->version, 'rule_hash' => $rule->contentHash],
        );
    }

    private function unique(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            $id = (string) ($item['id'] ?? '');
            if ($id === '' || isset($result[$id])) {
                continue;
            }
            $result[$id] = $item;
        }

        return array_values($result);
    }

    private function labels(array $items): array
    {
        return array_map(function (array $item): array {
            $key = (string) ($item['name_key'] ?? '');
            $label = $key === '' ? '' : ($this->translate)($key);
            if ($label === '' || $label === $key) {
                throw new InvalidArgumentException('Technology work package translation is missing.');
            }

            return [...$item, 'label' => $label];
        }, $items);
    }

    private function assertDag(array $works, array $dependencies): void
    {
        $nodes = array_fill_keys(array_column($works, 'id'), true);
        $edges = [];
        $indegree = array_fill_keys(array_keys($nodes), 0);
        foreach ($dependencies as $edge) {
            $from = (string) ($edge['from'] ?? '');
            $to = (string) ($edge['to'] ?? '');
            if (! isset($nodes[$from], $nodes[$to]) || $from === $to) {
                throw new InvalidArgumentException('Technology work package dependency is invalid.');
            }
            $edges[$from][] = $to;
            $indegree[$to]++;
        }
        $queue = array_keys(array_filter($indegree, static fn (int $value): bool => $value === 0));
        $visited = 0;
        while ($queue !== []) {
            $node = array_shift($queue);
            $visited++;
            foreach ($edges[$node] ?? [] as $next) {
                if (--$indegree[$next] === 0) {
                    $queue[] = $next;
                }
            }
        }
        if ($visited !== count($nodes)) {
            throw new InvalidArgumentException('Technology work package dependencies contain a cycle.');
        }
    }
}
