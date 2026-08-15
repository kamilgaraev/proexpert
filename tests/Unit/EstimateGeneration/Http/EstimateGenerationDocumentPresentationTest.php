<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Http;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitStatus;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\EstimateGenerationDocumentActionBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\EstimateGenerationDocumentPreviewService;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationDocumentDetailResource;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationDocumentResource;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentPage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Services\Logging\LoggingService;
use App\Services\Storage\FileService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class EstimateGenerationDocumentPresentationTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function generic_failed_document_exposes_only_ignore_action_from_review_permission(): void
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->expects('can')->once()->withArgs(static fn (User $user, string $permission, array $context): bool => $user->id === 5
            && $permission === 'estimate_generation.review'
            && $context === ['organization_id' => 7, 'project_id' => 17]
        )->andReturnTrue();

        $actions = (new EstimateGenerationDocumentActionBuilder($authorization))->forDocument(
            $this->document('failed'),
            $this->user(7),
        );

        self::assertSame(['ignore_document'], array_column($actions, 'action'));
        self::assertSame([9], array_column($actions, 'state_version'));
        self::assertSame([
            '/api/v1/admin/projects/17/estimate-generation/sessions/41/documents/91/ignore',
        ], array_column($actions, 'endpoint'));
        self::assertTrue($actions[0]['requires_confirmation']);
    }

    #[Test]
    public function generic_legacy_failed_document_remains_user_action_required(): void
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('can')->andReturnTrue();
        $this->app->instance(
            EstimateGenerationDocumentActionBuilder::class,
            new EstimateGenerationDocumentActionBuilder($authorization),
        );
        $request = Request::create('/documents/91');
        $user = $this->user(7);
        $request->setUserResolver(static fn (): User => $user);

        $payload = (new EstimateGenerationDocumentResource($this->document('failed')))->toArray($request);

        self::assertSame('user_action_required', $payload['processing_outcome']['type']);
        self::assertSame(['ignore_document'], array_column($payload['available_actions'], 'action'));
    }

    #[Test]
    public function production_document_173_failure_mix_exposes_explicit_retry_capability_and_source_fence(): void
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('can')->andReturnTrue();
        $this->app->instance(
            EstimateGenerationDocumentActionBuilder::class,
            new EstimateGenerationDocumentActionBuilder($authorization),
        );
        $document = $this->document('failed');
        $document->forceFill([
            'page_count' => 22,
            'processed_page_count' => 0,
            'source_version' => 'sha256:current',
            'error_code' => 'document_processing_system_failed',
            'error_message_key' => 'estimate_generation.document_processing_system_failed',
            'meta' => [
                'processing_attempt_id' => 'attempt-terminal',
            ],
            'facts_summary' => [
                'processing_outcome' => [
                    'type' => 'system_failure',
                    'counts' => [
                        'included' => 22,
                        'ready' => 0,
                        'needs_user_action' => 0,
                        'system_failed' => 22,
                        'processing' => 0,
                        'excluded' => 0,
                    ],
                    'retry_allowed' => false,
                ],
            ],
        ]);
        $fingerprint = hash('sha256', 'typed-systemic-root');
        $failureCodes = [
            ...array_fill(0, 9, 'document_unit_pre_wire_failed'),
            ...array_fill(0, 11, 'vision_provider_response_invalid'),
            ...array_fill(0, 2, 'vision_wire_outcome_ambiguous'),
        ];
        $document->setRelation('processingUnits', new Collection(array_map(
            static function (string $failureCode, int $index) use ($fingerprint): EstimateGenerationProcessingUnit {
                $unit = new EstimateGenerationProcessingUnit;
                $unit->forceFill([
                    'id' => 500 + $index,
                    'organization_id' => 7,
                    'project_id' => 17,
                    'session_id' => 41,
                    'document_id' => 91,
                    'source_version' => 'sha256:current',
                    'unit_type' => DocumentUnitType::PdfPage,
                    'unit_index' => $index + 1,
                    'status' => DocumentProcessingUnitStatus::Failed,
                    'output_count' => 0,
                    'failure_code' => $failureCode,
                    'failure_fingerprint' => $fingerprint,
                    'metadata' => ['failure_category' => 'terminal'],
                ]);

                return $unit;
            }, $failureCodes, range(0, 21),
        )));
        $request = Request::create('/documents/91');
        $user = $this->user(7);
        $request->setUserResolver(static fn (): User => $user);

        $payload = (new EstimateGenerationDocumentResource($document))->toArray($request);

        self::assertSame('system_failure', $payload['processing_outcome']['type']);
        self::assertSame(22, $payload['processing_outcome']['counts']['system_failed']);
        self::assertSame(0, $payload['processing_outcome']['counts']['processing']);
        self::assertSame('Сервис не смог обработать документ. Файл сохранён, повторная загрузка не требуется.', $payload['processing_outcome']['message']);
        self::assertSame(['retry_document', 'ignore_document'], array_column($payload['available_actions'], 'action'));
        self::assertSame('explicit_system_failure_retry', $payload['available_actions'][0]['retry_disposition']);
        self::assertSame('sha256:current', $payload['available_actions'][0]['source_version']);
        self::assertTrue($payload['available_actions'][0]['requires_confirmation']);
    }

    #[Test]
    public function production_shaped_active_document_reports_execution_and_usefulness_without_public_costs(): void
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('can')->andReturnTrue();
        $this->app->instance(
            EstimateGenerationDocumentActionBuilder::class,
            new EstimateGenerationDocumentActionBuilder($authorization),
        );
        $document = $this->document('needs_review');
        $document->forceFill([
            'page_count' => 22,
            'processed_page_count' => 0,
            'progress_percent' => 100,
            'source_version' => 'sha256:current',
            'processing_control_status' => 'active',
            'facts_summary' => [],
        ]);
        $units = [];
        $pages = [];
        for ($page = 1; $page <= 22; $page++) {
            $status = match (true) {
                $page <= 3 => DocumentProcessingUnitStatus::Completed,
                $page <= 12 => DocumentProcessingUnitStatus::Failed,
                $page <= 14 => DocumentProcessingUnitStatus::Running,
                default => DocumentProcessingUnitStatus::Pending,
            };
            $unit = new EstimateGenerationProcessingUnit;
            $unit->forceFill([
                'id' => 500 + $page,
                'organization_id' => 7,
                'project_id' => 17,
                'session_id' => 41,
                'document_id' => 91,
                'source_version' => 'sha256:current',
                'unit_type' => DocumentUnitType::PdfPage,
                'unit_index' => $page,
                'status' => $status,
                'output_count' => $status === DocumentProcessingUnitStatus::Completed ? 1 : 0,
                'failure_code' => $status === DocumentProcessingUnitStatus::Failed
                    ? ($page <= 7 ? 'document_unit_pre_wire_failed' : ($page <= 11 ? 'unit_claim_lost' : 'vision_provider_response_invalid'))
                    : null,
                'metadata' => $status === DocumentProcessingUnitStatus::Failed
                    ? ['failure_category' => 'terminal']
                    : [],
            ]);
            $units[] = $unit;

            $pageProjection = new EstimateGenerationDocumentPage;
            $pageProjection->forceFill([
                'id' => 700 + $page,
                'organization_id' => 7,
                'project_id' => 17,
                'session_id' => 41,
                'document_id' => 91,
                'processing_unit_id' => 500 + $page,
                'source_version' => 'sha256:current',
                'page_number' => $page,
                'status' => match (true) {
                    $page <= 3 => 'needs_review',
                    $page <= 12 => 'failed',
                    $page <= 14 => 'processing',
                    default => 'queued',
                },
                'quality_flags' => $page <= 3 ? ['review_required'] : [],
            ]);
            $pages[] = $pageProjection;
        }
        $document->setRelation('processingUnits', new Collection($units));
        $document->setRelation('pages', new Collection($pages));
        $document->setAttribute('processing_cost_spent_rub', '209.35611000');
        $document->setAttribute('processing_cost_limit', '250.00000000');
        $request = Request::create('/documents/91');
        $user = $this->user(7);
        $request->setUserResolver(static fn (): User => $user);

        $payload = (new EstimateGenerationDocumentResource($document))->toArray($request);

        self::assertSame('processing', $payload['processing_outcome']['type']);
        self::assertSame([
            'included' => 22,
            'ready' => 3,
            'needs_user_action' => 3,
            'terminal_system_failed' => 9,
            'breaker_stopped' => 0,
            'system_failed' => 9,
            'processing' => 10,
            'excluded' => 0,
            'cancelled' => 0,
        ], $payload['processing_outcome']['counts']);
        self::assertSame(54, $payload['processing_outcome']['execution_progress_percent']);
        self::assertSame(['completed_pages' => 12, 'total_pages' => 22, 'progress_percent' => 54], $payload['processing_outcome']['execution']);
        self::assertSame(['usable_pages' => 3, 'total_pages' => 22], $payload['processing_outcome']['usefulness']);
        self::assertArrayNotHasKey('cost_journal', $payload);
        self::assertContains('stop_document_processing', array_column($payload['available_actions'], 'action'));
    }

    #[Test]
    public function stopped_document_174_reports_full_execution_and_two_usable_pages(): void
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('can')->andReturnTrue();
        $this->app->instance(
            EstimateGenerationDocumentActionBuilder::class,
            new EstimateGenerationDocumentActionBuilder($authorization),
        );
        $document = $this->document('needs_review');
        $document->forceFill([
            'id' => 174,
            'page_count' => 22,
            'processed_page_count' => 2,
            'progress_percent' => 100,
            'source_version' => 'sha256:current',
            'processing_control_status' => 'cancelled',
            'processing_control_reason' => 'operator_stop',
        ]);
        $units = [];
        $pages = [];
        for ($page = 1; $page <= 22; $page++) {
            $unit = new EstimateGenerationProcessingUnit;
            $unit->forceFill([
                'id' => 800 + $page,
                'source_version' => 'sha256:current',
                'status' => $page <= 2
                    ? DocumentProcessingUnitStatus::Completed
                    : DocumentProcessingUnitStatus::Superseded,
                'output_count' => $page <= 2 ? 1 : 0,
                'metadata' => $page <= 2 ? [] : ['processing_control_status' => 'cancelled'],
            ]);
            $units[] = $unit;
            $pageProjection = new EstimateGenerationDocumentPage;
            $pageProjection->forceFill([
                'processing_unit_id' => 800 + $page,
                'source_version' => 'sha256:current',
                'page_number' => $page,
                'status' => $page <= 2 ? 'ready' : 'needs_review',
                'quality_flags' => $page <= 2 ? [] : ['processing_cancelled'],
            ]);
            $pages[] = $pageProjection;
        }
        $document->setRelation('processingUnits', new Collection($units));
        $document->setRelation('pages', new Collection($pages));
        $request = Request::create('/documents/174');
        $user = $this->user(7);
        $request->setUserResolver(static fn (): User => $user);

        $payload = (new EstimateGenerationDocumentResource($document))->toArray($request);

        self::assertSame('cancelled', $payload['processing_outcome']['type']);
        self::assertSame('partial', $payload['processing_outcome']['state']);
        self::assertSame(['completed_pages' => 22, 'total_pages' => 22, 'progress_percent' => 100], $payload['processing_outcome']['execution']);
        self::assertSame(['usable_pages' => 2, 'total_pages' => 22], $payload['processing_outcome']['usefulness']);
        self::assertSame('Обработка остановлена, частичный результат сохранён.', $payload['processing_outcome']['message']);
    }

    #[Test]
    public function temporary_document_failure_does_not_expose_explicit_system_failure_retry(): void
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('can')->andReturnTrue();
        $this->app->instance(
            EstimateGenerationDocumentActionBuilder::class,
            new EstimateGenerationDocumentActionBuilder($authorization),
        );
        $document = $this->document('failed');
        $document->forceFill([
            'page_count' => 22,
            'processed_page_count' => 0,
            'error_code' => 'document_processing_temporarily_unavailable',
            'error_message_key' => 'estimate_generation.document_processing_temporarily_unavailable',
            'facts_summary' => [
                'processing_outcome' => [
                    'type' => 'temporary_failure',
                    'counts' => [
                        'included' => 22,
                        'ready' => 0,
                        'needs_user_action' => 0,
                        'system_failed' => 22,
                        'processing' => 0,
                        'excluded' => 0,
                    ],
                    'retry_allowed' => true,
                ],
            ],
        ]);
        $request = Request::create('/documents/91');
        $user = $this->user(7);
        $request->setUserResolver(static fn (): User => $user);

        $payload = (new EstimateGenerationDocumentResource($document))->toArray($request);

        self::assertSame('temporary_failure', $payload['processing_outcome']['type']);
        self::assertTrue($payload['processing_outcome']['retry_allowed']);
        self::assertSame(['ignore_document'], array_column($payload['available_actions'], 'action'));
    }

    #[Test]
    public function legacy_identical_unit_failures_expose_one_explicit_document_retry(): void
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('can')->andReturnTrue();
        $this->app->instance(
            EstimateGenerationDocumentActionBuilder::class,
            new EstimateGenerationDocumentActionBuilder($authorization),
        );
        $document = $this->document('needs_review');
        $document->forceFill([
            'page_count' => 22,
            'processed_page_count' => 0,
            'source_version' => 'sha256:current',
        ]);
        $fingerprint = hash('sha256', 'legacy-systemic-root');
        $currentUnits = array_map(
            static function (int $index) use ($fingerprint): EstimateGenerationProcessingUnit {
                $unit = new EstimateGenerationProcessingUnit;
                $unit->forceFill([
                    'id' => 280 + $index,
                    'organization_id' => 7,
                    'project_id' => 17,
                    'session_id' => 41,
                    'document_id' => 91,
                    'source_version' => 'sha256:current',
                    'unit_type' => DocumentUnitType::PdfPage,
                    'unit_index' => $index + 1,
                    'status' => DocumentProcessingUnitStatus::Failed,
                    'attempt_count' => 3,
                    'output_count' => 0,
                    'failure_code' => 'document_geometry_processing_failed',
                    'failure_fingerprint' => $fingerprint,
                ]);

                return $unit;
            }, range(0, 21));
        $stale = new EstimateGenerationProcessingUnit;
        $stale->forceFill([
            'id' => 279,
            'organization_id' => 7,
            'project_id' => 17,
            'session_id' => 41,
            'document_id' => 91,
            'source_version' => 'sha256:stale',
            'unit_type' => DocumentUnitType::PdfPage,
            'unit_index' => 1,
            'status' => DocumentProcessingUnitStatus::Failed,
            'attempt_count' => 3,
            'output_count' => 0,
            'failure_code' => 'different_stale_failure',
            'failure_fingerprint' => hash('sha256', 'stale-root'),
        ]);
        $document->setRelation('processingUnits', new Collection([$stale, ...$currentUnits]));
        $request = Request::create('/documents/91');
        $user = $this->user(7);
        $request->setUserResolver(static fn (): User => $user);

        $payload = (new EstimateGenerationDocumentResource($document))->toArray($request);

        self::assertSame('system_failure', $payload['processing_outcome']['type']);
        self::assertSame(22, $payload['processing_outcome']['counts']['system_failed']);
        self::assertSame(0, $payload['processing_outcome']['counts']['needs_user_action']);
        self::assertSame(['retry_document', 'ignore_document'], array_column($payload['available_actions'], 'action'));
        self::assertSame('explicit_system_failure_retry', $payload['available_actions'][0]['retry_disposition']);
    }

    #[Test]
    public function document_actions_are_absent_without_permission_for_wrong_tenant_or_active_status(): void
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('can')->andReturnFalse();
        $builder = new EstimateGenerationDocumentActionBuilder($authorization);

        self::assertSame([], $builder->forDocument($this->document('failed'), $this->user(7)));
        self::assertSame([], $builder->forDocument($this->document('failed'), $this->user(8)));

        $authorization->allows('can')->andReturnTrue();
        self::assertSame([], $builder->forDocument($this->document('processing'), $this->user(7)));
    }

    /** @return iterable<string, array{EstimateGenerationStatus}> */
    public static function disallowedSessionStatuses(): iterable
    {
        yield 'applying' => [EstimateGenerationStatus::Applying];
        yield 'applied' => [EstimateGenerationStatus::Applied];
        yield 'failed' => [EstimateGenerationStatus::Failed];
        yield 'cancelled' => [EstimateGenerationStatus::Cancelled];
        yield 'archived' => [EstimateGenerationStatus::Archived];
    }

    #[Test]
    #[DataProvider('disallowedSessionStatuses')]
    public function document_actions_are_absent_outside_document_mutation_policy(
        EstimateGenerationStatus $sessionStatus,
    ): void {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldNotReceive('can');
        $document = $this->document('failed', $sessionStatus);

        $actions = (new EstimateGenerationDocumentActionBuilder($authorization))->forDocument(
            $document,
            $this->user(7),
        );

        self::assertSame([], $actions);
    }

    #[Test]
    public function list_resource_does_not_sign_document_preview(): void
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('can')->andReturnTrue();
        $files = Mockery::mock(FileService::class);
        $files->shouldNotReceive('temporaryUrl');
        $this->app->instance(
            EstimateGenerationDocumentActionBuilder::class,
            new EstimateGenerationDocumentActionBuilder($authorization),
        );
        $this->app->instance(
            EstimateGenerationDocumentPreviewService::class,
            new EstimateGenerationDocumentPreviewService($authorization, $files),
        );
        $request = Request::create('/documents');
        $user = $this->user(7);
        $request->setUserResolver(static fn (): User => $user);

        $payload = (new EstimateGenerationDocumentResource($this->document('ready')))->toArray($request);

        self::assertArrayNotHasKey('preview_url', $payload);
    }

    #[Test]
    public function ignored_document_has_no_retry_action(): void
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('can')->andReturnTrue();

        $actions = (new EstimateGenerationDocumentActionBuilder($authorization))->forDocument(
            $this->document('ignored'),
            $this->user(7),
        );

        self::assertSame([], $actions);
    }

    #[Test]
    public function preview_is_short_lived_scoped_and_only_for_safe_inline_types(): void
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->expects('can')->once()->andReturnTrue();
        $files = Mockery::mock(FileService::class);
        $files->expects('temporaryUrl')->once()->withArgs(static function (
            string $path,
            int $minutes,
            $organization,
            array $options,
        ): bool {
            return $path === 'org-7/estimate-generation/sessions/41/documents/plan.pdf'
                && $minutes === 5
                && $organization->id === 7
                && $options['ResponseContentType'] === 'application/pdf'
                && str_starts_with($options['ResponseContentDisposition'], 'inline;');
        })->andReturn('https://storage.example/signed-preview');
        $document = $this->document('ready');
        $document->forceFill([
            'mime_type' => 'application/pdf',
            'filename' => "plan\r\n.pdf",
            'storage_path' => 'org-7/estimate-generation/sessions/41/documents/plan.pdf',
        ]);

        $url = (new EstimateGenerationDocumentPreviewService($authorization, $files))->forDocument(
            $document,
            $this->user(7),
        );

        self::assertSame('https://storage.example/signed-preview', $url);
    }

    #[Test]
    public function preview_is_absent_for_unsafe_path_failed_document_unsupported_type_or_missing_permission(): void
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('can')->andReturnTrue();
        $files = Mockery::mock(FileService::class);
        $files->shouldNotReceive('temporaryUrl');
        $service = new EstimateGenerationDocumentPreviewService($authorization, $files);

        $unsafe = $this->document('ready');
        $unsafe->forceFill(['mime_type' => 'application/pdf', 'storage_path' => 'org-8/private.pdf']);
        self::assertNull($service->forDocument($unsafe, $this->user(7)));

        $failed = $this->document('failed');
        $failed->forceFill(['mime_type' => 'application/pdf']);
        self::assertNull($service->forDocument($failed, $this->user(7)));

        $cad = $this->document('ready');
        $cad->forceFill(['mime_type' => 'application/acad']);
        self::assertNull($service->forDocument($cad, $this->user(7)));

        $deniedAuthorization = Mockery::mock(AuthorizationService::class);
        $deniedAuthorization->expects('can')->once()->andReturnFalse();
        self::assertNull((new EstimateGenerationDocumentPreviewService($deniedAuthorization, $files))
            ->forDocument($this->document('ready'), $this->user(7)));
    }

    #[Test]
    public function detail_resource_exposes_safe_page_units_actions_and_preview_contract(): void
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('can')->andReturnTrue();
        $files = Mockery::mock(FileService::class);
        $files->allows('temporaryUrl')->andReturn('https://storage.example/signed-preview');
        $this->app->instance(
            EstimateGenerationDocumentActionBuilder::class,
            new EstimateGenerationDocumentActionBuilder($authorization),
        );
        $this->app->instance(
            EstimateGenerationDocumentPreviewService::class,
            new EstimateGenerationDocumentPreviewService($authorization, $files),
        );
        $document = $this->document('ready');
        $page = new EstimateGenerationDocumentPage;
        $page->forceFill([
            'id' => 101,
            'processing_unit_id' => 501,
            'page_number' => 1,
            'normalized_payload' => [
                'page_understanding' => [
                    'page_role' => 'floor_plan',
                    'role_for_estimation' => 'geometry_source',
                    'review_required' => true,
                    'review_reasons' => ['scale_missing'],
                ],
            ],
            'quality_flags' => ['low_contrast'],
        ]);
        $unit = new EstimateGenerationProcessingUnit;
        $unit->forceFill([
            'id' => 501,
            'unit_type' => DocumentUnitType::PdfPage,
            'unit_index' => 1,
            'status' => DocumentProcessingUnitStatus::Failed,
            'attempt_count' => 2,
            'output_count' => 0,
            'failure_code' => 'page_processing_failed',
        ]);
        $document->setRelations([
            'session' => $document->session,
            'pages' => new Collection([$page]),
            'processingUnits' => new Collection([$unit]),
            'facts' => new Collection,
            'drawingElements' => new Collection,
            'quantityTakeoffs' => new Collection,
            'scopeInferences' => new Collection,
        ]);
        $request = Request::create('/documents/91');
        $user = $this->user(7);
        $request->setUserResolver(static fn (): User => $user);

        $payload = (new EstimateGenerationDocumentDetailResource($document))->toArray($request);

        self::assertSame(9, $payload['state_version']);
        self::assertSame(['ignore_document'], array_column($payload['available_actions'], 'action'));
        self::assertSame('https://storage.example/signed-preview', $payload['preview_url']);
        self::assertSame('floor_plan', $payload['pages'][0]['page_role']);
        self::assertSame('geometry_source', $payload['pages'][0]['role_for_estimation']);
        self::assertFalse($payload['pages'][0]['review']['required']);
        self::assertSame('failed', $payload['processing_units'][0]['status']);
        self::assertSame('pdf_page', $payload['processing_units'][0]['unit_type']);
    }

    #[Test]
    public function file_service_never_logs_generated_temporary_url(): void
    {
        Log::spy();
        Log::shouldReceive('debug')->never();
        $logging = Mockery::mock(LoggingService::class);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->expects('temporaryUrl')->once()->withArgs(static fn (
            string $path,
            $expiresAt,
            array $options,
        ): bool => $path === 'org-7/estimate-generation/sessions/41/documents/plan.pdf'
            && $expiresAt instanceof \DateTimeInterface
            && $options === ['ResponseContentType' => 'application/pdf']
        )->andReturn('https://storage.example/secret-signed-url');
        $service = Mockery::mock(FileService::class, [$logging])->makePartial();
        $service->allows('disk')->andReturn($disk);

        $url = $service->temporaryUrl(
            'org-7/estimate-generation/sessions/41/documents/plan.pdf',
            5,
            null,
            ['ResponseContentType' => 'application/pdf'],
        );

        self::assertSame('https://storage.example/secret-signed-url', $url);
    }

    private function user(int $organizationId): User
    {
        $user = new User;
        $user->forceFill(['id' => 5, 'current_organization_id' => $organizationId]);

        return $user;
    }

    private function document(
        string $status,
        EstimateGenerationStatus $sessionStatus = EstimateGenerationStatus::Draft,
    ): EstimateGenerationDocument {
        $session = new EstimateGenerationSession;
        $session->forceFill([
            'id' => 41,
            'organization_id' => 7,
            'project_id' => 17,
            'state_version' => 9,
            'status' => $sessionStatus,
        ]);
        $document = new EstimateGenerationDocument;
        $document->forceFill([
            'id' => 91,
            'session_id' => 41,
            'organization_id' => 7,
            'project_id' => 17,
            'filename' => 'plan.pdf',
            'mime_type' => 'application/pdf',
            'storage_path' => 'org-7/estimate-generation/sessions/41/documents/plan.pdf',
            'status' => $status,
            'processing_stage' => $status === 'processing' ? 'preflight' : 'completed',
            'progress_percent' => $status === 'processing' ? 30 : 100,
        ]);
        $document->setRelation('session', $session);

        return $document;
    }
}
