<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\ComposerSourceClaimValidationService as Claims;
use PHPUnit\Framework\TestCase;

final class ComposerSourceClaimValidationTest extends TestCase
{
    public function test_requirement_proposal_uses_existing_generic_source_without_numeric_or_equipment_authority(): void
    {
        [$entity, $fact, $descriptor] = $this->requirementSource();
        self::assertSame($descriptor, Claims::requirementProposal($entity, $fact));
        $source = ['descriptor' => $descriptor, 'evidence' => [['evidence_id' => 'evidence:1']]];
        $details = Claims::requirementDetails($source);
        self::assertSame('1.2', $details['threshold']);
        self::assertSame('m', $details['unit']);
        self::assertNull($details['equipment_identity']);
        self::assertNull($details['conditions']);
        self::assertSame('unresolved', $details['source_correspondence']);
        self::assertSame('unresolved', $details['source_authority']);
        self::assertSame('unresolved', $details['engineering_applicability']);
        self::assertFalse($details['numeric_inputs_verified']);
        self::assertArrayNotHasKey('confirmation_level', $details);
        self::assertArrayNotHasKey('descriptor', $details);
        self::assertArrayNotHasKey('field_locators', $details);
        self::assertArrayNotHasKey('observed', $details);
        foreach ([['threshold' => ['data' => 1.2]], ['threshold' => ['data' => '0']], ['threshold' => ['data' => '1e2']],
            ['comparator' => ['data' => 'lte']], ['unit' => ['data' => 'm2']], ['dimension' => 'area']] as $changes) {
            [$invalidEntity, $invalidFact] = $this->requirementSource($changes);
            self::assertNull(Claims::requirementProposal($invalidEntity, $invalidFact));
        }
    }

    public function test_requirement_ref_ignores_answer_and_cas_but_changes_with_source_work_and_scope(): void
    {
        [, , $descriptor] = $this->requirementSource();
        $association = ['descriptor' => $descriptor, 'source_fact_id' => 'fact:source'];
        $work = ['schema_version' => 1, 'binding' => ['work_key' => 'custom_install', 'work_section' => 'heating'],
            'binding_hash' => str_repeat('a', 64), 'dependencies' => [], 'policy' => [], 'question' => []];
        $ref = Claims::requirementReference([10, 20, 40], $work, $association);
        self::assertSame($ref, Claims::requirementReference([10, 20, 40], [...$work, 'state_version' => 9,
            'snapshot_token' => 'new', 'answer' => ['fact_id' => 'fact:new'], 'decision_id' => 'decision:new'], $association));
        self::assertNotSame($ref, Claims::requirementReference([10, 20, 41], $work, $association));
        self::assertNotSame($ref, Claims::requirementReference([10, 20, 40], [...$work,
            'binding' => [...$work['binding'], 'work_key' => 'replacement']], $association));
        [, , $changed] = $this->requirementSource(['threshold' => ['data' => '1.3']]);
        self::assertNotSame($ref, Claims::requirementReference([10, 20, 40], $work, [...$association, 'descriptor' => $changed]));
    }

