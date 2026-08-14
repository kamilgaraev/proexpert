<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\AiRoleRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ArbitrationDecision;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ArbitrationInputBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ClaimSemanticMatcher;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\RunDocumentArbitration;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunClaim;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunFailure;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceWriter;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\InMemoryEvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\VisionProvider;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Preprocessing\ProjectiveTransformFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;

final class DocumentArbitrationTest extends TestCase
{
    #[Test]
    public function semantically_equal_synonyms_are_grouped_without_string_majority_vote(): void
    {
        $matcher = new ClaimSemanticMatcher;
        $groups = $matcher->groups([
            $this->claim('literal:1', 'стеновой материал', 'газобетонный блок', 'literal-evidence'),
            $this->claim('construction:1', 'материал стен', 'блок из ячеистого бетона', 'construction-evidence'),
            $this->claim('risk:1', 'несущая стена', 'газобетон', 'risk-evidence'),
        ]);

        self::assertCount(1, $groups);
        self::assertSame(['construction:1', 'literal:1', 'risk:1'], array_column($groups[0], 'id'));
    }

    #[Test]
    public function stronger_minority_evidence_can_be_accepted_and_unique_valid_note_is_preserved(): void
    {
        $claims = [
            $this->claim('literal:1', 'тип фундамента', 'ленточный', 'visual-1', false),
            $this->claim('construction:1', 'тип фундамента', 'ленточный', 'visual-2', false),
            $this->claim('risk:1', 'тип фундамента', 'условный', 'native-note', true),
            $this->claim('risk:2', 'узел примыкания', 'требует противопожарной разделки', 'native-detail', true),
        ];

        $decision = ArbitrationDecision::fromProviderIntent([
            'claim_id' => 'risk:1',
            'status' => 'accepted',
            'supporting_claim_ids' => ['risk:1'],
            'evidence_refs' => ['native-note'],
            'reason_code' => 'explicit_note_over_visual_similarity',
        ], $claims);
        $unique = ArbitrationDecision::fromProviderIntent([
            'claim_id' => 'risk:2',
            'status' => 'candidate',
            'supporting_claim_ids' => ['risk:2'],
            'evidence_refs' => ['native-detail'],
            'reason_code' => 'unique_professional_observation',
        ], $claims);

        self::assertSame('risk:1', $decision->claimId);
        self::assertSame('accepted', $decision->status);
        self::assertSame('candidate', $unique->status);
    }

    #[Test]
    public function identical_unsupported_guesses_cannot_be_confirmed(): void
    {
        $claims = [
            $this->claim('literal:1', 'тип кровли', 'металлочерепица', null, false),
            $this->claim('construction:1', 'тип кровли', 'металлочерепица', null, false),
            $this->claim('risk:1', 'тип кровли', 'металлочерепица', null, false),
        ];

        $this->expectException(InvalidArgumentException::class);
        ArbitrationDecision::fromProviderIntent([
            'claim_id' => 'literal:1',
            'status' => 'accepted',
            'supporting_claim_ids' => ['literal:1', 'construction:1', 'risk:1'],
            'evidence_refs' => [],
            'reason_code' => 'three_observers_agree',
        ], $claims);
    }

    #[Test]
    public function three_way_conflict_requires_a_concrete_question(): void
    {
        $claims = [
            $this->claim('literal:1', 'толщина стены', '300 мм', 'dimension-1'),
            $this->claim('construction:1', 'толщина стены', '375 мм', 'dimension-2'),
            $this->claim('risk:1', 'толщина стены', '400 мм', 'dimension-3'),
        ];

        $decision = ArbitrationDecision::fromProviderIntent([
            'claim_id' => 'literal:1',
            'status' => 'unresolved',
            'supporting_claim_ids' => ['literal:1', 'construction:1', 'risk:1'],
            'evidence_refs' => ['dimension-1', 'dimension-2', 'dimension-3'],
            'reason_code' => 'source_conflict',
            'question' => [
                'code' => 'wall_thickness_conflict',
                'subject' => 'Толщина наружной стены',
                'reason' => 'На листе указаны три разных значения толщины стены.',
                'impact' => 'От толщины зависит объём кладки и стоимость работ.',
                'recommendation' => 'Проверьте размер по рабочему чертежу стены.',
                'choices' => ['300 мм', '375 мм', '400 мм'],
                'source_locator' => ['page_number' => 4, 'evidence_refs' => ['dimension-1', 'dimension-2', 'dimension-3']],
            ],
        ], $claims);

        self::assertSame('wall_thickness_conflict', $decision->question['code']);
    }

