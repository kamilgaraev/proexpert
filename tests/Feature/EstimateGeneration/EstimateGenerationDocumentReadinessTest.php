<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationDocumentController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\IgnoreEstimateGenerationDocumentRequest;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EstimateGenerationDocumentReadinessTest extends TestCase
{
    public function test_pending_document_defers_generation_with_summary(): void
    {
        Queue::fake();

        [$user, $project, $session] = $this->makeSession();
        $this->makeDocument($session, 'queued');
        $request = $this->request('/generate', 'POST', $user);

        $response = app(EstimateGenerationController::class)->generate($request, $project, $session);
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(202, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertSame('waiting_for_documents', $payload['data']['status']);
        $this->assertSame(1, $payload['data']['documents_summary']['pending_count']);
        $this->assertSame('documents_processing', $payload['data']['progress']['stage']);
        Queue::assertNotPushed(GenerateEstimateDraftJob::class);
    }

    public function test_pending_document_blocks_analysis(): void
    {
        [$user, $project, $session] = $this->makeSession();
        $this->makeDocument($session, 'processing');
        $request = $this->request('/analyze', 'POST', $user);

        $response = app(EstimateGenerationController::class)->analyze($request, $project, $session);
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertFalse($payload['success']);
        $this->assertSame(1, $payload['documents_summary']['pending_count']);
    }

    public function test_failed_document_blocks_generation_until_it_is_ignored(): void
    {
        Queue::fake();

        [$user, $project, $session] = $this->makeSession();
        $document = $this->makeDocument($session, 'failed');
        $generateRequest = $this->request('/generate', 'POST', $user);

        $blocked = app(EstimateGenerationController::class)->generate($generateRequest, $project, $session);
        $blockedPayload = json_decode($blocked->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(409, $blocked->getStatusCode());
        $this->assertSame(1, $blockedPayload['documents_summary']['action_required_count']);

        $ignoreRequest = IgnoreEstimateGenerationDocumentRequest::create('/ignore', 'POST', ['reason' => 'Не нужен для этой сметы']);
        $ignoreRequest->setUserResolver(static fn (): User => $user);
        app(EstimateGenerationDocumentController::class)->ignore($ignoreRequest, $project, $session, $document);

        $allowed = app(EstimateGenerationController::class)->generate($generateRequest, $project, $session->fresh());
        $allowedPayload = json_decode($allowed->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(202, $allowed->getStatusCode());
        $this->assertSame('queued', $allowedPayload['data']['status']);
        $this->assertSame(1, $allowedPayload['data']['documents_summary']['ignored_count']);
        Queue::assertPushed(GenerateEstimateDraftJob::class);
    }

    public function test_status_returns_documents_summary(): void
    {
        [$user, $project, $session] = $this->makeSession('processing');
        $this->makeDocument($session, 'ready');
        $request = $this->request('/status', 'GET', $user);

        $response = app(EstimateGenerationController::class)->status($request, $project, $session);
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($payload['success']);
        $this->assertSame(1, $payload['data']['documents_summary']['ready_count']);
        $this->assertTrue($payload['data']['documents_summary']['can_generate']);
        $this->assertSame(2, $payload['data']['documents_summary']['items'][0]['page_count']);
        $this->assertSame(2, $payload['data']['documents_summary']['items'][0]['processed_page_count']);
    }

    public function test_empty_session_without_description_or_ready_documents_is_not_generated(): void
    {
        Queue::fake();

        [$user, $project, $session] = $this->makeSession();
        $session->forceFill([
            'input_payload' => [
                'description' => '',
            ],
        ])->save();

        $response = app(EstimateGenerationController::class)->generate(
            $this->request('/generate', 'POST', $user),
            $project,
            $session->fresh()
        );
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertFalse($payload['success']);
        $this->assertSame(0, $payload['documents_summary']['ready_count']);
        Queue::assertNotPushed(GenerateEstimateDraftJob::class);
    }

    /**
     * @return array{0: User, 1: Project, 2: EstimateGenerationSession}
     */
    private function makeSession(string $status = 'created'): array
    {
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
            'processing_stage' => $status,
            'processing_progress' => 0,
            'input_payload' => [
                'description' => 'Склад 1200 м2',
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
            'facts_summary' => $status === 'ready' ? [
                'document_understanding' => [
                    'role_for_estimation' => 'drawing_architecture',
                    'extracted_capabilities' => [
                        'has_quantities' => true,
                        'requires_manual_review' => false,
                    ],
                ],
            ] : [],
        ]);
    }

    private function request(string $uri, string $method, User $user): Request
    {
        $request = Request::create($uri, $method);
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    }
}
