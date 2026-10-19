<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;

final class EquipmentEstimateScope
{
    public const SUPPLY_AND_INSTALLATION = 'include_supply_and_installation';

    public const INSTALLATION_ONLY = 'installation_only';

    public static function answerFactIds(ProjectModelSnapshot $snapshot): array
    {
        return array_values(array_map(static fn (Fact $fact): string => $fact->id,
            array_filter($snapshot->facts, self::isAnswer(...))));
    }

    public static function select(ProjectModelSnapshot $snapshot, array $decisions): array
    {
        $equipment = $restricted = [];
        foreach ($snapshot->entities as $entity) {
            $properties = $entity->attributes['properties'] ?? [];
            $scopes = array_filter([$entity->attributes['estimate_scope'] ?? null, $properties['estimate_scope'] ?? null], 'is_string');
            if (($entity->attributes['semantic_type'] ?? $properties['semantic_type'] ?? $entity->type) !== 'equipment'
                || array_intersect($scopes, ['contextual_only', 'excluded_by_document_note']) !== []) {
                continue;
            }
            $equipment[$entity->id] = $entity;
            $restricted[$entity->id] = $scopes !== []
                || isset($entity->attributes['visual_inventory_category']) || isset($properties['visual_inventory_category']);
        }
        $choices = self::choices($snapshot, $decisions);
        $result = [];
        foreach (array_keys($equipment) as $id) {
            $modes = array_keys($choices[$id] ?? []);
            if ($modes === [] && ! $restricted[$id]) {
                $result[$id] = self::SUPPLY_AND_INSTALLATION;
            } elseif (count($modes) === 1 && in_array($modes[0], [self::SUPPLY_AND_INSTALLATION, self::INSTALLATION_ONLY], true)) {
                $result[$id] = $modes[0];
            }
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    public static function answeredEntityIds(ProjectModelSnapshot $snapshot, array $decisions): array
    {
        return array_keys(self::choices($snapshot, $decisions));
    }

    private static function choices(ProjectModelSnapshot $snapshot, array $decisions): array
    {
        $entities = array_column($snapshot->entities, null, 'id');
        $byFact = array_column($snapshot->facts, null, 'id');
        $accepted = [];
        foreach ($decisions as $decision) {
            $fact = $decision instanceof Decision ? ($byFact[$decision->selectedFactId ?? ''] ?? null) : null;
            if (! $fact instanceof Fact || ! self::isAnswer($fact) || $decision->actorType !== 'user'
                || $decision->targetType !== 'fact' || $decision->sourceVersion !== $fact->sourceVersion
                || $decision->organizationId !== $fact->organizationId || $decision->projectId !== $fact->projectId
                || $decision->sessionId !== $fact->sessionId) {
                continue;
            }
            $target = $byFact[$decision->targetId] ?? null;
            if ($target instanceof Fact && ($target->entityId !== $fact->entityId || $target->sourceVersion !== $fact->sourceVersion)) {
                continue;
            }
            $byFact[$decision->targetId] ??= $fact;
            if ($fact->supersedesFactId !== null) {
                $byFact[$fact->supersedesFactId] ??= $fact;
            }
            $accepted[$fact->id] = $fact;
        }
        $choices = [];
        foreach ($accepted as $fact) {
            $mode = $fact->status === 'confirmed' && ($fact->value['response'] ?? null) === 'selected'
                && in_array($fact->value['choice_value'] ?? null, [self::SUPPLY_AND_INSTALLATION, self::INSTALLATION_ONLY], true)
                ? $fact->value['choice_value'] : 'exclude';
            foreach ($fact->value['source_locator']['fact_ids'] as $factId) {
                $source = is_string($factId) ? ($byFact[$factId] ?? null) : null;
                if ($source instanceof Fact && isset($entities[$source->entityId])
                    && $source->sourceVersion === $fact->sourceVersion
                    && $entities[$source->entityId]->sourceVersion === $fact->sourceVersion) {
                    $choices[$source->entityId][$mode] = true;
                }
            }
        }

        return $choices;
    }

    public static function workCount(array $scopes): int
    {
        return array_sum(array_map(static fn (string $mode): int => $mode === self::SUPPLY_AND_INSTALLATION ? 2 : 1, $scopes));
    }

    private static function isAnswer(Fact $fact): bool
    {
        return (($fact->origin === 'user_assumption' && $fact->status === 'confirmed')
                || ($fact->origin === 'unresolved' && $fact->status === 'unresolved'))
            && is_array($fact->value) && is_string($fact->value['question_key'] ?? null)
            && is_string($fact->value['question_fingerprint'] ?? null)
            && is_string($fact->value['source_locator']['conflict_id'] ?? null)
            && str_starts_with($fact->value['source_locator']['conflict_id'], 'visual-inventory:')
            && is_array($fact->value['source_locator']['fact_ids'] ?? null);
    }
}
