<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationEvent;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationWorkflow;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\ApplyEstimateGenerationDraftRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\GenerateEstimateGenerationRequest;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
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
    public function test_persisted_session_follows_the_exact_lifecycle_without_skipping_states(): void
    {
        [, , $session] = $this->makeSession();
        $workflow = app(EstimateGenerationWorkflow::class);
        $transitions = [
            [EstimateGenerationEvent::StartDocumentProcessing, EstimateGenerationStatus::ProcessingDocuments],
            [EstimateGenerationEvent::DocumentsReady, EstimateGenerationStatus::ReadyToGenerate],
            [EstimateGenerationEvent::GenerationStarted, EstimateGenerationStatus::Generating],
            [EstimateGenerationEvent::GenerationNeedsReview, EstimateGenerationStatus::EstimateReviewRequired],
            [EstimateGenerationEvent::GenerationReady, EstimateGenerationStatus::ReadyToApply],
            [EstimateGenerationEvent::ApplyStarted, EstimateGenerationStatus::Applying],
            [EstimateGenerationEvent::ApplyCompleted, EstimateGenerationStatus::Applied],
        ];

        foreach ($transitions as $expectedVersion => [$event, $status]) {
            $session = $workflow->transition($session, $event);
            $session->refresh();
            $this->assertSame($status, $session->status);
            $this->assertSame($expectedVersion + 1, $session->state_version);
        }
    }

    public function test_generate_waits_for_processing_documents_instead_of_staying_created(): void
    {
        Queue::fake();

        [$user, $project, $session] = $this->makeSession();
        $this->makeDocument($session, 'processing');

        $response = app(EstimateGenerationController::class)->generate(
            $this->generationRequest($user, $session),
            $project,
            $session
        );
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(202, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertSame('processing_documents', $payload['data']['status']);
        $this->assertSame('processing_documents', $payload['data']['processing_stage']);
        $this->assertSame(5, $payload['data']['processing_progress']);
        Queue::assertNotPushed(GenerateEstimateDraftJob::class);
    }

    public function test_ready_document_starts_deferred_generation(): void
    {
        Queue::fake();

        [, , $session] = $this->makeSession('processing_documents', 'processing_documents', 10);
        $session->forceFill(['input_payload' => [
            ...($session->input_payload ?? []),
            'generation_requested' => true,
        ]])->save();
        $document = $this->makeDocument($session, 'processing');

        app(DocumentProcessingStatusService::class)->markReady($document, 0.9, 'good', [
            'document_understanding' => [
                'role_for_estimation' => 'drawing_architecture',
                'extracted_capabilities' => [
                    'has_quantities' => true,
                    'requires_manual_review' => false,
                ],
            ],
        ]);

        $session->refresh();
        $this->assertSame(EstimateGenerationStatus::Generating, $session->status);
        $this->assertSame('generating', $session->processing_stage);
        $this->assertSame(40, $session->processing_progress);
        Queue::assertPushed(GenerateEstimateDraftJob::class);
    }

    public function test_status_returns_progress_stage_labels_and_user_action(): void
    {
        [$user, $project, $session] = $this->makeSession('generating', 'resource_enrichment', 71);

        $response = app(EstimateGenerationController::class)->status(
            $this->request('/status', 'GET', $user),
            $project,
            $session
        );
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($payload['success']);
        $this->assertSame('resource_enrichment', $payload['data']['processing_stage']);
        $this->assertSame(71, $payload['data']['processing_progress']);
        $this->assertSame('generating', $payload['data']['status']);
    }

    public function test_apply_returns_review_queue_when_blocking_items_remain(): void
    {
        [$user, $project, $session] = $this->makeSession('ready_to_apply', 'quality_check', 100);
        $this->makeDocument($session, 'ready');
        $session->forceFill([
            'draft_payload' => [
                'quality_summary' => [
                    'status' => 'ready',
                    'level' => 'passed',
                    'total_work_items' => 1,
                    'priced_work_items' => 1,
                    'operation_work_items' => 0,
                    'quantity_review_work_items' => 0,
                    'not_calculated_work_items' => 0,
                    'safe_norm_required_work_items' => 0,
                    'duplicate_work_items' => 0,
                    'normative_items' => ['requires_review' => 0],
                ],
                'local_estimates' => [[
                    'key' => 'local-1',
                    'title' => 'Local estimate',
                    'sections' => [[
                        'key' => 'section-1',
                        'title' => 'Section',
                        'work_items' => [[
                            'key' => 'ready-work',
                            'item_type' => 'priced_work',
                            'name' => 'Ready work',
                            'unit' => 'm2',
                            'quantity' => 1,
                            'total_cost' => 1000,
                            'pricing_status' => 'calculated',
                            'normative_match' => [
                                'norm_id' => 10,
                                'code' => '01-01-001-01',
                                'status' => 'matched',
                                'decision' => ['status' => 'accepted'],
                            ],
                            'validation_flags' => [],
                        ]],
                    ]],
                ]],
            ],
        ])->save();

        $package = EstimateGenerationPackage::query()->create([
            'session_id' => $session->id,
            'key' => 'local-1',
            'title' => 'Local estimate',
            'scope_type' => 'site',
            'status' => 'ready_for_review',
            'sort_order' => 100,
        ]);
        EstimateGenerationPackageItem::query()->create([
            'package_id' => $package->id,
            'key' => 'package-only-blocker',
            'item_type' => 'priced_work',
            'name' => 'Package only blocker',
            'unit' => 'm',
            'quantity' => 1,
            'total_cost' => 0,
            'flags' => ['pricing_not_calculated'],
            'metadata' => [
                'pricing_status' => 'not_calculated',
                'pricing_blocker' => 'normative_required',
                'normative_match' => ['status' => 'not_found'],
            ],
        ]);

        $request = ApplyEstimateGenerationDraftRequest::create('/apply', 'POST', ['name' => 'Blocked draft']);
        $request->setUserResolver(static fn (): User => $user);

        $response = app(EstimateGenerationController::class)->apply($request, $project, $session->fresh());
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertFalse($payload['success']);
        $this->assertSame(1, $payload['review_queue']['summary']['blocking']);
        $this->assertSame('package-only-blocker', $payload['review_queue']['items'][0]['work_item_key']);
        $this->assertSame('review_items_require_action', $payload['estimator_readiness']['blockers'][0]['code']);
    }

    /**
     * @return array{0: User, 1: Project, 2: EstimateGenerationSession}
     */
    private function makeSession(
        string $status = 'draft',
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
            'filename' => $status.'-document.pdf',
            'mime_type' => 'application/pdf',
            'storage_path' => 'org-'.$session->organization_id.'/estimate-generation/documents/'.$status.'.pdf',
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

    private function generationRequest(User $user, EstimateGenerationSession $session): GenerateEstimateGenerationRequest
    {
        $request = GenerateEstimateGenerationRequest::create('/generate', 'POST', [
            'state_version' => $session->state_version,
        ]);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    }
}
