<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\EloquentAiRoleRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Observers\ObserverInputBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Observers\ObserverProfile;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Observers\RunIndependentObservers;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentPage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\VisionProvider;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionEvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\PhysicalAttempt\EloquentVisionPhysicalAttemptStore;
use App\BusinessModules\Addons\EstimateGeneration\Vision\PhysicalAttempt\VisionPhysicalAttemptCollision;
use App\BusinessModules\Addons\EstimateGeneration\Vision\PhysicalAttempt\VisionPhysicalAttemptStore;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Preprocessing\ProjectiveTransformFactory;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

#[Group('postgres-contract')]
final class VisionPhysicalAttemptRecoveryPostgresTest extends TestCase
{
    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function response_received_is_replayed_without_a_second_physical_attempt_and_collision_stays_closed(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        self::assertSame('1', getenv('RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT'));

        DB::beginTransaction();
        try {
            $organization = Organization::factory()->create();
            $project = Project::factory()->for($organization)->create();
            $session = EstimateGenerationSession::query()->create([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'user_id' => User::factory()->create()->id,
                'status' => 'draft',
                'processing_stage' => 'draft',
                'processing_progress' => 0,
                'input_payload' => [],
            ]);
            $context = new AiOperationContext(
                correlationId: '5ca5cfc7-22d3-53dc-98d1-b4c41e827c45',
                attemptId: '2759032d-3953-58cf-9506-940985021e1e',
                organizationId: (int) $organization->id,
                projectId: (int) $project->id,
                sessionId: (int) $session->id,
                stage: 'understand_documents',
                operation: 'vision',
                attemptOrdinal: 1,
            );
            $store = new EloquentVisionPhysicalAttemptStore(DB::connection());
            $now = new DateTimeImmutable('2026-08-15T10:00:00+00:00');
            $fingerprint = hash('sha256', 'production-shaped-request');
            $owner = 'c3132350-6ae6-4b30-a163-95a42e13fdfc';
            $response = [
                'model' => 'openai/gpt-5.6-luna',
                'choices' => [['finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
            ];

            $owned = $store->claim($context, $fingerprint, $owner, $now, $now->modify('+120 seconds'));
            self::assertSame($owner, $owned->ownerToken);
            $store->markWireStarted($context->attemptId, $fingerprint, $owner, $now, $now->modify('+120 seconds'));
            $store->storeResponse(
                $context->attemptId,
                $fingerprint,
                $owner,
                $response,
                'succeeded',
                200,
                420,
                'openai/gpt-5.6-luna',
                ['currency' => 'RUB'],
            );

            $replay = $store->claim(
                $context,
                $fingerprint,
                '4aa67a51-7677-43c0-ab29-99439ffbb0f3',
                $now->modify('+1 second'),
                $now->modify('+121 seconds'),
            );

            self::assertFalse($replay->reservedNow);
            self::assertSame('response_received', $replay->state);
            self::assertEquals($response, $replay->responsePayload);
            self::assertSame(1, DB::table('estimate_generation_vision_physical_attempts')->count());

            $this->expectException(VisionPhysicalAttemptCollision::class);
            $store->claim(
                $context,
                hash('sha256', 'conflicting-request'),
                'cbca8fc6-9325-4586-a542-a37503eb577a',
                $now->modify('+2 seconds'),
                $now->modify('+122 seconds'),
            );
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function production_observer_path_recovers_a_durable_response_without_a_second_wire_call(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        DB::beginTransaction();
        try {
            $organization = Organization::factory()->create();
            $project = Project::factory()->for($organization)->create();
            $user = User::factory()->create();
            $sourceVersion = 'sha256:'.str_repeat('e', 64);
            $session = EstimateGenerationSession::query()->create([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'user_id' => $user->id,
                'status' => 'draft',
                'processing_stage' => 'draft',
                'processing_progress' => 0,
                'input_payload' => [],
            ]);
            $document = EstimateGenerationDocument::query()->create([
                'session_id' => $session->id,
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'user_id' => $user->id,
                'filename' => 'durable-recovery.pdf',
                'mime_type' => 'application/pdf',
                'source_version' => $sourceVersion,
            ]);
            $unit = EstimateGenerationProcessingUnit::query()->create([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'session_id' => $session->id,
                'document_id' => $document->id,
                'unit_type' => 'pdf_page',
                'unit_index' => 1,
                'source_version' => $sourceVersion,
                'status' => 'pending',
                'locator' => [
                    'source_kind' => 'pdf',
                    'source_version' => $sourceVersion,
                    'coordinate_space' => 'pdf_page_pixels',
                    'artifact_path' => 'org-'.$organization->id.'/estimate-generation/tests/recovery.png',
                    'artifact_source_version' => $sourceVersion,
                    'artifact_version_id' => 'durable-recovery-page-1',
                    'artifact_bytes' => 1,
                    'artifact_sha256' => $sourceVersion,
                    'content_type' => 'image/png',
                ],
                'metadata' => ['processing_attempt_id' => '33333333-3333-5333-8333-333333333333'],
            ]);
            $page = EstimateGenerationDocumentPage::query()->create([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'session_id' => $session->id,
                'document_id' => $document->id,
                'processing_unit_id' => $unit->id,
                'page_number' => 1,
                'source_version' => $sourceVersion,
                'status' => 'processing',
                'language_codes' => [],
                'normalized_payload' => [],
                'quality_flags' => [],
            ]);
            $image = imagecreatetruecolor(4, 4);
            imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
            ob_start();
            imagepng($image);
            $content = ob_get_clean();
            $content = is_string($content) ? $content : '';
            $source = new VisionDocumentInput(
                (int) $organization->id,
                (int) $project->id,
                (int) $session->id,
                (int) $document->id,
                (int) $page->id,
                1,
                (int) $unit->id,
                $sourceVersion,
                'sha256:'.hash('sha256', $content),
                'image/png',
                $content,
                'high',
                new AiOperationContext(
                    '11111111-1111-5111-8111-111111111111',
                    '22222222-2222-5222-8222-222222222222',
                    (int) $organization->id,
                    (int) $project->id,
                    (int) $session->id,
                    'understand_documents',
                    'vision',
                    1,
                    (int) $document->id,
                    (int) $page->id,
                    (int) $unit->id,
                    '33333333-3333-5333-8333-333333333333',
                ),
                (new ProjectiveTransformFactory)->identity(),
            );
            $physical = new EloquentVisionPhysicalAttemptStore(DB::connection());
            $provider = new RecordedDurableRecoveryVisionProvider($physical);
            $runner = new RunIndependentObservers(
                new EloquentAiRoleRunRepository(DB::connection(), 60),
                $provider,
                new ObserverInputBuilder,
                'openai/gpt-5.6-luna',
            );

            try {
                $runner->run($source, [ObserverProfile::Literal]);
                self::fail('The injected post-response crash did not occur.');
            } catch (RuntimeException $exception) {
                self::assertSame('injected_after_response_received', $exception->getMessage());
            }
            DB::table('estimate_generation_ai_role_runs')->update([
                'lease_expires_at' => new DateTimeImmutable('-1 minute'),
            ]);

            $results = $runner->run($source, [ObserverProfile::Literal]);

            self::assertArrayHasKey('observer_literal', $results);
            self::assertSame(1, $provider->wireCalls);
            self::assertSame(2, $provider->logicalCalls);
            self::assertSame('completed', DB::table('estimate_generation_ai_role_runs')->value('status'));
            self::assertSame(1, DB::table('estimate_generation_vision_physical_attempts')->count());
        } finally {
            DB::rollBack();
        }
    }
}

final class RecordedDurableRecoveryVisionProvider implements VisionProvider
{
    public int $logicalCalls = 0;

    public int $wireCalls = 0;

    public function __construct(private readonly VisionPhysicalAttemptStore $attempts) {}

    public function analyze(VisionDocumentInput $input): VisionAnalysisData
    {
        $this->logicalCalls++;
        $attemptId = $input->operationContext->attemptId;
        $fingerprint = hash('sha256', 'recorded-durable-response');
        $owner = '44444444-4444-5444-8444-444444444444';
        $now = new DateTimeImmutable;
        $snapshot = $this->attempts->claim(
            $input->operationContext,
            $fingerprint,
            $owner,
            $now,
            $now->modify('+120 seconds'),
        );
        ($input->onPhysicalAttemptReserved)($attemptId);
        if (! in_array($snapshot->state, ['response_received', 'completed'], true)) {
            $this->wireCalls++;
            $this->attempts->markWireStarted($attemptId, $fingerprint, $owner, $now, $now->modify('+120 seconds'));
            $this->attempts->storeResponse(
                $attemptId,
                $fingerprint,
                $owner,
                ['recorded' => true],
                'response_received',
                200,
                10,
                'openai/gpt-5.6-luna',
                ['currency' => 'RUB'],
            );

            throw new RuntimeException('injected_after_response_received');
        }

        return new VisionAnalysisData(
            'floor_plan',
            [VisionEvidenceData::fromArray(['key' => 'page', 'locator' => [
                'page_id' => $input->pageId,
                'page_number' => $input->pageNumber,
                'processing_unit_id' => $input->processingUnitId,
                'source_version' => $input->sourceVersion,
                'coordinate_space' => 'normalized_derivative_v1',
            ]])],
            [],
            [],
            ['scale_missing'],
            'timeweb',
            'openai/gpt-5.6-luna',
            'openai/gpt-5.6-luna',
            'model:v1',
            'measured',
            1,
            1,
        );
    }
}