    public function test_requirement_sources_do_not_read_artifacts_for_unattached_or_foreign_context(): void
    {
        [$source, $fact] = $this->requirementSource();
        $target = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity('entity:target', 10, 20, 40,
            $source->sourceVersion, 'equipment', 'entity:target');
        $snapshot = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot([$source, $target], [$fact], [], []);
        $evidence = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository::class);
        $evidence->expects(self::never())->method('node');
        $reader = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitContentReader::class);
        $reader->expects(self::never())->method('read');
        $units = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitStore::class);
        $claims = new Claims($evidence, $units, contentReader: $reader);
        $binding = ['entity_id' => $target->id, 'work_key' => 'custom_install', 'work_section' => 'heating'];
        $work = ['binding' => $binding, 'binding_hash' => hash('sha256',
            \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($binding))];
        self::assertSame([], $claims->requirementSources([10, 20, 40], $snapshot, $work, []));
        self::assertSame([], $claims->requirementSources([10, 20, 41], $snapshot, $work, []));
        self::assertSame([], $claims->requirementSources([10, 20, 40], $snapshot, [...$work, 'binding_hash' => str_repeat('0', 64)], []));
        self::assertSame([], $claims->requirementSources([10, 20, 40], $snapshot, $work,
            [['document_id' => 18, 'source_version' => $source->sourceVersion, 'checksum_sha256' => str_repeat('a', 64), 'status' => 'ready']]));
    }

    public function test_requirement_descriptor_rejects_missing_field_provenance_future_shape_and_hash_drift(): void
    {
        [$entity, $fact, $descriptor] = $this->requirementSource();
        $missing = $descriptor;
        unset($missing['field_locators']['threshold']);
        $missing['proposal_hash'] = hash('sha256', \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DerivedQuantityIdentity::canonicalJson(
            array_intersect_key($missing, array_flip(['schema_version', 'payload', 'field_locators', 'source_pins']))));
        foreach ([$missing, [...$descriptor, 'schema_version' => 2], [...$descriptor, 'verified' => true],
            [...$descriptor, 'proposal_hash' => str_repeat('0', 64)],
            [...$descriptor, 'source_pins' => [...$descriptor['source_pins'], 'document_id' => '17']]] as $invalid) {
            $invalidEntity = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity($entity->id, 10, 20, 40,
                $entity->sourceVersion, 'wall', $entity->stableKey, [...$entity->attributes, 'properties' => ['document_semantic_proposal' => $invalid]]);
            self::assertNull(Claims::requirementProposal($invalidEntity, $fact));
        }
    }

    private function requirementSource(array $changes = []): array
    {
        $version = 'sha256:'.str_repeat('a', 64);
        $polygon = [[0.1, 0.2], [0.8, 0.2], [0.8, 0.7], [0.1, 0.7]];
        $field = static fn (?string $data): array => ['data' => $data, 'evidenceRef' => $data === null ? null : 'source',
            'sourcePolygonOrNativeRef' => $data === null ? null : $polygon];
        $payload = ['schemaVersion' => 1, 'kind' => 'source_requirement', 'parameter' => $field('Высота ограждения'),
            'dimension' => 'length', 'comparator' => $field('gte'), 'threshold' => $field('1.2'), 'unit' => $field('m'),
            'appliesTo' => ['subject' => $field('Указанное в источнике ограждение'), 'equipmentIdentity' => null],
            'documentEdition' => $field(null), 'clause' => $field(null), 'conditions' => null];
        $payload = array_replace_recursive($payload, $changes);
        $locators = [];
        foreach (['parameter', 'comparator', 'threshold', 'unit', 'appliesTo.subject'] as $path) {
            $locators[$path] = ['organization_id' => 10, 'project_id' => 20, 'session_id' => 40,
                'document_id' => 17, 'page_id' => 3, 'processing_unit_id' => 2, 'source_version' => $version,
                'fact_source' => ['kind' => 'polygon', 'coordinate_space' => 'normalized_source_v1', 'value' => $polygon]];
        }
        ksort($locators, SORT_STRING);
        $base = ['schema_version' => 1, 'payload' => $payload, 'field_locators' => $locators,
            'source_pins' => ['organization_id' => 10, 'project_id' => 20, 'session_id' => 40, 'document_id' => 17,
                'page_number' => 1, 'processing_unit_id' => 2, 'source_version' => $version]];
        $descriptor = [...$base, 'status' => 'proposed', 'proposal_hash' => hash('sha256',
            \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DerivedQuantityIdentity::canonicalJson($base))];
        $entity = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity('entity:source', 10, 20, 40,
            $version, 'wall', 'entity:source', ['semantic_type' => 'wall', 'identity' => ['source_entity_key' => 'wall:source'],
                'properties' => ['document_semantic_proposal' => $descriptor]]);
        $fact = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact('fact:source', 10, 20, 40, $version,
            $entity->id, 'technology_requirements', 'Фрагмент требования', null, 0.0, 'document', 'confirmed', ['evidence:1']);

        return [$entity, $fact, $descriptor];
    }

    public function test_closed_candidate_does_not_accept_model_verdicts_numeric_values_or_self_evidence(): void
    {
        $candidate = $this->candidate();
        self::assertTrue(Claims::isCandidate($candidate));
        foreach ([
            [...$candidate, 'passed' => true], [...$candidate, 'unit' => 'шт'],
            [...$candidate, 'value' => ['type' => 'number', 'data' => '3']],
            [...$candidate, 'value' => ['type' => 'string', 'data' => str_repeat('я', 501)]],
            [...$candidate, 'fact_type' => 'equipment_count'],
            [...$candidate, 'document_fact_ids' => ['fact:answer']],
            [...$candidate, 'document_fact_ids' => ['fact:document', 'fact:second']],
        ] as $invalid) {
            self::assertFalse(Claims::isCandidate($invalid));
        }
    }

    public function test_receipt_duplicates_collapse_but_conflicting_interpretations_are_unresolved(): void
    {
        $candidate = $this->candidate();
        self::assertSame([['run_id' => 1, 'candidate' => $candidate]], Claims::foldReceipts([
            ['run_id' => 2, 'candidates' => [$candidate]], ['run_id' => 1, 'candidates' => [$candidate]],
        ]));
        self::assertSame([], Claims::foldReceipts([
            ['run_id' => 1, 'candidates' => [$candidate]],
            ['run_id' => 2, 'candidates' => [[...$candidate, 'entity_id' => 'entity:other']]],
        ]));
        self::assertSame([], Claims::foldReceipts([['run_id' => 1, 'candidates' => [$candidate, $candidate]]]));
    }

    public function test_receipt_projection_and_logical_claim_bounds_fail_closed(): void
    {
        $receipts = array_fill(0, Claims::MAX_PROJECTIONS, ['run_id' => 1, 'candidates' => [$this->candidate()]]);
        self::assertCount(1, Claims::foldReceipts($receipts));
        $receipts[] = $receipts[0];
        self::assertSame([], Claims::foldReceipts($receipts));
        $receipts = [];
        for ($i = 0; $i < 9; $i++) {
            $receipts[] = ['run_id' => $i + 1, 'candidates' => [[...$this->candidate(), 'source_answer_fact_id' => 'fact:answer'.$i]]];
        }
        self::assertSame([], Claims::foldReceipts($receipts));
    }

    public function test_exact_document_correspondence_does_not_turn_a_different_word_or_paraphrase_into_support(): void
    {
        $original = 'Монтаж стендов';
        $witness = ['value_hash' => hash('sha256', json_encode($original, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))];
        $candidate = [...$this->candidate(), 'value' => ['type' => 'string', 'data' => $original]];
        self::assertTrue(Claims::literalValueCorresponds($candidate, $original, $witness));
        self::assertFalse(Claims::literalValueCorresponds([...$candidate, 'value' => ['type' => 'string', 'data' => 'Монтаж стен']], $original, $witness));
        self::assertFalse(Claims::literalValueCorresponds([...$candidate, 'value' => ['type' => 'string', 'data' => 'Установка стендов']], $original, $witness));
        self::assertFalse(Claims::literalValueCorresponds($candidate, $original, ['value_hash' => str_repeat('0', 64)]));
    }

    private function candidate(): array
    {
        return ['source_answer_fact_id' => 'fact:answer', 'entity_id' => 'entity:equipment',
            'fact_type' => 'equipment_specification', 'value' => ['type' => 'string', 'data' => 'Насос по спецификации.'],
            'unit' => null, 'document_fact_ids' => ['fact:document']];
    }
}
