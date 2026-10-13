<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\DocumentRequirementAcceptance;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\ScopeCompletenessEvaluator;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceProducer;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use PHPUnit\Framework\TestCase;

final class DocumentRequirementApplicabilityTest extends TestCase
{
    public function test_a_document_of_another_source_entity_requires_a_precise_human_application_to_the_target(): void
    {
        $targetVersion = 'sha256:'.str_repeat('a', 64);
        $sourceVersion = 'sha256:'.str_repeat('b', 64);
        $target = new Entity('equipment:target', 1, 2, 3, $targetVersion, 'equipment', 'equipment:target', ['properties' => ['semantic_type' => 'equipment']]);
        $source = new Entity('equipment:source', 1, 2, 3, $sourceVersion, 'equipment', 'equipment:source', ['properties' => ['semantic_type' => 'equipment']]);
        $documentProof = new Evidence('evidence:document', 1, 2, 3, $sourceVersion, 'document:2', 'document', 1);
        $document = new Fact('fact:spec', 1, 2, 3, $sourceVersion, $source->id, 'equipment_specification',
            'Спецификация насоса P1: модель и монтажные требования', null, 0, 'document', 'confirmed', [$documentProof->id]);
        $decision = new Decision('decision:apply', 1, 2, 3, $targetVersion, 'fact', 'fact:approval', 'fact:approval', 'user', '42',
            'Проверена применимость спецификации P1 к выбранному насосу', 1, ['evidence:approval']);
        $value = ['disposition' => 'document_verified', 'scope_version' => ScopeCompletenessEvaluator::VERSION,
            'technology_key' => 'unspecified', 'section' => 'equipment', 'document_fact_id' => $document->id,
            'document_source_version' => $sourceVersion, 'document_source_ref' => 'document:2',
            'document_binding_version' => 'document-applicability:v1', 'document_entity_id' => $source->id,
            'document_fact_version' => 1, 'document_value_hash' => hash('sha256', CanonicalPipelineJson::encode(['type' => $document->type, 'value' => $document->value, 'unit' => $document->unit])),
            'document_applicability_confirmed' => true, 'decision_id' => $decision->id, 'reason' => $decision->reason];
        $approval = new Fact('fact:approval', 1, 2, 3, $targetVersion, $target->id, 'evaluation_requirement.equipment_specification',
            $value, null, 0, 'user_input', 'confirmed', ['evidence:approval']);
        $proof = new Evidence('evidence:approval', 1, 2, 3, $targetVersion, 'input:3', 'user_input',
            nativeReference: 'input_payload.evaluation_choices', producerName: EvidenceProducer::UserInputNormalizer->value,
            sourceReference: 'input:3', choiceHash: hash('sha256', CanonicalPipelineJson::encode($value)));
        $snapshot = new ProjectModelSnapshot([$target, $source], [$document, $approval], [$documentProof, $proof], []);
        $accept = new DocumentRequirementAcceptance;
        self::assertTrue($accept->accepted($snapshot, [$decision], $document, 'unspecified', $target->id, 'equipment'));
        self::assertFalse($accept->accepted($snapshot, [], $document, 'unspecified', $target->id, 'equipment'));
        self::assertFalse($accept->accepted($snapshot, [$decision], $document, 'unspecified', 'equipment:other', 'equipment'));
        self::assertFalse($accept->accepted($snapshot, [$decision], $document, 'unspecified', $target->id, 'heating'));
        self::assertFalse($accept->accepted($snapshot, [$decision], $document, 'changed-technology', $target->id, 'equipment'));
        foreach ([['version' => 2], ['value' => 'Другая спецификация'], ['status' => 'candidate']] as $change) {
            $changed = new Fact($document->id, $change['organization'] ?? 1, 2, 3, $sourceVersion, $source->id, $document->type,
                $change['value'] ?? $document->value, null, 0, 'document', $change['status'] ?? 'confirmed', $document->evidenceIds, $change['version'] ?? 1);
            $stale = new ProjectModelSnapshot([$target, $source], [$changed, $approval], [$documentProof, $proof], []);
            self::assertFalse($accept->accepted($stale, [$decision], $changed, 'unspecified', $target->id, 'equipment'));
        }
        $coverage = (new ScopeCompletenessEvaluator)->evaluate($snapshot, 'construction', 'new_construction', ['equipment' => [$target->id]], [], [$decision]);
        self::assertContains('equipment_specification', array_column($coverage['covered_requirements'], 'requirement'));
        self::assertNotContains('equipment_specification', array_column($coverage['missing_requirements'], 'requirement'));
        self::assertFalse($coverage['scope_complete']);
    }
}
