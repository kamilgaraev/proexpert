<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\FrozenWorkItemLimit;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;

final class WallWorkItemExpander
{
    public const WORKS = ['walls.external_volume', 'walls.internal', 'rough.walls', 'finish.paint', 'facade.area'];

    public function __construct(private readonly ?\Closure $translate = null) {}

    public static function surfaces(string $work, array $scope): array
    {
        return match ($work) {
            'walls.external_volume' => $scope['role'] === 'external' ? ['body'] : [],
            'walls.internal' => $scope['role'] === 'partition' ? ['body'] : [],
            'facade.area' => $scope['role'] === 'external' && in_array('outside', $scope['finished_surfaces'], true) ? ['outside'] : [],
            'rough.walls', 'finish.paint' => array_values(array_diff($scope['finished_surfaces'], ['outside'])),
            default => [],
        };
    }

    public static function permits(array $item, array $scopes, bool $requireAssembly = true): bool
    {
        if (! $requireAssembly && ! self::isAssemblyWork($item)) {
            return true;
        }
        $work = $item['metadata']['composition_work_key'] ?? $item['metadata']['quantity_key'] ?? null;
        if (! in_array($work, self::WORKS, true)) {
            return ($item['metadata']['generation_source'] ?? null) !== 'canonical_wall_assembly'
                && ! str_starts_with((string) ($item['key'] ?? ''), 'wall-work:');
        }
        $entity = $item['metadata']['scope_entity_id'] ?? $item['entity_id'] ?? null;
        $scope = is_string($entity) ? ($scopes[$entity] ?? null) : null;

        return is_array($scope) && CanonicalPipelineJson::encode($item['metadata']['wall_assembly'] ?? null) === CanonicalPipelineJson::encode($scope)
            && in_array($item['metadata']['wall_surface'] ?? null, self::surfaces($work, $scope), true);
    }

    public static function isAssemblyWork(array $item): bool
    {
        return isset($item['metadata']['wall_assembly'])
            || ($item['metadata']['generation_source'] ?? null) === 'canonical_wall_assembly'
            || str_starts_with((string) ($item['key'] ?? ''), 'wall-work:');
    }

