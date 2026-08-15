<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\AiRoleRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunClaim;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunFailure;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Observers\ObserverInputBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Observers\ObserverProfile;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Observers\RunIndependentObservers;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\VisionProvider;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Preprocessing\ProjectiveTransformFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IndependentObserversTest extends TestCase
{
    #[Test]
    public function profiles_build_three_isolated_contexts_without_peer_outputs(): void
    {
        $builder = new ObserverInputBuilder;
        $source = $this->sourceInput([
            'observer_outputs' => ['forbidden'],
            'peer_result' => ['forbidden'],
            'capabilities' => ['page_render' => 'available'],
        ]);
        $inputs = array_map(
            static fn (ObserverProfile $profile): VisionDocumentInput => $builder->build($source, $profile, static function (): void {}),
            ObserverProfile::cases(),
        );

        self::assertCount(3, $inputs);
        self::assertCount(3, array_unique(array_map(
            static fn (VisionDocumentInput $input): string => $input->operationContext->correlationId,
            $inputs,
        )));
        self::assertCount(3, array_unique(array_column(array_column(
            array_map(static fn (VisionDocumentInput $input): array => $input->auxiliaryMetadata, $inputs),
            'observer',
        ), 'prompt_sha256')));
        self::assertCount(3, array_unique(array_column(array_column(
            array_map(static fn (VisionDocumentInput $input): array => $input->auxiliaryMetadata, $inputs),
            'observer',
        ), 'composition')));
        self::assertCount(3, array_unique(array_map(
            static fn (VisionDocumentInput $input): string => $input->derivativeHash,
            $inputs,
        )));
        foreach ($inputs as $input) {
            $encoded = json_encode($input->auxiliaryMetadata, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('observer_outputs', $encoded);
            self::assertStringNotContainsString('peer_result', $encoded);
            self::assertSame('image/png', $input->contentType);
            self::assertNotSame('', $input->imageContent);
        }
    }

    #[Test]
    public function explicit_retry_lineages_share_logical_observer_identity_but_not_physical_attempt_identity(): void
    {
        $builder = new ObserverInputBuilder;
        $first = $builder->build(
            $this->sourceInput(processingLineageId: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'),
            ObserverProfile::Literal,
            static function (): void {},
        );
        $second = $builder->build(
            $this->sourceInput(processingLineageId: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'),
            ObserverProfile::Literal,
            static function (): void {},
        );

        self::assertSame($first->operationContext->correlationId, $second->operationContext->correlationId);
        self::assertNotSame($first->operationContext->attemptId, $second->operationContext->attemptId);
        self::assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $first->operationContext->processingLineageId);
        self::assertSame('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $second->operationContext->processingLineageId);
    }

    #[Test]
    public function runner_persists_three_independent_role_results_with_one_pinned_model(): void
    {
        $repository = new InMemoryAiRoleRunRepository;
        $provider = new RecordingObserverVisionProvider($this->analysis());
        $runner = new RunIndependentObservers(
            $repository,
            $provider,
            new ObserverInputBuilder,
            'openai/gpt-5.6-luna',
        );

        $results = $runner->run($this->sourceInput(), [
            ObserverProfile::Literal,
            ObserverProfile::Construction,
            ObserverProfile::Risk,
        ]);

        self::assertSame([
            'observer_literal',
            'observer_construction',
            'observer_risk',
        ], array_keys($results));
        self::assertCount(3, array_unique(array_map(
            static fn (AiRoleRunInput $input): string => $input->promptContractVersion,
            $repository->inputs,
        )));
        self::assertSame(['openai/gpt-5.6-luna'], array_values(array_unique(array_map(
            static fn (AiRoleRunInput $input): string => $input->model,
            $repository->inputs,
        ))));
        self::assertCount(3, array_unique($repository->physicalAttemptIds));
        self::assertCount(3, $provider->inputs);
        foreach ($repository->completed as $result) {
            self::assertSame('Необычный узел примыкания сохранён для проверки арбитром', $result->payload['observation']['raw_facts'][0]['observation']);
            self::assertLessThanOrEqual(64, count($result->payload['claims']));
            self::assertLessThanOrEqual(128, count($result->payload['evidence']));
        }
        $replayed = $runner->run($this->sourceInput(), [
            ObserverProfile::Literal,
            ObserverProfile::Construction,
            ObserverProfile::Risk,
        ]);
        self::assertCount(3, $provider->inputs);
        self::assertSame(
            array_map(static fn (AiRoleRunResult $result): array => $result->payload, $results),
            array_map(static fn (AiRoleRunResult $result): array => $result->payload, $replayed),
        );
    }

    #[Test]
    public function completed_observer_response_reuses_across_exact_lineage_fence_but_source_mismatch_executes_again(): void
    {
        $repository = new InMemoryAiRoleRunRepository;
        $provider = new RecordingObserverVisionProvider($this->analysis());
        $runner = new RunIndependentObservers(
            $repository,
            $provider,
            new ObserverInputBuilder,
            'openai/gpt-5.6-luna',
        );

        $runner->run(
            $this->sourceInput(processingLineageId: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'),
            [ObserverProfile::Literal],
        );
        $runner->run(
            $this->sourceInput(processingLineageId: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'),
            [ObserverProfile::Literal],
        );

        self::assertCount(1, $provider->inputs);
        self::assertCount(1, $repository->completed);
        self::assertCount(1, $repository->physicalAttemptIds);

        $runner->run(
            $this->sourceInput(
                processingLineageId: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                sourceVersion: 'sha256:'.str_repeat('b', 64),
            ),
            [ObserverProfile::Literal],
        );

        self::assertCount(2, $provider->inputs);
        self::assertCount(2, $repository->completed);
        self::assertCount(2, $repository->physicalAttemptIds);
    }

    #[Test]
    public function production_shaped_two_response_flow_resumes_without_new_provider_calls(): void
    {
        $repository = new InMemoryAiRoleRunRepository;
        $provider = new RecordingObserverVisionProvider($this->analysis());
        $runner = new RunIndependentObservers(
            $repository,
            $provider,
            new ObserverInputBuilder,
            'openai/gpt-5.6-luna',
        );
        $profiles = [ObserverProfile::Construction, ObserverProfile::Risk];

        $results = $runner->run($this->sourceInput(), $profiles);
        $replayed = $runner->run($this->sourceInput(), $profiles);

        self::assertSame(['observer_construction', 'observer_risk'], array_keys($results));
        self::assertSame(
            array_map(static fn (AiRoleRunResult $result): array => $result->payload, $results),
            array_map(static fn (AiRoleRunResult $result): array => $result->payload, $replayed),
        );
        self::assertCount(2, $provider->inputs);
        self::assertCount(2, $repository->physicalAttemptIds);
    }

    #[Test]
    public function production_shaped_pages_preserve_foundation_openings_materials_and_visual_observations(): void
    {
        $fixture = json_decode((string) file_get_contents(
            dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/analysis/independent-observers-pages-4-17-18.json',
        ), true, 32, JSON_THROW_ON_ERROR);

        self::assertSame([4, 17, 18], array_column($fixture['pages'], 'page_number'));
        $encoded = json_encode($fixture, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('условный фундамент', mb_strtolower($encoded));
        self::assertStringContainsString('таблица проёмов', mb_strtolower($encoded));
        self::assertStringContainsString('газобетон', mb_strtolower($encoded));
        self::assertStringContainsString('визуально', mb_strtolower($encoded));
        self::assertStringNotContainsString('needs clarification', mb_strtolower($encoded));
        self::assertStringNotContainsString('нужно уточнить', mb_strtolower($encoded));
    }

    #[Test]
    public function production_shaped_empty_risk_claims_remain_a_valid_preserved_observation(): void
    {
        $repository = new InMemoryAiRoleRunRepository;
        $provider = new RecordingObserverVisionProvider($this->analysis());
        $runner = new RunIndependentObservers(
            $repository,
            $provider,
            new ObserverInputBuilder,
            'openai/gpt-5.6-luna',
        );

        $results = $runner->run($this->sourceInput(), [ObserverProfile::Risk]);

        self::assertSame([], $results['observer_risk']->payload['claims']);
        self::assertCount(1, $results['observer_risk']->payload['observation']['quarantined_items']);
        self::assertCount(1, $provider->inputs);

        $replayed = $runner->run($this->sourceInput(), [ObserverProfile::Risk]);

        self::assertSame([], $replayed['observer_risk']->payload['claims']);
        self::assertCount(1, $provider->inputs);
    }

    /** @param array<string, mixed> $auxiliaryMetadata */
    private function sourceInput(
        array $auxiliaryMetadata = [],
        ?string $processingLineageId = null,
        string $sourceVersion = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    ): VisionDocumentInput {
        $image = imagecreatetruecolor(4, 4);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        ob_start();
        imagepng($image);
        $content = ob_get_clean();
        $content = is_string($content) ? $content : '';

        return new VisionDocumentInput(
            organizationId: 7,
            projectId: 9,
            sessionId: 11,
            documentId: 13,
            pageId: 17,
            pageNumber: 4,
            processingUnitId: 19,
            sourceVersion: $sourceVersion,
            derivativeHash: 'sha256:'.hash('sha256', $content),
            contentType: 'image/png',
            imageContent: $content,
            imageDetail: 'high',
            operationContext: new AiOperationContext(
                '11111111-1111-5111-8111-111111111111',
                '22222222-2222-5222-8222-222222222222',
                7,
                9,
                11,
                'understand_documents',
                'vision',
                1,
                13,
                17,
                19,
                $processingLineageId,
            ),
            sourceTransform: (new ProjectiveTransformFactory)->identity(),
            nativeReferences: ['pdf:page:4/text', 'pdf:page:4/vector'],
            auxiliaryText: 'Фундамент условный. Материалы уточняются по листам 17–18.',
            auxiliaryMetadata: $auxiliaryMetadata,
        );
    }

    private function analysis(): VisionAnalysisData
    {
        return VisionAnalysisData::fromProviderArray([
            'schema_version' => 3,
            'sheet_type' => 'detail',
            'evidence' => [[
                'key' => 'detail-note',
                'locator' => [
                    'page_id' => 17,
                    'page_number' => 4,
                    'processing_unit_id' => 19,
                    'source_version' => 'sha256:'.str_repeat('a', 64),
                    'coordinate_space' => 'normalized_derivative_v1',
                ],
            ]],
            'elements' => [],
            'scale_candidates' => [],
            'warnings' => ['scale_missing'],
            'visual_attributes' => [],
            'project_sheet_analysis' => [
                'contractVersion' => 'sheet-analysis:v3',
                'role' => 'unknown',
                'facts' => [],
            ],
        ], 'timeweb', 'openai/gpt-5.6-luna', 'openai/gpt-5.6-luna', 'observer-test:v1', 'measured', 100, 20, 64);
    }
}

final class RecordingObserverVisionProvider implements VisionProvider
{
    /** @var list<VisionDocumentInput> */
    public array $inputs = [];

    public function __construct(private readonly VisionAnalysisData $analysis) {}

    public function analyze(VisionDocumentInput $input): VisionAnalysisData
    {
        $this->inputs[] = $input;
        ($input->onPhysicalAttemptReserved)('aaaaaaaa-aaaa-4aaa-8aaa-'.str_pad((string) count($this->inputs), 12, '0', STR_PAD_LEFT));

        return new VisionAnalysisData(
            $this->analysis->sheetType,
            $this->analysis->evidence,
            $this->analysis->elements,
            $this->analysis->scaleCandidates,
            $this->analysis->warnings,
            $this->analysis->provider,
            $this->analysis->requestedModel,
            $this->analysis->reportedModel,
            $this->analysis->modelVersion,
            $this->analysis->usageStatus,
            $this->analysis->inputTokens,
            $this->analysis->outputTokens,
            $this->analysis->visualAttributes,
            $this->analysis->projectSheetAnalysis,
            [[
                'section' => 'project_sheet_analysis',
                'index' => 0,
                'reason' => 'unknown_professional_observation',
            ]],
            [[
                'entityKey' => 'unusual-junction',
                'factType' => 'unregistered_professional_type',
                'observation' => 'Необычный узел примыкания сохранён для проверки арбитром',
                'evidenceRef' => 'detail-note',
            ]],
        );
    }
}

final class InMemoryAiRoleRunRepository implements AiRoleRunRepository
{
    /** @var list<AiRoleRunInput> */
    public array $inputs = [];

    /** @var list<string> */
    public array $physicalAttemptIds = [];

    /** @var list<AiRoleRunResult> */
    public array $completed = [];

    /** @var array<int, string> */
    private array $identityByRun = [];

    /** @var array<string, AiRoleRunResult> */
    private array $completedByIdentity = [];

    public function claim(AiRoleRunInput $input, string $ownerUuid): AiRoleRunClaim
    {
        $this->inputs[] = $input;
        $identity = $input->identityFingerprint();
        if (isset($this->completedByIdentity[$identity])) {
            $runId = array_search($identity, $this->identityByRun, true);

            return new AiRoleRunClaim((int) $runId, 'replay', result: $this->completedByIdentity[$identity]);
        }
        $runId = count($this->identityByRun) + 1;
        $this->identityByRun[$runId] = $identity;

        return new AiRoleRunClaim($runId, 'owned', $ownerUuid);
    }

    public function startPhysicalAttempt(int $runId, string $ownerUuid, string $physicalAttemptId): void
    {
        $this->physicalAttemptIds[] = $physicalAttemptId;
    }

    public function complete(int $runId, string $ownerUuid, AiRoleRunResult $result): void
    {
        $this->completed[] = $result;
        $this->completedByIdentity[$this->identityByRun[$runId]] = $result;
    }

    public function fail(int $runId, string $ownerUuid, AiRoleRunFailure $failure): void {}

    public function loadCurrent(AiRoleRunInput $input): ?AiRoleRunClaim
    {
        return null;
    }

    public function completedFingerprints(int $organizationId, int $projectId, int $sessionId, array $roles, array $sourceVersions): array
    {
        return [];
    }
}
