<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationController;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentProcessingStatusService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class EstimateGenerationFlowTest extends TestCase
{
    public function test_generate_waits_for_processing_documents_instead_of_staying_created(): void
    {
        Queue::fake();

        [$user, $project, $session] = $this->makeSession();
        $this->makeDocument($session, 'processing');

        $response = app(EstimateGenerationController::class)->generate(
            $this->request('/generate', 'POST', $user),
            $project,
            $session
        );
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(202, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertSame('waiting_for_documents', $payload['data']['status']);
        $this->assertSame('documents_processing', $payload['data']['progress']['stage']);
        $this->assertSame('Документы обрабатываются', $payload['data']['progress']['title']);
        $this->assertTrue($payload['data']['progress']['can_close_page']);
        Queue::assertNotPushed(GenerateEstimateDraftJob::class);
    }

    public function test_ready_document_starts_deferred_generation(): void
    {
        Queue::fake();

        [, , $session] = $this->makeSession('waiting_for_documents', 'documents_processing', 10);
        $document = $this->makeDocument($session, 'processing');

        app(DocumentProcessingStatusService::class)->markReady($document, 0.9, 'good', []);

        $session->refresh();
        $this->assertSame('queued', $session->status);
        $this->assertSame('queued', $session->processing_stage);
        $this->assertSame(40, $session->processing_progress);
        Queue::assertPushed(GenerateEstimateDraftJob::class);
    }

    public function test_status_returns_progress_stage_labels_and_user_action(): void
    {
        [$user, $project, $session] = $this->makeSession('processing', 'resource_enrichment', 71);

        $response = app(EstimateGenerationController::class)->status(
            $this->request('/status', 'GET', $user),
            $project,
            $session
        );
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($payload['success']);
        $this->assertSame('resource_enrichment', $payload['data']['progress']['stage']);
        $this->assertSame(71, $payload['data']['progress']['percent']);
        $this->assertSame('Подбираем ресурсы и цены', $payload['data']['progress']['title']);
        $this->assertSame('Можно закрыть страницу: мы продолжим расчет и сообщим, когда черновик будет готов.', $payload['data']['progress']['description']);
        $this->assertSame('wait', $payload['data']['progress']['user_action']);
        $this->assertTrue($payload['data']['progress']['can_close_page']);
    }

    /**
     * @return array{0: User, 1: Project, 2: EstimateGenerationSession}
     */
    private function makeSession(
        string $status = 'created',
        string $stage = 'created',
        int $progress = 0
    ): array {
        $organization = Organization::factory()->create();
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
        ]);
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => $status,
            'processing_stage' => $stage,
            'processing_progress' => $progress,
            'input_payload' => [
                'description' => 'Частный дом 150 м2',
            ],
            'problem_flags' => [],
        ]);

        return [$user, $project, $session];
    }

    private function makeDocument(EstimateGenerationSession $session, string $status): EstimateGenerationDocument
    {
        return EstimateGenerationDocument::query()->create([
            'session_id' => $session->id,
            'organization_id' => $session->organization_id,
            'project_id' => $session->project_id,
            'user_id' => $session->user_id,
            'filename' => $status . '-document.pdf',
            'mime_type' => 'application/pdf',
            'storage_path' => 'org-' . $session->organization_id . '/estimate-generation/documents/' . $status . '.pdf',
            'status' => $status,
            'processing_stage' => $status,
            'progress_percent' => $status === 'ready' ? 100 : 30,
            'page_count' => $status === 'ready' ? 2 : null,
            'processed_page_count' => $status === 'ready' ? 2 : 0,
            'quality_score' => $status === 'ready' ? 0.92 : null,
            'quality_level' => $status === 'ready' ? 'good' : null,
            'quality_flags' => [],
            'facts_summary' => [],
        ]);
    }

    private function request(string $uri, string $method, User $user): Request
    {
        $request = Request::create($uri, $method);
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    }
}