    public function expand(array $draft, ProjectModelSnapshot $snapshot, array $scopes, array $quantities,
        ?int $workLimit, array $documentVersions): array
    {
        $walls = WallEstimateScope::walls($snapshot, $documentVersions);
        $scopes = array_intersect_key($scopes, $walls);
        if ($scopes === []) {
            return $draft;
        }
        $names = [];
        foreach ($snapshot->facts as $fact) {
            if ($fact->type === 'entity_identity' && $fact->status === 'confirmed' && is_array($fact->value)
                && is_string($fact->value['name'] ?? null)) {
                $names[$fact->entityId] = mb_substr($fact->value['name'], 0, 160);
            }
        }
        $existing = [];
        $count = 0;
        foreach ($draft['local_estimates'] ?? [] as $local) {
            foreach ($local['sections'] ?? [] as $section) {
                foreach ($section['work_items'] ?? [] as $item) {
                    $count++;
                    $existing[$item['key']] = true;
                }
            }
        }
        FrozenWorkItemLimit::assertCount($count, $workLimit);
        foreach ($draft['local_estimates'] ?? [] as $localIndex => $local) {
            foreach ($local['sections'] ?? [] as $sectionIndex => $section) {
                $items = [];
                foreach ($section['work_items'] ?? [] as $item) {
                    $work = $item['metadata']['composition_work_key'] ?? $item['metadata']['quantity_key'] ?? null;
                    if (! in_array($work, self::WORKS, true) || ($item['metadata']['generation_source'] ?? null) !== 'qualitative_work_catalog'
                        || ($item['metadata']['quantity_source'] ?? null) !== 'unknown' || ($item['item_type'] ?? null) !== 'priced_work'
                        || isset($item['metadata']['scope_entity_id']) || isset($item['entity_id'])
                        || ($item['normative_match']['selected_by_user'] ?? false) || ! empty($item['normative_rate_code'])) {
                        $items[] = $item;

                        continue;
                    }
                    $seen = array_fill_keys($item['metadata']['wall_generated_keys'] ?? [], true);
                    $new = [];
                    foreach ($scopes as $entity => $scope) {
                        foreach (self::surfaces($work, $scope) as $surface) {
                            $key = 'wall-work:'.hash('sha256', $item['key']."\0".$entity."\0".$surface);
                            if (isset($seen[$key]) || isset($existing[$key])) {
                                $seen[$key] = true;

                                continue;
                            }
                            $row = $item;
                            $row['key'] = $key;
                            if (isset($row['logical_key'])) {
                                $row['logical_key'] = $key;
                            }
                            $row['entity_id'] = $entity;
                            $row['metadata']['scope_entity_id'] = $entity;
                            $row['metadata']['composition_work_key'] = $work;
                            $row['metadata']['generation_source'] = 'canonical_wall_assembly';
                            $row['metadata']['wall_assembly'] = $scope;
                            $row['metadata']['wall_surface'] = $surface;
                            unset($row['metadata']['wall_generated_keys']);
                            $name = $names[$entity] ?? $walls[$entity]->attributes['name'] ?? $entity;
                            $row['name'] = $item['name'].' — '.mb_substr((string) $name, 0, 160)
                                .($surface === 'body' ? '' : ' / '.$this->text('estimate_generation.wall_assembly.surfaces.'.$surface));
                            $row['unit'] = $work === 'walls.external_volume' ? 'm3' : 'm2';
                            $formula = $work === 'walls.external_volume' ? 'wall_net_volume' : 'wall_net_area';
                            $matches = array_values(array_filter($quantities, static fn ($quantity): bool => $quantity instanceof QuantityData
                                && $quantity->formulaKey === $formula && ($quantity->formulaInputs['entity_id'] ?? null) === $entity
                                && $quantity->reviewBlockers === []));
                            $quantity = count($matches) === 1 ? $matches[0] : null;
                            $row['quantity'] = null;
                            $row['metadata']['quantity_key'] = $quantity?->key ?? $work;
                            $row['metadata']['quantity_source'] = $quantity?->source->value ?? 'unknown';
                            $row['quantity_formula'] = $quantity?->key ?? $work;
                            unset($row['quantity_evidence'], $row['quantity_evidence_id'], $row['quantity_evidence_fingerprint'],
                                $row['quantity_evidence_source_version'], $row['quantity_basis'], $row['quantity_basis_details'], $row['quantity_status']);
                            foreach (['materials', 'labor', 'machinery', 'other_resources', 'equipment'] as $group) {
                                $row[$group] = [];
                            }
                            foreach (['unit_price', 'total_cost', 'work_cost', 'materials_cost', 'labor_cost', 'machinery_cost', 'other_cost'] as $money) {
                                $row[$money] = null;
                            }
                            $row['pricing_status'] = 'not_calculated';
                            $row['pricing_blocker'] = $quantity === null ? 'quantity_unknown' : 'normative_required';
                            $row['source_refs'][] = ['fact_id' => $scope['fact_id']];
                            $new[] = $row;
                            $seen[$key] = $existing[$key] = true;
                        }
                    }
                    $complete = $walls !== [] && count(array_intersect_key($scopes, $walls)) === count($walls);
                    $count += count($new) - ($complete ? 1 : 0);
                    FrozenWorkItemLimit::assertCount($count, $workLimit);
                    if (! $complete) {
                        $item['metadata']['wall_generated_keys'] = array_keys($seen);
                        $items[] = $item;
                    }
                    array_push($items, ...$new);
                }
                $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'] = $items;
            }
        }

        return $draft;
    }

    private function text(string $key): string
    {
        return $this->translate === null ? trans_message($key) : ($this->translate)($key);
    }
}
