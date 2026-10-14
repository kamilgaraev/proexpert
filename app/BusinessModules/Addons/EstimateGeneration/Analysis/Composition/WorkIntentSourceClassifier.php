<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;
use InvalidArgumentException;

/** Applicability of an asserted source, separate from quantity arithmetic and price selection. */
final class WorkIntentSourceClassifier
{
    private const WORKS = [
        'rough.floor' => 'finishing', 'rough.walls' => 'finishing', 'finish.floor' => 'finishing',
        'finish.paint' => 'finishing', 'finish.baseboard' => 'finishing', 'floor_screed' => 'finishing',
        'roof.area' => 'roofing', 'roof.flat_area' => 'roofing', 'roof_underlay' => 'roofing',
        'roof_covering' => 'roofing', 'roof_waterproofing' => 'roofing', 'roof_drainage' => 'roofing',
        'roof_fasteners' => 'roofing', 'roof_insulation' => 'roofing',
        'foundation.concrete' => 'structures', 'foundation.formwork' => 'structures', 'foundation.rebar' => 'structures',
        'foundation.waterproofing' => 'structures', 'walls.internal' => 'structures', 'walls.external_volume' => 'structures',
        'facade.area' => 'structures', 'slabs.concrete' => 'structures', 'slabs.formwork' => 'structures', 'slabs.rebar' => 'structures',
        'earth.plan' => 'earthworks', 'earth.trench' => 'earthworks', 'earth.backfill' => 'earthworks', 'earth.export' => 'earthworks',
        'heating.unit' => 'heating', 'heating.pipe' => 'heating', 'heating.radiators' => 'heating',
        'equipment.purchase' => 'equipment', 'equipment.install' => 'equipment', 'pipelines.install' => 'technological_pipelines',
        'plumbing.pipe' => 'water_supply', 'sewerage.pipe' => 'sewerage', 'electrical.main_cable' => 'electricity', 'ventilation.duct' => 'ventilation',
    ];

    /** @return array{entity_id:string,work_section:string,basis_fact_ids:list<string>} */
    public function binding(array $intent, array $facts): array
    {
        $registered = self::WORKS[$intent['work_key']] ?? null;
        $section = $registered ?? $intent['work_section'] ?? null;
        if (! is_string($section) || ($registered !== null && isset($intent['work_section']) && $intent['work_section'] !== $registered)) {
            throw new InvalidArgumentException('estimate_composer_work_domain_unsupported');
        }
        $byId = array_column($facts, null, 'id');
        $relevant = [];
        $unconfirmed = false;
        $definitionUnverified = false;
        foreach ($intent['source_fact_ids'] as $id) {
            $fact = $byId[$id] ?? null;
            if (! is_array($fact)) {
                continue;
            }
            // An unknown model work has no server definition. A categorical
            // document fact cannot verify it merely by matching a claimed section.
            // A user's description can still provide a bounded preliminary outline.
            if ($registered === null && ! (($fact['type'] ?? null) === 'evaluation_answer'
                && ($fact['origin'] ?? null) === 'user_input'
                && ((($fact['context_kind'] ?? null) === 'initial_description'
                    && is_array($fact['declared_work_sections'] ?? null))
                    || (is_array($fact['value'] ?? null) && is_string($fact['value']['description'] ?? null)
                        && trim($fact['value']['description']) !== '')))) {
                $definitionUnverified = true;

                continue;
            }
            $type = $fact['type'] ?? null;
            $entityType = $fact['entity_type'] ?? null;
            $domains = is_string($type) && is_string($entityType) ? $this->domains($type, $entityType) : [];
            if ($type === 'work_scope' && ($fact['origin'] ?? null) === 'user_input'
                && is_array($fact['value'] ?? null) && is_string($fact['value']['section'] ?? null)) {
                $domains = [$fact['value']['section']];
            }
            if ($type === 'evaluation_answer' && ($fact['origin'] ?? null) === 'user_input' && is_string($entityType)) {
                $domains = $this->domains('entity_identity', $entityType);
                if (($fact['context_kind'] ?? null) === 'initial_description'
                    && is_array($fact['declared_work_sections'] ?? null)) {
                    $domains = $fact['declared_work_sections'];
                }
            }
            if (! in_array($section, $domains, true)) {
                continue;
            }
            if (($fact['status'] ?? null) !== 'confirmed' || ! in_array($fact['origin'] ?? null, ['document', 'user_input', 'user_assumption'], true)
                || ! is_array($fact['evidence_ids'] ?? null) || $fact['evidence_ids'] === []) {
                $unconfirmed = true;

                continue;
            }
            $entityId = $fact['entity_id'] ?? null;
            if (! is_string($entityId) || $entityId === '') {
                continue;
            }
            if (isset($intent['entity_id']) && $intent['entity_id'] !== $entityId
                && ! in_array($intent['entity_id'], $fact['related_entity_ids'] ?? [], true)) {
                continue;
            }
            $relevant[$id] = $intent['entity_id'] ?? $entityId;
        }
        if ($relevant === []) {
            if ($definitionUnverified) {
                throw new InvalidArgumentException('estimate_composer_work_definition_unverified');
            }
            throw new InvalidArgumentException($unconfirmed ? 'estimate_composer_work_context_unconfirmed' : 'estimate_composer_work_source_unrelated');
        }
        $entities = array_values(array_unique(array_values($relevant)));
        if (count($entities) !== 1) {
            throw new InvalidArgumentException('estimate_composer_work_entity_ambiguous');
        }

        return ['entity_id' => $entities[0], 'work_section' => $section, 'basis_fact_ids' => array_keys($relevant)];
    }

    private function domains(string $type, string $entityType): array
    {
        if (($domain = FactVocabulary::documentDomain($type)) !== null) {
            return $domain !== 'industrial' && FactVocabulary::documentSupportsEntity($type, $entityType) ? [$domain] : [];
        }
        // A measured asset or route is a quantity basis, not a request to purchase or install it.
        if (in_array($type, FactVocabulary::engineeringParameters(), true)) {
            return [];
        }
        if (in_array($type, ['roof_type', 'roof_slope_degrees', 'roof_geometry', 'roof_fastener_specification'], true)) {
            return in_array($entityType, ['roof', 'roof_facet', 'roof_opening'], true) ? ['roofing'] : [];
        }
        $parameter = FactVocabulary::parameter($type);
        if ($type !== 'entity_identity' && ! FactVocabulary::supports($entityType, $parameter)) {
            return [];
        }

        return match ($entityType) {
            'roof', 'roof_facet', 'roof_opening' => ['roofing'],
            'room', 'wall', 'opening' => ['finishing', 'structures'],
            'site' => ['earthworks'],
            'equipment' => ['equipment'], 'radiator' => ['heating'], 'water_pipe' => ['water_supply'], 'sewer_pipe' => ['sewerage'],
            'technological_pipeline' => ['technological_pipelines'], 'electrical_circuit' => ['electricity'], 'ventilation_duct' => ['ventilation'],
            default => [],
        };
    }
}
