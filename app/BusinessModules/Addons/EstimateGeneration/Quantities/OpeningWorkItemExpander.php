<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\FrozenWorkItemLimit;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;

final class OpeningWorkItemExpander
{
    public const WORKS = ['openings.windows', 'openings.doors'];

    private static function kinds(string $work, array $scope): array
    {
        $kind = match ($work) {
            'openings.windows' => 'window', 'openings.doors' => 'door', default => null,
        };

        return $kind !== null && ($scope['opening_kind'] ?? null) === $kind ? [$kind] : [];
    }

    public static function permits(array $item, array $scopes): bool
    {
        if (! self::isOpeningWork($item)) {
            return true;
        }
        $work = $item['metadata']['composition_work_key'] ?? $item['metadata']['quantity_key'] ?? null;
        if (! in_array($work, self::WORKS, true)) {
            return false;
        }
        $entity = $item['metadata']['scope_entity_id'] ?? $item['entity_id'] ?? null;
        $scope = is_string($entity) ? ($scopes[$entity] ?? null) : null;

        return is_array($scope) && CanonicalPipelineJson::encode($item['metadata']['opening_scope'] ?? null) === CanonicalPipelineJson::encode($scope)
            && in_array($item['metadata']['opening_kind'] ?? null, self::kinds($work, $scope), true);
    }

    public static function isOpeningWork(array $item): bool
    {
        return isset($item['metadata']['opening_scope'])
            || ($item['metadata']['generation_source'] ?? null) === 'canonical_opening_scope'
            || str_starts_with((string) ($item['key'] ?? ''), 'opening-work:');
    }

    public function expand(array $draft, ProjectModelSnapshot $snapshot, array $scopes, array $quantities,
        ?int $workLimit, array $documentVersions): array
    {
        $openings = OpeningEstimateScope::openings($snapshot, $documentVersions);
        $scopes = array_intersect_key($scopes, $openings);
        $registered = array_filter(array_merge(...array_map(static fn ($local): array => $local['sections'] ?? [], $draft['local_estimates'] ?? [])),
            static fn ($section): bool => ! empty($section['opening_work_templates']));
        if ($scopes === [] && $registered === []) {
            return $draft;
        }
        $names = [];
        foreach ($snapshot->facts as $fact) {
            if ($fact->type === 'entity_identity' && $fact->status === 'confirmed' && is_array($fact->value)
                && is_string($fact->value['name'] ?? null)) {
                $names[$fact->entityId] = mb_substr($fact->value['name'], 0, 160);
            }
        }
        $count = 0;
        foreach ($draft['local_estimates'] ?? [] as $local) {
            foreach ($local['sections'] ?? [] as $section) {
                foreach ($section['work_items'] ?? [] as $item) {
                    $count++;
                }
            }
        }
        FrozenWorkItemLimit::assertCount($count, $workLimit);
        $historyCount = 0;
        foreach ($draft['local_estimates'] ?? [] as $localIndex => $local) {
            foreach ($local['sections'] ?? [] as $sectionIndex => $section) {
                $templates = $section['opening_work_templates'] ?? [];
                $existing = array_column($section['work_items'] ?? [], null, 'key');
                foreach ($section['work_items'] ?? [] as $item) {
                    if (self::isTemplate($item) && ! isset($templates[$item['key']])) {
                        $templates[$item['key']] = ['template' => $item, 'active_keys' => [], 'suppressed_keys' => [],
                            'placeholder_active' => false, 'suppressed' => false];
                    }
                }
                FrozenWorkItemLimit::assertCount(count($templates), $workLimit);
                $blocks = [];
                foreach ($templates as $templateKey => &$record) {
                    if (! is_array($record) || ! is_array($record['template'] ?? null) || ! self::isTemplate($record['template'])
                        || ($record['template']['key'] ?? null) !== $templateKey
                        || ! is_bool($record['placeholder_active'] ?? null) || ! is_bool($record['suppressed'] ?? null)) {
                        throw new \InvalidArgumentException('Invalid opening work template.');
                    }
                    foreach (['active_keys', 'suppressed_keys'] as $field) {
                        if (! is_array($record[$field] ?? null) || ! array_is_list($record[$field])
                            || count($record[$field]) > 10000 - $historyCount
                            || count(array_filter($record[$field], static fn ($key): bool => is_string($key)
                                && preg_match('/\Aopening-work:[a-f0-9]{64}\z/', $key) === 1)) !== count($record[$field])) {
                            throw new \InvalidArgumentException('Invalid opening work template history.');
                        }
                    }
                    if ($record['placeholder_active'] && (! isset($existing[$templateKey]) || ! self::isTemplate($existing[$templateKey]))) {
                        $record['suppressed'] = true;
                    }
                    $suppressed = array_fill_keys([...$record['suppressed_keys'], ...array_diff($record['active_keys'], array_keys($existing))], true);
                    $template = $record['template'];
                    $work = $template['metadata']['composition_work_key'] ?? $template['metadata']['quantity_key'];
                    $rows = [];
                    foreach ($scopes as $entity => $scope) {
                        foreach (self::kinds($work, $scope) as $kind) {
                            $key = 'opening-work:'.hash('sha256', $templateKey."\0".$entity);
                            if (isset($suppressed[$key])) {
                                continue;
                            }
                            $previous = $existing[$key] ?? null;
                            if ($record['suppressed'] && $previous === null) {
                                continue;
                            }
                            if ($previous !== null && ($previous['metadata']['opening_template_key'] ?? null) !== $templateKey) {
                                throw new \InvalidArgumentException('Opening work key collision.');
                            }
                            $name = $names[$entity] ?? $openings[$entity]->attributes['name'] ?? $entity;
                            $row = $previous !== null ? $previous
                                : $this->makeRow($template, $templateKey, $key, $entity, $scope, $work, $kind, (string) $name, $quantities);
                            if ($previous !== null && is_string($previous['name'] ?? null)) {
                                $row['name'] = $previous['name'];
                            }
                            $rows[] = $row;
                            FrozenWorkItemLimit::assertCount(count($rows), $workLimit);
                        }
                    }
                    $generated = array_column($rows, null, 'key');
                    foreach ($existing as $oldKey => $oldRow) {
                        if (($oldRow['metadata']['opening_template_key'] ?? null) === $templateKey && ! isset($generated[$oldKey])) {
                            $rows[] = $oldRow;
                        }
                    }
                    $record['active_keys'] = array_column($rows, 'key');
                    $record['suppressed_keys'] = array_keys($suppressed);
                    sort($record['suppressed_keys'], SORT_STRING);
                    $historyCount += 1 + count($record['active_keys']) + count($record['suppressed_keys']);
                    if ($historyCount > 10000) {
                        throw new \InvalidArgumentException('Opening work template history limit exceeded.');
                    }
                    $record['placeholder_active'] = ! $record['suppressed'] && ($openings === [] || count($scopes) !== count($openings));
                    $blocks[$templateKey] = $record['placeholder_active'] ? [$template, ...$rows] : $rows;
                }
                unset($record);
                $items = [];
                $placed = [];
                foreach ($section['work_items'] ?? [] as $item) {
                    $templateKey = isset($templates[$item['key']]) ? $item['key'] : ($item['metadata']['opening_template_key'] ?? null);
                    if (is_string($templateKey) && isset($blocks[$templateKey])
                        && (self::isTemplate($item) || self::isOpeningWork($item))) {
                        if (! isset($placed[$templateKey])) {
                            array_push($items, ...$blocks[$templateKey]);
                            $placed[$templateKey] = true;
                        }
                    } else {
                        $items[] = $item;
                    }
                }
                foreach (array_diff_key($blocks, $placed) as $block) {
                    array_push($items, ...$block);
                }
                $count += count($items) - count($section['work_items'] ?? []);
                FrozenWorkItemLimit::assertCount($count, $workLimit);
                $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'] = $items;
                if ($templates !== []) {
                    $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['opening_work_templates'] = $templates;
                }
            }
        }

        return $draft;
    }

