<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use InvalidArgumentException;

final class ScopeCompletenessEvaluator
{
    public const VERSION = 'universal-scope:v1';

    private const REQUIREMENTS = [
        'finishing' => ['preparation', 'finishing'],
        'structures' => ['preparation', 'structures'],
        'roofing' => ['preparation', 'roof_substrate', 'roof_covering', 'roof_drainage'],
        'earthworks' => ['site_preparation', 'excavation', 'soil_removal'],
        'water_supply' => ['installation', 'water_supply_test_program', 'water_supply_testing'],
        'sewerage' => ['installation', 'sewerage_test_program', 'sewerage_testing'],
        'heating' => ['installation', 'heating_test_program', 'heating_testing'],
        'ventilation' => ['installation', 'ventilation_test_program', 'ventilation_testing'],
        'electricity' => ['installation', 'electricity_test_program', 'electricity_testing'],
        'automation' => ['installation', 'automation_test_program', 'automation_testing'],
        'technological_pipelines' => ['installation', 'pipeline_specification', 'pipeline_test_program', 'pipeline_testing'],
        'equipment' => ['equipment_specification', 'equipment_purchase', 'delivery', 'installation', 'commissioning'],
    ];

    public function evaluate(ProjectModelSnapshot $snapshot, string $profile, string $constructionType, array $selectedScope, array $positions, array $decisions = [], array $technologies = []): array
    {
        if (! in_array($profile, ['construction', 'renovation', 'industrial'], true)
            || ! in_array($constructionType, ['new_construction', 'current_repair', 'capital_repair', 'reconstruction'], true)
            || $selectedScope === [] || count($selectedScope) > 50) {
            throw new InvalidArgumentException('scope_profile_invalid');
        }
        $entities = array_column($snapshot->entities, null, 'id');
        $evidence = array_column($snapshot->evidence, null, 'id');
        $facts = [];
        foreach ($snapshot->facts as $fact) {
            $proofCurrent = $fact->evidenceIds !== [];
            foreach ($fact->evidenceIds as $id) {
                $proofCurrent = $proofCurrent && isset($evidence[$id]) && $evidence[$id]->sourceVersion === $fact->sourceVersion
                    && ($fact->origin !== 'document' || $evidence[$id]->sourceType === 'document');
            }
            if ($fact->status === 'confirmed' && $fact->origin !== 'user_assumption' && $proofCurrent
                && ! in_array($fact->value, [null, false, '', '0', 'false', 'unknown', 'not_provided', []], true)) {
                $facts[$fact->entityId][$fact->type][] = $fact;
            }
        }
        $coveredWorks = [];
        foreach ($positions as $position) {
            if (($position['included'] ?? true) !== true || ($position['selected'] ?? true) !== true) {
                continue;
            }
            if (is_string($position['entity_id'] ?? null) && is_string($position['section'] ?? null) && is_string($position['work_key'] ?? null)) {
                $coveredWorks[$position['section']][$position['entity_id']][$position['work_key']] = $position['key'];
            }
        }
        $requirements = [];
        $covered = [];
        $notApplicable = [];
        foreach ($selectedScope as $section => $entityIds) {
            if (! is_string($section) || ! is_array($entityIds) || ! array_is_list($entityIds) || count($entityIds) > 10000) {
                throw new InvalidArgumentException('selected_entity_scope_invalid');
            }
            $required = self::REQUIREMENTS[$section] ?? null;
            if ($required === null || $entityIds === []) {
                $requirements[] = ['section' => $section, 'entity_id' => null, 'requirement' => 'scope_specification', 'reason' => 'scope_requirements_unknown'];

                continue;
            }
            if ($constructionType !== 'new_construction') {
                $required = [...$required, 'demolition', 'waste_removal', 'existing_structures_protection'];
            }
            foreach (array_unique($entityIds) as $entityId) {
                if (! is_string($entityId) || ! isset($entities[$entityId])) {
                    throw new InvalidArgumentException('selected_entity_outside_snapshot');
                }
                foreach ($required as $requirement) {
                    $disposition = (new RequirementApplicability)->notApplicable($snapshot, $decisions, $entityId, $requirement,
                        (string) ($technologies[$section][$entityId] ?? 'unspecified'));
                    if ($disposition !== null && in_array($requirement, ['demolition', 'waste_removal', 'existing_structures_protection'], true)) {
                        $notApplicable[] = ['section' => $section, 'entity_id' => $entityId, 'requirement' => $requirement, ...$disposition];

                        continue;
                    }
                    $documentRequirement = str_contains($requirement, 'test_program') || str_contains($requirement, 'specification');
                    $proof = array_values(array_filter($facts[$entityId][$requirement] ?? [], static fn (Fact $fact): bool => ! $documentRequirement
                        || (new DocumentRequirementAcceptance)->accepted($snapshot, $decisions, $fact, (string) ($technologies[$section][$entityId] ?? 'unspecified'))));
                    $work = $coveredWorks[$section][$entityId][$requirement] ?? null;
                    $satisfied = $documentRequirement ? $proof !== [] : is_string($work);
                    $row = ['section' => $section, 'entity_id' => $entityId, 'requirement' => $requirement,
                        'fact_ids' => array_column($proof, 'id'), 'position_keys' => $work === null ? [] : [$work]];
                    if ($satisfied) {
                        $covered[] = $row;
                    } else {
                        $requirements[] = [...$row, 'reason' => $documentRequirement ? 'document_requirement_missing' : 'work_scope_missing'];
                    }
                }
                if ($profile === 'industrial') {
                    foreach (['industrial_specification', 'technology_requirements', 'industry_work_checklist'] as $requirement) {
                        $proof = array_values(array_filter($facts[$entityId][$requirement] ?? [], static fn (Fact $fact): bool => (new DocumentRequirementAcceptance)->accepted($snapshot, $decisions, $fact, (string) ($technologies[$section][$entityId] ?? 'unspecified'))));
                        if ($proof === []) {
                            $requirements[] = ['section' => $section, 'entity_id' => $entityId, 'requirement' => $requirement, 'reason' => 'industrial_requirements_unverified'];
                        }
                    }
                    $requirements[] = ['section' => $section, 'entity_id' => $entityId, 'requirement' => 'industrial_profile_validation', 'reason' => 'industrial_reference_corpus_not_verified'];
                }
            }
        }

        return ['profile_id' => $profile, 'profile_version' => self::VERSION, 'construction_type' => $constructionType,
            'selected_scope' => $selectedScope, 'covered_requirements' => $covered, 'missing_requirements' => $requirements,
            'not_applicable_requirements' => $notApplicable,
            'scope_complete' => $requirements === [], 'accuracy_calibrated' => false];
    }
}
