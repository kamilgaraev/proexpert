<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationDocumentController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\IgnoreEstimateGenerationDocumentRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\ManageEstimateGenerationDocumentPagesRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\RetryEstimateGenerationDocumentRequest;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationUnitJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDrawingElement;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentFact;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentPage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationQuantityTakeoff;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationScopeInference;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class EstimateGenerationDocumentApiTest extends TestCase
{
    public function test_list_and_detail_return_safe_document_payloads(): void
    {
        [$user, $project, $session] = $this->makeSession();
        $document = $this->makeDocument($session, 'ready');
        $page = EstimateGenerationDocumentPage::query()->create([
            'document_id' => $document->id,
            'organization_id' => $session->organization_id,
            'project_id' => $session->project_id,
            'session_id' => $session->id,
            'page_number' => 1,
            'text' => 'Склад 1200 м2',
            'raw_payload_path' => 'org-'.$session->organization_id.'/raw/provider.json',
            'normalized_payload' => ['blocks' => []],
            'quality_flags' => [],
        ]);
        EstimateGenerationDocumentFact::query()->create([
            'document_id' => $document->id,
            'page_id' => $page->id,
            'organization_id' => $session->organization_id,
            'project_id' => $session->project_id,
            'session_id' => $session->id,
            'fact_type' => 'total_area',
            'scope_key' => 'total_area',
            'label' => 'Общая площадь',
            'value_text' => '1200 м2',
            'value_number' => 1200.0,
            'unit' => 'м2',
            'confidence' => 0.92,
            'source_ref' => ['type' => 'document', 'document_id' => $document->id, 'page_number' => 1],
        ]);
        $request = $this->request('/documents', 'GET', $user);

        $list = app(EstimateGenerationDocumentController::class)->index($request, $project, $session);
        $listPayload = json_decode($list->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($listPayload['success']);
        $this->assertSame(1, $listPayload['data']['documents_summary']['ready_count']);
        $this->assertArrayNotHasKey('storage_path', $listPayload['data']['documents'][0]);

        $detail = app(EstimateGenerationDocumentController::class)->show($request, $project, $session, $document);
        $detailPayload = json_decode($detail->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($detailPayload['success']);
        $this->assertSame('Склад 1200 м2', $detailPayload['data']['pages'][0]['text']);
        $this->assertSame('ready', $detailPayload['data']['pages'][0]['status']);
        $this->assertFalse($detailPayload['data']['pages'][0]['excluded']);
        $this->assertArrayNotHasKey('raw_payload_path', $detailPayload['data']['pages'][0]);
        $this->assertSame('total_area', $detailPayload['data']['facts'][0]['fact_type']);
    }

    public function test_retry_resets_failed_document_and_dispatches_ocr_job(): void
    {
        Queue::fake();

        [$user, $project, $session] = $this->makeSession();
        $document = $this->makeDocument($session, 'failed');
        $request = RetryEstimateGenerationDocumentRequest::create('/retry', 'POST', ['state_version' => $session->state_version, 'reason' => 'Повторить']);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->setUserResolver(static fn (): User => $user);

        $response = app(EstimateGenerationDocumentController::class)->retry($request, $project, $session, $document);
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($payload['success']);
        $this->assertSame('queued', $payload['data']['document']['status']);
        $this->assertSame('stored', $payload['data']['document']['processing_stage']);
        $this->assertSame(1, $payload['data']['documents_summary']['pending_count']);
        $this->assertDatabaseHas('estimate_generation_documents', [
            'id' => $document->id,
            'status' => 'queued',
            'processing_stage' => 'stored',
        ]);
        Queue::assertPushed(
            ProcessEstimateGenerationDocumentJob::class,
            static fn (ProcessEstimateGenerationDocumentJob $job): bool => $job->queue === ProcessEstimateGenerationDocumentJob::RECOVERY_QUEUE
                && $job->connection === ProcessEstimateGenerationDocumentJob::CONNECTION
        );
    }

    public function test_retry_selected_pages_resets_only_selected_units_and_page_lineage(): void
    {
        Queue::fake();

        [$user, $project, $session] = $this->makeSession();
        $document = $this->makeDocument($session, 'ready');
        $document->forceFill([
            'source_version' => 'sha256:document',
            'units_finalized_source_version' => 'sha256:document',
            'units_reconciled_source_version' => 'sha256:document',
        ])->save();
        $first = $this->makeProcessedPage($document, 1, 'Страница 1');
        $second = $this->makeProcessedPage($document, 2, 'Страница 2');
        $this->makePageLineage($first);
        $this->makePageLineage($second);
        $request = ManageEstimateGenerationDocumentPagesRequest::create('/pages/retry', 'POST', [
            'state_version' => $session->state_version,
            'page_numbers' => [2],
            'reason' => 'плохое распознавание',
        ]);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->setUserResolver(static fn (): User => $user);

        $response = app(EstimateGenerationDocumentController::class)->retryPages($request, $project, $session, $document);
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($payload['success']);
        $this->assertSame('processing', $payload['data']['document']['status']);
        $this->assertSame('queued', $payload['data']['pages'][1]['status']);
        $this->assertSame('ready', $payload['data']['pages'][0]['status']);
        $this->assertDatabaseHas('estimate_generation_document_pages', [
            'id' => $first->id,
            'status' => 'ready',
            'text' => 'Страница 1',
        ]);
        $this->assertDatabaseHas('estimate_generation_document_pages', [
            'id' => $second->id,
            'status' => 'queued',
            'text' => null,
        ]);
        $this->assertDatabaseHas('estimate_generation_document_facts', ['page_id' => $first->id]);
        $this->assertDatabaseMissing('estimate_generation_document_facts', ['page_id' => $second->id]);
        $this->assertDatabaseHas('estimate_generation_processing_units', [
            'id' => $second->processing_unit_id,
            'status' => 'pending',
            'output_version' => null,
        ]);
        Queue::assertPushed(
            ProcessEstimateGenerationUnitJob::class,
            static fn (ProcessEstimateGenerationUnitJob $job): bool => $job->queue === ProcessEstimateGenerationUnitJob::RECOVERY_QUEUE
                && $job->connection === ProcessEstimateGenerationUnitJob::CONNECTION
        );
    }

    public function test_exclude_and_restore_selected_pages_do_not_ignore_whole_document(): void
    {
        [$user, $project, $session] = $this->makeSession();
        $document = $this->makeDocument($session, 'ready');
        $document->forceFill([
            'source_version' => 'sha256:document',
            'units_finalized_source_version' => 'sha256:document',
            'units_reconciled_source_version' => 'sha256:document',
        ])->save();
        $first = $this->makeProcessedPage($document, 1, 'Страница 1');
        $second = $this->makeProcessedPage($document, 2, 'Страница 2');
        $this->makePageLineage($first);
        $this->makePageLineage($second);
        $exclude = ManageEstimateGenerationDocumentPagesRequest::create('/pages/exclude', 'POST', [
            'state_version' => $session->state_version,
            'page_numbers' => [2],
            'reason' => 'справочная страница',
        ]);
        $exclude->setContainer($this->app)->setRedirector($this->app['redirect']);
        $exclude->setUserResolver(static fn (): User => $user);

        $excluded = app(EstimateGenerationDocumentController::class)->excludePages($exclude, $project, $session, $document);
        $excludedPayload = json_decode($excluded->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($excludedPayload['success']);
        $this->assertSame('ready', $excludedPayload['data']['document']['status']);
        $this->assertSame('excluded', $excludedPayload['data']['pages'][1]['status']);
        $this->assertDatabaseHas('estimate_generation_documents', ['id' => $document->id, 'status' => 'ready']);
        $this->assertDatabaseHas('estimate_generation_document_pages', ['id' => $second->id, 'status' => 'excluded']);
        $this->assertDatabaseHas('estimate_generation_document_facts', ['page_id' => $first->id]);
        $this->assertDatabaseMissing('estimate_generation_document_facts', ['page_id' => $second->id]);

        $restore = ManageEstimateGenerationDocumentPagesRequest::create('/pages/restore', 'POST', [
            'state_version' => $session->state_version,
            'page_numbers' => [2],
        ]);
        $restore->setContainer($this->app)->setRedirector($this->app['redirect']);
        $restore->setUserResolver(static fn (): User => $user);

        $restored = app(EstimateGenerationDocumentController::class)->restorePages($restore, $project, $session, $document);
        $restoredPayload = json_decode($restored->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($restoredPayload['success']);
        $this->assertSame('ready', $restoredPayload['data']['document']['status']);
        $this->assertSame('ready', $restoredPayload['data']['pages'][1]['status']);
    }

    public function test_retry_is_allowed_for_ready_document(): void
    {
        Queue::fake();

        [$user, $project, $session] = $this->makeSession();
        $document = $this->makeDocument($session, 'ready');
        $request = RetryEstimateGenerationDocumentRequest::create('/retry', 'POST', ['state_version' => $session->state_version, 'reason' => 'Повторить']);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->setUserResolver(static fn (): User => $user);

        $response = app(EstimateGenerationDocumentController::class)->retry($request, $project, $session, $document);
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($payload['success']);
        $this->assertSame('queued', $payload['data']['document']['status']);
        $this->assertSame(1, $payload['data']['documents_summary']['pending_count']);
        Queue::assertPushed(ProcessEstimateGenerationDocumentJob::class);
    }

    public function test_ignore_is_allowed_for_ready_failed_or_review_documents(): void
    {
        [$user, $project, $session] = $this->makeSession();
        $processing = $this->makeDocument($session, 'processing');
        $ready = $this->makeDocument($session, 'ready');
        $failed = $this->makeDocument($session, 'failed');
        $request = IgnoreEstimateGenerationDocumentRequest::create('/ignore', 'POST', ['state_version' => $session->state_version, 'reason' => 'Не учитывать']);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->setUserResolver(static fn (): User => $user);

        $notAllowed = app(EstimateGenerationDocumentController::class)->ignore($request, $project, $session, $processing);
        $notAllowedPayload = json_decode($notAllowed->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(422, $notAllowed->getStatusCode());
        $this->assertFalse($notAllowedPayload['success']);

        $readyAllowed = app(EstimateGenerationDocumentController::class)->ignore($request, $project, $session, $ready);
        $readyPayload = json_decode($readyAllowed->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($readyPayload['success']);
        $this->assertSame('ignored', $readyPayload['data']['document']['status']);

        $allowed = app(EstimateGenerationDocumentController::class)->ignore($request, $project, $session, $failed);
        $allowedPayload = json_decode($allowed->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($allowedPayload['success']);
        $this->assertSame('ignored', $allowedPayload['data']['document']['status']);
        $this->assertSame('completed', $allowedPayload['data']['document']['processing_stage']);
        $this->assertSame(2, $allowedPayload['data']['documents_summary']['ignored_count']);
        $this->assertDatabaseHas('estimate_generation_documents', [
            'id' => $failed->id,
            'status' => 'ignored',
            'processing_stage' => 'completed',
        ]);
    }

    public function test_document_from_another_session_is_not_accessible(): void
    {
        [$user, $project, $session] = $this->makeSession();
        [, , $otherSession] = $this->makeSession();
        $foreignDocument = $this->makeDocument($otherSession, 'ready');
        $request = $this->request('/documents/foreign', 'GET', $user);

        $this->expectException(NotFoundHttpException::class);

        app(EstimateGenerationDocumentController::class)->show($request, $project, $session, $foreignDocument);
    }

    /**
     * @return array{0: User, 1: Project, 2: EstimateGenerationSession}
     */
    private function makeSession(): array
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
            'status' => 'draft',
            'processing_stage' => 'draft',
            'processing_progress' => 0,
            'input_payload' => ['description' => 'Склад 1200 м2'],
            'problem_flags' => [],
        ]);

        return [$user, $project, $session];
    }

    private function makeDocument(EstimateGenerationSession $session, string $status): EstimateGenerationDocument
    {
        $processingStage = match ($status) {
            'queued' => 'stored',
            'processing' => 'preflight',
            default => 'completed',
        };

        return EstimateGenerationDocument::query()->create([
            'session_id' => $session->id,
            'organization_id' => $session->organization_id,
            'project_id' => $session->project_id,
            'user_id' => $session->user_id,
            'filename' => $status.'-document.pdf',
            'mime_type' => 'application/pdf',
            'storage_path' => 'org-'.$session->organization_id.'/estimate-generation/documents/'.$status.'.pdf',
            'status' => $status,
            'processing_stage' => $processingStage,
            'progress_percent' => $status === 'ready' ? 100 : 30,
            'quality_score' => $status === 'ready' ? 0.92 : null,
            'quality_level' => $status === 'ready' ? 'good' : null,
            'quality_flags' => [],
            'facts_summary' => [],
        ]);
    }

    private function makeProcessedPage(EstimateGenerationDocument $document, int $pageNumber, string $text): EstimateGenerationDocumentPage
    {
        $unit = EstimateGenerationProcessingUnit::query()->create([
            'organization_id' => $document->organization_id,
            'project_id' => $document->project_id,
            'session_id' => $document->session_id,
            'document_id' => $document->id,
            'unit_type' => 'pdf_page',
            'unit_index' => $pageNumber,
            'source_version' => (string) $document->source_version,
            'status' => 'completed',
            'attempt_count' => 1,
            'output_version' => 'output-'.$pageNumber,
            'output_count' => 1,
            'locator' => ['page' => $pageNumber],
            'metadata' => [],
            'completed_at' => now(),
        ]);

        return EstimateGenerationDocumentPage::query()->create([
            'document_id' => $document->id,
            'processing_unit_id' => $unit->id,
            'source_version' => (string) $document->source_version,
            'output_version' => 'output-'.$pageNumber,
            'organization_id' => $document->organization_id,
            'project_id' => $document->project_id,
            'session_id' => $document->session_id,
            'page_number' => $pageNumber,
            'text' => $text,
            'text_hash' => hash('sha256', $text),
            'normalized_payload' => ['blocks' => []],
            'quality_flags' => [],
            'status' => 'ready',
        ]);
    }

    private function makePageLineage(EstimateGenerationDocumentPage $page): void
    {
        EstimateGenerationDocumentFact::query()->create([
            'document_id' => $page->document_id,
            'page_id' => $page->id,
            'organization_id' => $page->organization_id,
            'project_id' => $page->project_id,
            'session_id' => $page->session_id,
            'fact_type' => 'note',
            'scope_key' => 'note',
            'label' => 'Примечание',
            'value_text' => 'данные',
            'confidence' => 0.8,
            'source_ref' => ['page_number' => $page->page_number],
        ]);
        EstimateGenerationDrawingElement::query()->create([
            'document_id' => $page->document_id,
            'page_id' => $page->id,
            'organization_id' => $page->organization_id,
            'project_id' => $page->project_id,
            'session_id' => $page->session_id,
            'type' => 'wall',
            'label' => 'Стена',
            'bbox' => [],
            'geometry' => [],
            'source_ref' => ['page_number' => $page->page_number],
            'normalized_payload' => [],
        ]);
        EstimateGenerationQuantityTakeoff::query()->create([
            'document_id' => $page->document_id,
            'page_id' => $page->id,
            'organization_id' => $page->organization_id,
            'project_id' => $page->project_id,
            'session_id' => $page->session_id,
            'source_element_ids' => [],
            'scope_key' => 'wall',
            'work_intent' => [],
            'name' => 'Стена',
            'unit' => 'м2',
            'quantity' => 1,
            'source_refs' => [['page_number' => $page->page_number]],
            'normalized_payload' => [],
        ]);
        EstimateGenerationScopeInference::query()->create([
            'document_id' => $page->document_id,
            'page_id' => $page->id,
            'organization_id' => $page->organization_id,
            'project_id' => $page->project_id,
            'session_id' => $page->session_id,
            'inference_type' => 'scope',
            'title' => 'Работы',
            'description' => 'Описание',
            'source_refs' => [['page_number' => $page->page_number]],
            'normative_basis' => [],
            'work_intent' => [],
            'confidence' => 0.8,
            'review_required' => false,
        ]);
    }

    private function request(string $uri, string $method, User $user): Request
    {
        $request = Request::create($uri, $method);
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    }
}
