<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\CurrentRoomIdentitySources;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\ManualRoomIdentityFactEligibility;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\RoomIdentityFactEligibility;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceNode;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceProducer;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ManualRoomIdentityFactEligibilityTest extends TestCase
{
    public function test_manual_source_projection_requires_opt_in_and_keeps_the_full_bound_choice_hash_without_a_document(): void
    {
        [$snapshot, $fact, $entity, $decision, $node] = $this->fixture();
        $models = $this->createMock(ProjectModelRepository::class);
        $models->expects(self::exactly(2))->method('snapshot')->with(1, 2, 3, 10001)->willReturn($snapshot);
        $models->expects(self::once())->method('decisionsForSelectedFacts')->with(1, 2, 3, [$fact->id])->willReturn([$decision]);
        $evidence = $this->createMock(EvidenceRepository::class);
        $evidence->expects(self::once())->method('activeNodesForUpdate')->with(1, 2, 3, [1])->willReturn([$node]);
        $session = new EstimateGenerationSession;
        $session->setRawAttributes(['id' => 3, 'organization_id' => 1, 'project_id' => 2]);
        $reader = new CurrentRoomIdentitySources($models, $evidence, new RoomIdentityFactEligibility);
        self::assertSame([], $reader->rows($session));
        $rows = $reader->rows($session, true);
        self::assertCount(1, $rows);
        self::assertSame('Комната отделки', $rows[0]['name']);
        self::assertSame($entity->id, $rows[0]['entity_id']);
        self::assertSame('user_input', $rows[0]['source_kind']);
        self::assertSame(hash('sha256', CanonicalPipelineJson::encode(['value' => $fact->value])), $rows[0]['source_value_hash']);
        self::assertFalse($rows[0]['dimensions_confirmed']);
        foreach (['document_id', 'document_name', 'page', 'region', 'native_reference'] as $key) {
            self::assertNull($rows[0]['evidence'][$key]);
        }
    }

    public function test_accepts_exact_user_choice_without_turning_a_name_into_document_or_dimensions(): void
    {
        [$snapshot, $fact, $entity, $decision, $node] = $this->fixture();
        self::assertSame($node, (new ManualRoomIdentityFactEligibility)->proof($snapshot, $fact, $entity, $decision, [$node->id => $node]));
        self::assertSame(EvidenceSourceType::UserInput, $node->sourceType);
        self::assertArrayNotHasKey('document_id', $node->locator);
        self::assertNull($fact->unit);
    }

    public function test_rejects_missing_decision_stale_or_invalidated_evidence_and_another_entity(): void
    {
        [$snapshot, $fact, $entity, $decision, $node] = $this->fixture();
        $guard = new ManualRoomIdentityFactEligibility;
        self::assertNull($guard->proof($snapshot, $fact, $entity, null, [$node->id => $node]));
        self::assertNull($guard->proof($snapshot, $fact, $entity, $decision, []));
        $invalidated = new EvidenceNode($node->id, $node->data, $node->fingerprint, new DateTimeImmutable, 'removed', 1);
        self::assertNull($guard->proof($snapshot, $fact, $entity, $decision, [$node->id => $invalidated]));
        $other = new Entity('room:other', 1, 2, 3, $entity->sourceVersion, 'room', 'room:other');
        self::assertNull($guard->proof($snapshot, $fact, $other, $decision, [$node->id => $node]));
        [$stale, $fact, $entity, $decision, $node] = $this->fixture(staleProof: true);
        self::assertNull($guard->proof($stale, $fact, $entity, $decision, [$node->id => $node]));
        [$snapshot, $fact, $entity, $decision, $node] = $this->fixture(wrongNodeHash: true);
        self::assertNull($guard->proof($snapshot, $fact, $entity, $decision, [$node->id => $node]));
    }

    public function test_rejects_names_without_the_bound_manual_identity_contract(): void
    {
        foreach ([['entity_id' => 'room:another'], ['entity_type' => 'wall'], ['identity_confirmed' => false],
            ['answer_kind' => 'evaluation_answer'], ['name' => ''], ['name' => str_repeat('я', 161)]] as $change) {
            [$snapshot, $fact, $entity, $decision, $node] = $this->fixture($change);
            self::assertNull((new ManualRoomIdentityFactEligibility)->proof($snapshot, $fact, $entity, $decision, [$node->id => $node]));
        }
    }

    private function fixture(array $change = [], bool $staleProof = false, bool $wrongNodeHash = false): array
    {
        $version = 'sha256:'.str_repeat('a', 64);
        $entity = new Entity('room:manual', 1, 2, 3, $version, 'room', 'room:manual');
        $value = array_replace(['answer_kind' => 'entity_identity', 'entity_id' => $entity->id, 'entity_type' => 'room',
            'name' => 'Комната отделки', 'identity_confirmed' => true, 'decision_id' => 'decision:manual', 'reason' => 'Подтверждено пользователем.'], $change);
        $hash = hash('sha256', CanonicalPipelineJson::encode($value));
        $proofVersion = $staleProof ? 'sha256:'.str_repeat('c', 64) : $version;
        $proof = new Evidence('evidence:1', 1, 2, 3, $proofVersion, 'input:3', 'user_input', null, null,
            'input_payload.manual_facts', EvidenceProducer::UserInputNormalizer->value, 'input:3', $hash);
        $node = new EvidenceNode(1, new EvidenceData(1, 2, 3, EvidenceType::SourceFact, EvidenceSourceType::UserInput,
            'input:3', $proofVersion, ['source_key' => 'source:'.hash('sha256', 'manual-room'), 'native_reference' => 'input_payload.manual_facts'],
            ['fact_key' => 'evaluation_choice', 'fact_value' => $wrongNodeHash ? str_repeat('0', 64) : $hash], 0, EvidenceProducer::UserInputNormalizer->value, $version), str_repeat('b', 64));
        $fact = new Fact('fact:manual', 1, 2, 3, $version, $entity->id, 'entity_identity', $value, null, 0, 'user_input', 'confirmed', [$proof->id]);
        $decision = new Decision('decision:manual', 1, 2, 3, $version, 'fact', $fact->id, $fact->id, 'user', '1', $value['reason'], 1, [$proof->id]);

        return [new ProjectModelSnapshot([$entity], [$fact], [$proof], []), $fact, $entity, $decision, $node];
    }
}