    #[Test]
    public function stale_source_and_cross_tenant_locators_are_rejected_before_arbitration(): void
    {
        foreach ([
            ['organization_id' => 8, 'project_id' => 9, 'session_id' => 11, 'source_version' => $this->version()],
            ['organization_id' => 7, 'project_id' => 9, 'session_id' => 11, 'source_version' => 'sha256:'.str_repeat('b', 64)],
        ] as $locator) {
            try {
                ObservationClaim::fromObserverPayload('observer_literal', 0, [
                    'entityKey' => 'wall-1',
                    'factType' => 'material',
                    'value' => ['type' => 'string', 'data' => 'газобетон'],
                    'unit' => null,
                    'evidenceRef' => 'wall-note',
                ], ['wall-note' => $locator], 7, 9, 11, $this->version());
                self::fail('Invalid locator was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    #[Test]
    public function arbiter_receives_original_image_checks_minority_and_replays_exact_result(): void
    {
        $source = $this->sourceInput();
        $runs = new ArbitrationRunMemoryRepository;
        $provider = new ArbitrationRecordingProvider;
        $service = new RunDocumentArbitration($runs, $provider, new ArbitrationInputBuilder, 'openai/gpt-5.6-luna');
        $observers = $this->observerRuns();

        $first = $service->run($source, $observers);
        $second = $service->run($source, $observers);

        self::assertSame($first->payload, $second->payload);
        self::assertCount(1, $provider->inputs);
        self::assertSame($source->imageContent, $provider->inputs[0]->imageContent);
        self::assertTrue($provider->inputs[0]->auxiliaryMetadata['arbitration']['minority_evidence_required']);
        self::assertSame('risk:1', $first->payload['decisions'][0]['claim_id']);
    }

    #[Test]
    public function accepted_candidate_and_unresolved_claims_are_written_once_to_canonical_model(): void
    {
        $models = new InMemoryProjectModelRepository;
        $writer = new ProjectModelEvidenceWriter($models, new InMemoryEvidenceRepository);
        $claims = [
            $this->claim('literal:1', 'material', 'газобетон', 'material-note'),
            $this->claim('construction:1', 'finish', 'штукатурка', 'finish-region'),
            $this->claim('risk:1', 'wall_thickness', '375 мм', 'dimension-conflict'),
        ];
        $decisions = [
            ArbitrationDecision::fromProviderIntent([
                'claim_id' => 'literal:1', 'status' => 'accepted', 'supporting_claim_ids' => ['literal:1'],
                'evidence_refs' => ['material-note'], 'reason_code' => 'explicit_note',
            ], $claims),
            ArbitrationDecision::fromProviderIntent([
                'claim_id' => 'construction:1', 'status' => 'candidate', 'supporting_claim_ids' => ['construction:1'],
                'evidence_refs' => ['finish-region'], 'reason_code' => 'visual_candidate',
            ], $claims),
            ArbitrationDecision::fromProviderIntent([
                'claim_id' => 'risk:1', 'status' => 'unresolved', 'supporting_claim_ids' => ['risk:1'],
                'evidence_refs' => ['dimension-conflict'], 'reason_code' => 'source_conflict',
                'question' => [
                    'code' => 'wall_thickness_conflict', 'subject' => 'Толщина наружной стены',
                    'reason' => 'Размеры стены расходятся.',
                    'impact' => 'Изменяется объём кладки.', 'recommendation' => 'Проверьте рабочий разрез.',
                    'choices' => ['300 мм', '375 мм'],
                    'source_locator' => ['page_number' => 4, 'evidence_refs' => ['dimension-conflict']],
                ],
            ], $claims),
        ];

        $writer->writeArbitration($claims, $decisions, 13, 4);
        $writer->writeArbitration($claims, $decisions, 13, 4);

        self::assertEqualsCanonicalizing(['candidate', 'confirmed', 'unresolved'], array_values(array_unique(array_column($models->facts, 'status'))));
        self::assertCount(3, $models->facts);
        self::assertCount(3, $models->evidence);
    }

    private function claim(string $id, string $type, string $value, ?string $evidenceRef, bool $explicit = true): ObservationClaim
    {
        return new ObservationClaim(
            $id,
            str_starts_with($id, 'literal') ? 'observer_literal' : (str_starts_with($id, 'construction') ? 'observer_construction' : 'observer_risk'),
            'building-1',
            $type,
            ['type' => 'string', 'data' => $value],
            null,
            $evidenceRef,
            $explicit,
            7,
            9,
            11,
            $this->version(),
            ['page_number' => 4],
        );
    }

    private function version(): string
    {
        return 'sha256:'.str_repeat('a', 64);
    }

    private function sourceInput(): VisionDocumentInput
    {
        $image = imagecreatetruecolor(4, 4);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        ob_start();
        imagepng($image);
        $content = ob_get_clean();
        $content = is_string($content) ? $content : '';

        return new VisionDocumentInput(
            7, 9, 11, 13, 17, 4, 19, $this->version(),
            'sha256:'.hash('sha256', $content), 'image/png', $content, 'high',
            new AiOperationContext(
                '11111111-1111-5111-8111-111111111111',
                '22222222-2222-5222-8222-222222222222',
                7, 9, 11, 'understand_documents', 'vision', 1, 13, 17, 19,
            ),
            (new ProjectiveTransformFactory)->identity(),
            nativeReferences: ['pdf:page:4/text'],
        );
    }

    /** @return array<string,AiRoleRunResult> */
    private function observerRuns(): array
    {
        $runs = [];
        foreach (['observer_literal', 'observer_construction', 'observer_risk'] as $role) {
            $short = str_replace('observer_', '', $role);
            $runs[$role] = new AiRoleRunResult([
                'schema_version' => 1,
                'role' => $role,
                'source' => ['document_id' => 13, 'page_id' => 17, 'page_number' => 4, 'source_version' => $this->version()],
                'claims' => [[
                    'entityKey' => 'foundation-1',
                    'factType' => 'foundation_type',
                    'value' => ['type' => 'string', 'data' => $role === 'observer_risk' ? 'условный' : 'ленточный'],
                    'unit' => null,
                    'evidenceRef' => 'note-1',
                    'sourcePolygonOrNativeRef' => $role === 'observer_risk' ? 'pdf:page:4/text' : [[0.1, 0.1], [0.2, 0.2]],
                ]],
                'evidence' => [[
                    'key' => 'note-1',
                    'locator' => [
                        'page_id' => 17,
                        'page_number' => 4,
                        'processing_unit_id' => 19,
                        'source_version' => $this->version(),
                        'coordinate_space' => 'normalized_derivative_v1',
                        'explicit' => $short === 'risk',
                    ],
                ]],
            ], 'aaaaaaaa-aaaa-4aaa-8aaa-'.($short === 'literal' ? '000000000001' : ($short === 'construction' ? '000000000002' : '000000000003')));
        }

        return $runs;
    }
}

final class ArbitrationRecordingProvider implements VisionProvider
{
    /** @var list<VisionDocumentInput> */
    public array $inputs = [];

    public function analyze(VisionDocumentInput $input): VisionAnalysisData
    {
        $this->inputs[] = $input;
        ($input->onPhysicalAttemptReserved)('bbbbbbbb-bbbb-4bbb-8bbb-000000000001');

        return new VisionAnalysisData(
            'detail',
            [\App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionEvidenceData::fromArray([
                'key' => 'page',
                'locator' => ['page_id' => 17, 'page_number' => 4, 'processing_unit_id' => 19, 'source_version' => 'sha256:'.str_repeat('a', 64), 'coordinate_space' => 'normalized_derivative_v1'],
            ])],
            [], [], ['scale_missing'], 'timeweb', 'openai/gpt-5.6-luna', 'openai/gpt-5.6-luna',
            'timeweb-gpt-5.6-luna-2026-08-13', 'measured', 10, 10, [], null, [], [[
                'claim_id' => 'risk:1',
                'status' => 'accepted',
                'supporting_claim_ids' => ['risk:1'],
                'evidence_refs' => ['risk:note-1'],
                'reason_code' => 'explicit_note_over_visual_similarity',
            ]],
        );
    }
}

final class ArbitrationRunMemoryRepository implements AiRoleRunRepository
{
    private ?AiRoleRunResult $result = null;

    public function claim(AiRoleRunInput $input, string $ownerUuid): AiRoleRunClaim
    {
        return $this->result === null
            ? new AiRoleRunClaim(1, 'owned', $ownerUuid)
            : new AiRoleRunClaim(1, 'replay', result: $this->result);
    }

    public function startPhysicalAttempt(int $runId, string $ownerUuid, string $physicalAttemptId): void {}

    public function complete(int $runId, string $ownerUuid, AiRoleRunResult $result): void
    {
        $this->result = $result;
    }

    public function fail(int $runId, string $ownerUuid, AiRoleRunFailure $failure): void {}

    public function loadCurrent(AiRoleRunInput $input): ?AiRoleRunClaim
    {
        return null;
    }
}