    private static function isTemplate(array $item): bool
    {
        return in_array($item['metadata']['composition_work_key'] ?? $item['metadata']['quantity_key'] ?? null, self::WORKS, true)
            && ($item['metadata']['generation_source'] ?? null) === 'qualitative_work_catalog'
            && ($item['metadata']['quantity_source'] ?? null) === 'unknown' && ($item['item_type'] ?? null) === 'priced_work'
            && ! isset($item['metadata']['scope_entity_id']) && ! isset($item['entity_id'])
            && ($item['quantity'] ?? null) === null && ! isset($item['quantity_evidence'])
            && empty($item['unit_price']) && empty($item['materials']) && empty($item['labor']) && empty($item['machinery'])
            && empty($item['equipment']) && empty($item['other_resources'])
            && ! ($item['normative_match']['selected_by_user'] ?? false) && empty($item['normative_rate_code']);
    }

    private function makeRow(array $template, string $templateKey, string $key, string $entity, array $scope,
        string $work, string $kind, string $name, array $quantities): array
    {
        $row = $template;
        $row['key'] = $key;
        if (isset($row['logical_key'])) {
            $row['logical_key'] = $key;
        }
        $row['entity_id'] = $entity;
        $row['metadata']['scope_entity_id'] = $entity;
        $row['metadata']['composition_work_key'] = $work;
        $row['metadata']['generation_source'] = 'canonical_opening_scope';
        $row['metadata']['opening_template_key'] = $templateKey;
        $row['metadata']['opening_scope'] = $scope;
        $row['metadata']['opening_kind'] = $kind;
        $row['name'] = $template['name'].' — '.mb_substr($name, 0, 160);
        $row['unit'] = 'm2';
        $row['validation_flags'] = array_values(array_diff($row['validation_flags'] ?? [], ['unit_requires_confirmation']));
        $quantity = (new WorkItemQuantityMapper)->mapForEntity($work, $quantities, $entity);
        $row['quantity'] = null;
        $row['metadata']['quantity_key'] = $quantity?->key ?? $work;
        $row['metadata']['quantity_source'] = $quantity?->source->value ?? 'unknown';
        $row['quantity_formula'] = $quantity?->key ?? $work;
        foreach (['quantity_evidence', 'quantity_evidence_id', 'quantity_evidence_fingerprint', 'quantity_evidence_source_version',
            'quantity_basis', 'quantity_basis_details', 'quantity_status', 'normative_match', 'normative_rate_id', 'normative_rate_code',
            'commercial_price_snapshot', 'price_snapshot', 'evaluation_price_basis_quantity'] as $field) {
            unset($row[$field]);
        }
        foreach (['materials', 'labor', 'machinery', 'other_resources', 'equipment'] as $group) {
            $row[$group] = [];
        }
        foreach (['unit_price', 'total_cost', 'work_cost', 'materials_cost', 'labor_cost', 'machinery_cost', 'other_cost'] as $money) {
            $row[$money] = null;
        }
        $row['pricing_status'] = 'not_calculated';
        $row['pricing_blocker'] = $quantity === null ? 'quantity_unknown' : 'normative_required';
        $row['source_refs'][] = ['fact_id' => $scope['fact_id']];

        return $row;
    }
}
