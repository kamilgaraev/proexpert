<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\AnalyzeEstimateGenerationRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\GenerateEstimateGenerationRequest;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Services\DocumentParsingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Contracts\OcrClientInterface;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\OcrDocumentProcessor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EstimateGenerationDocumentE2ETest extends TestCase
{
    public function test_uploaded_ocr_document_drives_analysis_generation_and_source_refs(): void
    {
        Storage::fake('s3');
        Queue::fake();

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
            'input_payload' => [
                'description' => '',
                'regional_context' => [
                    'region_name' => 'Республика Татарстан',
                    'year' => 2026,
                    'quarter' => 1,
                    'version_key' => '2026-q1-ru-ta',
                ],
            ],
            'problem_flags' => [],
        ]);

        $this->app->instance(OcrClientInterface::class, new class implements OcrClientInterface
        {
            public function recognize(OcrDocumentInput $input): OcrRecognitionResult
            {
                return new OcrRecognitionResult(
                    provider: 'test_ocr',
                    model: 'page',
                    pages: [
                        new OcrPageResult(
                            pageNumber: 1,
                            text: implode("\n", [
                                'Общая площадь здания 1280 м2',
                                'Складская зона 900 м2',
                                'Офисная зона 280 м2',
                                '1 этаж',
                                'Плоская кровля, электроснабжение, вентиляция',
                            ]),
                            confidence: 0.93,
                            languageCodes: ['ru'],
                        ),
                    ],
                );
            }
        });

        $documents = app(DocumentParsingService::class)->storeParsedDocuments(
            $session,
            [UploadedFile::fake()->createWithContent('warehouse-plan.png', 'image content')],
            $user
        );
        $document = $documents->firstOrFail();

        app(OcrDocumentProcessor::class)->process($document);
        $document->refresh();

        $this->assertSame('ready', $document->status);
        $this->assertEquals(1280.0, $document->facts_summary['total_area_m2']);

        $analyzeResponse = app(EstimateGenerationController::class)->analyze(
            $this->request('/analyze', 'POST', $user, $session->fresh()),
            $project,
            $session->fresh()
        );

        $this->assertSame(200, $analyzeResponse->getStatusCode());

        $generateResponse = app(EstimateGenerationController::class)->generate(
            $this->request('/generate', 'POST', $user, $session->fresh()),
            $project,
            $session->fresh()
        );

        $this->assertSame(202, $generateResponse->getStatusCode());
        Queue::assertPushed(GenerateEstimateDraftJob::class);

        $session->refresh();
        (new GenerateEstimateDraftJob(
            $session->id,
            $session->state_version,
            (string) ($session->input_payload['generation_attempt_id'] ?? ''),
            FailureExecutionSnapshot::capture(
                $session,
                'generate_draft',
                (string) ($session->input_payload['generation_attempt_id'] ?? ''),
            ),
        ))->handle(app(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\DraftPipelineEntrypoint::class));
        $session->refresh();

        $draft = $session->draft_payload;

        $this->assertContains($session->status, [
            EstimateGenerationStatus::EstimateReviewRequired,
            EstimateGenerationStatus::ReadyToApply,
        ]);
        $this->assertEquals(1280.0, $draft['object_profile']['area']);
        $this->assertSame($document->id, $draft['traceability']['document_source_refs'][0]['document_id']);
        $this->assertSame($document->id, $draft['local_estimates'][0]['source_refs'][0]['document_id']);
        $this->assertSame($document->id, $draft['local_estimates'][0]['sections'][0]['work_items'][0]['source_refs'][0]['document_id']);
    }

    private function request(string $uri, string $method, User $user, EstimateGenerationSession $session): Request
    {
        $data = ['state_version' => $session->state_version];
        $request = match ($uri) {
            '/analyze' => AnalyzeEstimateGenerationRequest::create($uri, $method, $data),
            '/generate' => GenerateEstimateGenerationRequest::create($uri, $method, $data),
            default => Request::create($uri, $method, $data),
        };
        if ($request instanceof GenerateEstimateGenerationRequest || $request instanceof AnalyzeEstimateGenerationRequest) {
            $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        }
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    }
}
