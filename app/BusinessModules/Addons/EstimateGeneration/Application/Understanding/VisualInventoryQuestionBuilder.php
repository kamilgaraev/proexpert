<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Understanding;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use Closure;

final readonly class VisualInventoryQuestionBuilder
{
    private Closure $translator;

    public function __construct(?Closure $translator = null)
    {
        $this->translator = $translator ?? static fn (string $key, array $replace = []): string => trans_message($key, $replace);
    }

    /** @return list<array<string, mixed>> */
    public function build(array $entities, array $facts, array $evidence): array
    {
        $entitiesById = [];
        foreach ($entities as $entity) {
            if ($entity instanceof Entity) {
                $entitiesById[$entity->id] = $entity;
            }
        }
        $evidenceById = [];
        foreach ($evidence as $item) {
            if ($item instanceof Evidence) {
                $evidenceById[$item->id] = $item;
            }
        }
        $confirmedEntities = [];
        foreach ($facts as $fact) {
            if ($fact instanceof Fact && $fact->status === 'confirmed') {
                $confirmedEntities[$fact->entityId] = true;
            }
        }

        $groups = [];
        foreach ($facts as $fact) {
            $entity = $fact instanceof Fact ? ($entitiesById[$fact->entityId] ?? null) : null;
            $category = $entity instanceof Entity ? ($entity->attributes['properties']['visual_inventory_category'] ?? null) : null;
            if (! $fact instanceof Fact || ! $entity instanceof Entity
                || isset($confirmedEntities[$fact->entityId])
                || ! in_array($category, ['sanitary_fixture', 'kitchen_fixture', 'unknown_fixture'], true)
                || $fact->status === 'invalidated') {
                continue;
            }
            $room = is_string($entity->attributes['properties']['room_key'] ?? null)
                ? $entity->attributes['properties']['room_key']
                : 'room:unspecified';
            $groups[$room]['facts'][$fact->id] = $fact;
            $groups[$room]['labels'][$entity->attributes['name']] = true;
            foreach ($fact->evidenceIds as $evidenceId) {
                $groups[$room]['evidence'][$evidenceId] = true;
            }
        }

        $questions = [];
        foreach ($groups as $room => $group) {
            $factIds = array_keys($group['facts']);
            $evidenceIds = array_keys($group['evidence']);
            $labels = implode(', ', array_keys($group['labels']));
            $pages = [];
            foreach ($evidenceIds as $evidenceId) {
                $page = $evidenceById[$evidenceId]->page ?? null;
                if (is_int($page) && $page > 0) {
                    $pages[$page] = true;
                }
            }
            ksort($pages, SORT_NUMERIC);
            $id = 'visual-inventory:'.substr(hash('sha256', $room.'|'.implode('|', $factIds)), 0, 40);
            $questions[] = [
                'conflict_id' => $id,
                'text' => ($this->translator)('estimate_generation.project_model.visual_inventory.question', ['items' => $labels]),
                'reason' => ($this->translator)('estimate_generation.project_model.visual_inventory.reason'),
                'impact' => ($this->translator)('estimate_generation.project_model.visual_inventory.impact'),
                'recommendation' => ($this->translator)('estimate_generation.project_model.visual_inventory.recommendation'),
                'fact_ids' => $factIds,
                'evidence_ids' => $evidenceIds,
                'reason_code' => 'visual_inventory_scope_confirmation',
                'source_locator' => ['evidence_ids' => $evidenceIds, 'page_numbers' => array_keys($pages)],
                'options' => [
                    ['value' => 'include_supply_and_installation', 'label' => ($this->translator)('estimate_generation.project_model.visual_inventory.include'), 'evidence_ids' => $evidenceIds],
                    ['value' => 'installation_only', 'label' => ($this->translator)('estimate_generation.project_model.visual_inventory.installation_only'), 'evidence_ids' => $evidenceIds],
                    ['value' => 'exclude', 'label' => ($this->translator)('estimate_generation.project_model.visual_inventory.exclude'), 'evidence_ids' => $evidenceIds],
                ],
            ];
        }

        return $questions;
    }
}
