<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationCoverageEvidenceProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use PHPUnit\Framework\TestCase;

final class EvaluationCoverageEvidenceProjectorTest extends TestCase
{
    public function test_coverage_preserves_typed_original_coordinates_instead_of_guessing_legacy_region_units(): void
    {
        $version = 'sha256:'.str_repeat('a', 64);
        $entity = new Entity('wall:1', 1, 2, 3, $version, 'wall', 'wall:1');
        $source = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactSourceLocator([
            'kind' => 'polygon', 'coordinate_space' => 'normalized_source_v1', 'value' => [[0.1, 0.2], [0.4, 0.2], [0.4, 0.6], [0.1, 0.6]]]);
        $proof = new Evidence('proof:1', 1, 2, 3, $version, 'document:42', 'document', 7,
            ['x' => 10, 'y' => 20, 'width' => 30, 'height' => 40], 'element:legacy', factSource: $source);
        $fact = new Fact('fact:1', 1, 2, 3, $version, $entity->id, 'material', 'concrete', null, 0, 'document', 'confirmed', [$proof->id]);
        $result = (new EvaluationCoverageEvidenceProjector)->project(['covered_requirements' => [['entity_id' => $entity->id, 'fact_ids' => [$fact->id]]]],
            new ProjectModelSnapshot([$entity], [$fact], [$proof], []));
        $locator = $result['covered_requirements'][0]['sources'][0]['locators'][0];
        self::assertSame('normalized_source_v1', $locator['coordinate_space'] ?? null);
        self::assertEqualsWithDelta($source->region(), $locator['region'], 0.000001);
        self::assertNull($locator['native_reference']);
    }

    public function test_projects_exact_sources_for_linked_document_entity_without_promoting_scope(): void
    {
        $version = 'sha256:'.str_repeat('a', 64);
        $target = new Entity('equipment:1', 1, 2, 3, $version, 'equipment', 'equipment:1', ['name' => 'Насосная станция']);
        $documentEntity = new Entity('equipment:document', 1, 2, 3, $version, 'equipment', 'equipment:document');
        $proof = new Evidence('proof:pdf', 1, 2, 3, $version, 'artifact:page', 'document', 7,
            ['x' => 1, 'y' => 2, 'width' => 3, 'height' => 4], null, 'extractor', 'document:42');
        $native = new Evidence('proof:cell', 1, 2, 3, $version, 'document:42', 'document', null, null, 'xlsx:sheet:Лист1!C2');
        $fact = new Fact('fact:specification', 1, 2, 3, $version, $documentEntity->id, 'equipment_specification', 'Документ проверен', null, 0, 'document', 'confirmed', [$proof->id, $native->id]);
        $row = ['section' => 'equipment', 'entity_id' => $target->id, 'requirement' => 'equipment_specification', 'fact_ids' => [$fact->id], 'position_keys' => []];
        $coverage = ['covered_requirements' => [$row], 'missing_requirements' => [], 'not_applicable_requirements' => [], 'scope_complete' => false, 'accuracy_calibrated' => false];
        $result = (new EvaluationCoverageEvidenceProjector)->project($coverage, new ProjectModelSnapshot([$target, $documentEntity], [$fact], [$proof, $native], []));
        $projected = $result['covered_requirements'][0];
        self::assertSame('Насосная станция', $projected['entity_name']);
        self::assertSame($row['fact_ids'], $projected['fact_ids']);
        $pdfLocator = array_values(array_filter($projected['sources'][0]['locators'], static fn (array $locator): bool => $locator['page'] === 7))[0];
        $nativeLocator = array_values(array_filter($projected['sources'][0]['locators'], static fn (array $locator): bool => $locator['native_reference'] !== null))[0];
        self::assertSame(42, $pdfLocator['document_id']);
        self::assertSame(7, $pdfLocator['page']);
        self::assertSame($proof->region, $pdfLocator['region']);
        self::assertSame($native->nativeReference, $nativeLocator['native_reference']);
        self::assertArrayNotHasKey('value', $projected['sources'][0]);
        self::assertFalse($result['scope_complete']);
        self::assertFalse($result['accuracy_calibrated']);
    }

    public function test_does_not_turn_stale_conflicting_or_unaddressable_proof_into_a_source_link(): void
    {
        $version = 'sha256:'.str_repeat('a', 64);
        $entity = new Entity('equipment:1', 1, 2, 3, $version, 'equipment', 'equipment:1');
        $proofs = [new Evidence('proof:stale', 1, 2, 3, 'sha256:'.str_repeat('b', 64), 'document:42', 'document', 1),
            new Evidence('proof:conflicting', 1, 2, 3, $version, 'document:42', 'document', 1, null, null, null, 'document:43'),
            new Evidence('proof:region', 1, 2, 3, $version, 'document:42', 'document', null, ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1]),
            new Evidence('proof:user', 1, 2, 3, $version, 'user:1', 'user_input', null, null, 'manual:requirement')];
        $fact = new Fact('fact:1', 1, 2, 3, $version, $entity->id, 'equipment_specification', 'Да', null, 0, 'document', 'confirmed', array_column($proofs, 'id'));
        $candidate = new Fact('fact:candidate', 1, 2, 3, $version, $entity->id, 'equipment_specification', 'Да', null, 0, 'document', 'candidate', [$proofs[0]->id]);
        $row = ['entity_id' => $entity->id, 'fact_ids' => [$fact->id, $candidate->id, 'fact:missing']];
        $result = (new EvaluationCoverageEvidenceProjector)->project(['covered_requirements' => [$row]], new ProjectModelSnapshot([$entity], [$fact, $candidate], $proofs, []));
        self::assertCount(1, $result['covered_requirements'][0]['sources']);
        self::assertSame([], $result['covered_requirements'][0]['sources'][0]['locators']);
        self::assertNull($result['covered_requirements'][0]['entity_name']);
    }

    public function test_keeps_user_confirmation_distinct_from_document_evidence(): void
    {
        $version = 'sha256:'.str_repeat('a', 64);
        $entity = new Entity('wall:1', 1, 2, 3, $version, 'wall', 'wall:1', ['identity' => ['label' => 'Стена кухни']]);
        $proof = new Evidence('proof:user', 1, 2, 3, $version, 'user:1', 'user_input', null, null, 'manual:requirement');
        $fact = new Fact('fact:na', 1, 2, 3, $version, $entity->id, 'evaluation_requirement.demolition', ['disposition' => 'not_applicable'], null, 0, 'user_input', 'confirmed', [$proof->id]);
        $row = ['entity_id' => $entity->id, 'fact_ids' => [$fact->id], 'reason' => 'Новая стена, демонтаж не нужен.', 'decision_id' => 'decision:1'];
        $result = (new EvaluationCoverageEvidenceProjector)->project(['not_applicable_requirements' => [$row]], new ProjectModelSnapshot([$entity], [$fact], [$proof], []));
        self::assertSame('Стена кухни', $result['not_applicable_requirements'][0]['entity_name']);
        self::assertSame('user_input', $result['not_applicable_requirements'][0]['sources'][0]['origin']);
        self::assertSame([], $result['not_applicable_requirements'][0]['sources'][0]['locators']);
        self::assertSame($row['reason'], $result['not_applicable_requirements'][0]['reason']);
        self::assertSame($row['decision_id'], $result['not_applicable_requirements'][0]['decision_id']);
    }
}
