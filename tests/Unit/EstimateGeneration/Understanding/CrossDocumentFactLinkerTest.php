<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Understanding;

use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\CrossDocumentFactArbitrator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\CrossDocumentFactLinker;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\TargetedConflictResolver;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CrossDocumentFactLinkerTest extends TestCase
{
    #[Test]
    #[DataProvider('deterministicScenarios')]
    public function deterministic_engineering_keys_link_the_four_required_document_scenarios_without_ai(
        string $leftRole,
        string $rightRole,
        string $identityName,
        mixed $identityValue,
        string $strategy,
        string $entityType,
    ): void {
        $arbitrator = new RecordingCrossDocumentFactArbitrator;
        [$entities, $facts, $evidence] = $this->pair(
            $leftRole,
            $rightRole,
            $identityName,
            $identityValue,
            $entityType,
        );

        $result = $this->linker($arbitrator)->link($entities, $facts, $evidence);

        self::assertCount(1, $result->links);
        self::assertSame($strategy, $result->links[0]['strategy']);
        self::assertSame(['evidence:1'], $result->links[0]['evidence']['left']);
        self::assertSame(['evidence:2'], $result->links[0]['evidence']['right']);
        self::assertSame('linked', $result->links[0]['status']);
        self::assertSame(0, $result->providerCalls);
        self::assertSame([], $arbitrator->payloads);
    }

    public static function deterministicScenarios(): array
    {
        return [
            'room plan to explication' => ['plan', 'room_schedule', 'room_number', '101', 'room_number', 'room'],
            'plan to section by axes' => ['plan', 'section', 'axes', ['A', '1'], 'axes', 'dimension'],
            'equipment plan to specification' => ['equipment_plan', 'equipment_specification', 'position', 'E-12', 'equipment_position', 'equipment'],
            'facade to finish schedule' => ['facade', 'finish_schedule', 'facade_zone', 'F-1', 'facade_material', 'material'],
        ];
    }

    #[Test]
    public function native_and_stable_cross_document_keys_are_resolved_before_semantic_arbitration(): void
    {
        foreach ([['native_id', 'cad:handle:44', 'native_id'], ['cross_document_key', 'room:101', 'stable_key']] as [$attribute, $value, $strategy]) {
            $entities = [
                new Entity('entity:left', 1, 2, 3, $this->sourceVersion('a'), 'room', 'entity:left', [
                    'document_role' => 'plan', 'link_side' => 'left', $attribute => $value,
                ]),
                new Entity('entity:right', 1, 2, 3, $this->sourceVersion('a'), 'room', 'entity:right', [
                    'document_role' => 'schedule', 'link_side' => 'right', $attribute => $value,
                ]),
            ];
            $facts = [
                $this->fact('fact:left', 'entity:left', 'area', 18.4, 'evidence:1'),
                $this->fact('fact:right', 'entity:right', 'area', 18.4, 'evidence:2'),
            ];
            $evidence = [$this->evidence('evidence:1', 'artifact:plan', 2), $this->evidence('evidence:2', 'artifact:schedule', 4)];

            $result = $this->linker()->link($entities, $facts, $evidence);

            self::assertSame($strategy, $result->links[0]['strategy']);
            self::assertSame(0, $result->providerCalls);
        }
    }

    #[Test]
    public function incompatible_values_create_an_evidenced_conflict_and_a_human_question_instead_of_overwrite(): void
    {
        [$entities, $facts, $evidence] = $this->pair('plan', 'room_schedule', 'room_number', '101', 'room');
        $facts[1] = $this->fact('fact:right', 'entity:right', 'area', 19.1, 'evidence:2');

        $result = $this->linker()->link($entities, $facts, $evidence);

        self::assertSame([], $result->links);
        self::assertCount(1, $result->conflicts);
        self::assertSame(['fact:left', 'fact:right'], array_map(static fn (Fact $fact): string => $fact->id, $result->conflicts[0]->facts));
        self::assertSame(['evidence:1', 'evidence:2'], $result->conflicts[0]->evidenceIds);
        self::assertCount(1, $result->questions);
        self::assertStringContainsString('Какое значение использовать', $result->questions[0]['text']);
        self::assertCount(2, $result->questions[0]['options']);
        self::assertSame(['evidence:1'], $result->questions[0]['options'][0]['evidence_ids']);
        self::assertStringContainsString('документ', $result->questions[0]['options'][0]['label']);
    }

    #[Test]
    public function ambiguous_candidates_use_one_minimal_scoped_ai_payload_and_can_only_create_a_suggestion(): void
    {
        $arbitrator = new RecordingCrossDocumentFactArbitrator('fact:right-2');
        [$entities, $facts, $evidence] = $this->pair('plan', 'room_schedule', 'room_number', '101', 'room');
        $entities[] = $this->entity('entity:right-2', 'room', 'room_schedule', 'room_number', '101');
        $facts[] = $this->fact('fact:right-2', 'entity:right-2', 'area', 18.4, 'evidence:3');
        $evidence[] = $this->evidence('evidence:3', 'artifact:explication-2', 8);

        $result = $this->linker($arbitrator)->link($entities, $facts, $evidence);

        self::assertSame(1, $result->providerCalls);
        self::assertCount(1, $arbitrator->payloads);
        self::assertCount(1, $result->links);
        self::assertSame('suggested', $result->links[0]['status']);
        self::assertSame('ai_arbitration', $result->links[0]['strategy']);
        self::assertSame(['operation_identity', 'strategy', 'match_key', 'source_version', 'subject', 'candidates'], array_keys($arbitrator->payloads[0]));
        self::assertSame(['fact', 'evidence'], array_keys($arbitrator->payloads[0]['subject']));
        self::assertSame(['id', 'type', 'value', 'unit', 'confidence', 'origin', 'status'], array_keys($arbitrator->payloads[0]['subject']['fact']));
        self::assertSame(['id', 'source_artifact_id', 'source_type', 'source_version', 'page', 'region', 'native_reference'], array_keys($arbitrator->payloads[0]['subject']['evidence'][0]));
        self::assertSame($this->sourceVersion('a'), $arbitrator->payloads[0]['source_version']);
        self::assertSame($this->sourceVersion('b'), $arbitrator->payloads[0]['subject']['evidence'][0]['source_version']);
        self::assertArrayNotHasKey('attributes', $arbitrator->payloads[0]);
        self::assertArrayNotHasKey('documents', $arbitrator->payloads[0]);
    }

    #[Test]
    public function insufficient_evidence_and_no_match_never_call_the_provider(): void
    {
        [$entities, $facts, $evidence] = $this->pair('plan', 'room_schedule', 'room_number', '101', 'room');
        $facts[0] = $this->fact('fact:left', 'entity:left', 'area', 18.4, null, 'candidate');
        $arbitrator = new RecordingCrossDocumentFactArbitrator;

        $result = $this->linker($arbitrator)->link($entities, $facts, $evidence);

        self::assertSame([], $result->links);
        self::assertSame(0, $result->providerCalls);
        self::assertNotSame([], $result->limitations);

        $noMatch = $this->linker($arbitrator)->link([$entities[0]], [$facts[0]], [$evidence[0]]);
        self::assertSame([], $noMatch->links);
        self::assertSame(0, $noMatch->providerCalls);
    }

    #[Test]
    public function stale_source_and_cross_scope_records_are_never_linked(): void
    {
        [$entities, $facts, $evidence] = $this->pair('plan', 'room_schedule', 'room_number', '101', 'room');
        $facts[1] = $this->fact('fact:right', 'entity:right', 'area', 18.4, null, 'invalidated');

        self::assertSame([], $this->linker()->link($entities, $facts, $evidence)->links);

        $facts[1] = new Fact(
            'fact:right', 1, 2, 3, $this->sourceVersion('a'), 'entity:right', 'area', 18.4, 'm2',
            0.95, 'ai_technology_recommendation', 'candidate', ['evidence:2'],
        );
        self::assertSame([], $this->linker()->link($entities, $facts, $evidence)->links);

        $facts[1] = new Fact(
            'fact:right', 99, 2, 3, $this->sourceVersion('a'), 'entity:right', 'area', 18.4, 'm2',
            0.95, 'document', 'confirmed', ['evidence:2'],
        );
        $this->expectException(InvalidArgumentException::class);
        $this->linker()->link($entities, $facts, $evidence);
    }

    #[Test]
    public function permutation_and_duplicate_replay_produce_one_stable_link(): void
    {
        [$entities, $facts, $evidence] = $this->pair('equipment_plan', 'equipment_specification', 'position', 'E-12', 'equipment');
        $linker = $this->linker();

        $first = $linker->link($entities, $facts, $evidence);
        $second = $linker->link(array_reverse($entities), array_reverse($facts), array_reverse($evidence));

        self::assertSame($first->links, $second->links);
        self::assertCount(1, $first->links);
        self::assertSame($first->links[0]['id'], $second->links[0]['id']);
        self::assertSame($first->links[0]['operation_identity'], $second->links[0]['operation_identity']);
    }

    #[Test]
    public function candidate_fanout_is_bounded_before_any_provider_call(): void
    {
        [$entities, $facts, $evidence] = $this->pair('plan', 'room_schedule', 'room_number', '101', 'room');
        for ($index = 2; $index <= 4; $index++) {
            $entities[] = $this->entity('entity:right-'.$index, 'room', 'room_schedule', 'room_number', '101');
            $facts[] = $this->fact('fact:right-'.$index, 'entity:right-'.$index, 'area', 18.4, 'evidence:'.($index + 1));
            $evidence[] = $this->evidence('evidence:'.($index + 1), 'artifact:explication-'.$index, $index);
        }
        $arbitrator = new RecordingCrossDocumentFactArbitrator;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('candidate limit');
        $this->linker($arbitrator, 2)->link($entities, $facts, $evidence);
    }

    #[Test]
    public function evidence_payload_is_bounded_before_any_provider_call(): void
    {
        [$entities, $facts] = $this->pair('plan', 'room_schedule', 'room_number', '101', 'room');
        $evidenceIds = [];
        $evidence = [];
        for ($index = 1; $index <= 22; $index++) {
            $evidenceIds[] = 'evidence:'.$index;
            $evidence[] = $this->evidence('evidence:'.$index, 'artifact:document-'.$index, $index);
        }
        $facts[0] = new Fact(
            'fact:left', 1, 2, 3, $this->sourceVersion('a'), 'entity:left', 'area', 18.4, 'm2',
            0.95, 'document', 'confirmed', array_slice($evidenceIds, 0, 21),
        );
        $facts[1] = $this->fact('fact:right', 'entity:right', 'area', 18.4, 'evidence:22');
        $arbitrator = new RecordingCrossDocumentFactArbitrator;

        try {
            $this->linker($arbitrator)->link($entities, $facts, $evidence);
            self::fail('Unbounded evidence payload was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('evidence limit', $exception->getMessage());
            self::assertSame([], $arbitrator->payloads);
        }
    }

    private function linker(?CrossDocumentFactArbitrator $arbitrator = null, int $limit = 20): CrossDocumentFactLinker
    {
        $translator = static function (string $key, array $replace): string {
            $messages = [
                'estimate_generation.project_model.conflict_question' => 'В документах указаны разные значения для «:fact». Какое значение использовать?',
                'estimate_generation.project_model.conflict_option' => ':value — источник: :source',
                'estimate_generation.project_model.insufficient_evidence' => 'Недостаточно данных для однозначной связи между документами.',
                'estimate_generation.project_model.source_reference' => 'документ :document, стр. :page',
                'estimate_generation.project_model.source_without_page' => 'документ :document',
                'estimate_generation.project_model.fact_type.area' => 'площадь',
            ];

            return strtr($messages[$key], array_combine(
                array_map(static fn (string $name): string => ':'.$name, array_keys($replace)),
                array_map(static fn (mixed $value): string => (string) $value, array_values($replace)),
            ));
        };

        return new CrossDocumentFactLinker(
            new TargetedConflictResolver($translator),
            $arbitrator,
            $limit,
        );
    }

    private function pair(string $leftRole, string $rightRole, string $identityName, mixed $identityValue, string $type): array
    {
        return [
            [
                $this->entity('entity:left', $type, $leftRole, $identityName, $identityValue),
                $this->entity('entity:right', $type, $rightRole, $identityName, $identityValue),
            ],
            [
                $this->fact('fact:left', 'entity:left', 'area', 18.4, 'evidence:1'),
                $this->fact('fact:right', 'entity:right', 'area', 18.4, 'evidence:2'),
            ],
            [
                $this->evidence('evidence:1', 'artifact:plan', 2),
                $this->evidence('evidence:2', 'artifact:schedule', 4),
            ],
        ];
    }

    private function entity(string $id, string $type, string $role, string $identityName, mixed $identityValue): Entity
    {
        return new Entity(
            $id,
            1,
            2,
            3,
            $this->sourceVersion('a'),
            $type,
            $id,
            ['document_role' => $role, $identityName => $identityValue],
        );
    }

    private function fact(
        string $id,
        string $entityId,
        string $type,
        mixed $value,
        ?string $evidenceId,
        string $status = 'confirmed',
    ): Fact {
        return new Fact(
            $id,
            1,
            2,
            3,
            $this->sourceVersion('a'),
            $entityId,
            $type,
            $value,
            'm2',
            0.95,
            'document',
            $status,
            $evidenceId === null ? [] : [$evidenceId],
        );
    }

    private function evidence(string $id, string $artifact, int $page): Evidence
    {
        return new Evidence(
            $id,
            1,
            2,
            3,
            $this->sourceVersion('b'),
            $artifact,
            'pdf',
            $page,
            ['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.4],
            'pdf:text-span:'.$page,
        );
    }

    private function sourceVersion(string $character): string
    {
        return 'sha256:'.str_repeat($character, 64);
    }
}

final class RecordingCrossDocumentFactArbitrator implements CrossDocumentFactArbitrator
{
    public array $payloads = [];

    public function __construct(private readonly ?string $selectedFactId = null) {}

    public function arbitrate(string $operationIdentity, array $payload, array $scope): array
    {
        $this->payloads[] = $payload;

        return $this->selectedFactId === null
            ? ['status' => 'unresolved', 'selected_fact_id' => null, 'reason' => 'insufficient_evidence']
            : ['status' => 'suggested', 'selected_fact_id' => $this->selectedFactId, 'reason' => 'matching_context'];
    }
}
