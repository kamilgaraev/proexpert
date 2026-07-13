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
    public function failed_document_exposes_typed_retry_and_ignore_actions_from_review_permission(): void
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

        self::assertSame(['retry_document', 'ignore_document'], array_column($actions, 'action'));
        self::assertSame([9, 9], array_column($actions, 'state_version'));
        self::assertSame([
            '/api/v1/admin/projects/17/estimate-generation/sessions/41/documents/91/retry',
            '/api/v1/admin/projects/17/estimate-generation/sessions/41/documents/91/ignore',
        ], array_column($actions, 'endpoint'));
        self::assertTrue($actions[1]['requires_confirmation']);
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
    public function ignored_document_can_only_be_retried(): void
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('can')->andReturnTrue();

        $actions = (new EstimateGenerationDocumentActionBuilder($authorization))->forDocument(
            $this->document('ignored'),
            $this->user(7),
        );

        self::assertSame(['retry_document'], array_column($actions, 'action'));
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
        self::assertSame(['retry_document', 'ignore_document'], array_column($payload['available_actions'], 'action'));
        self::assertSame('https://storage.example/signed-preview', $payload['preview_url']);
        self::assertSame('floor_plan', $payload['pages'][0]['page_role']);
        self::assertSame('geometry_source', $payload['pages'][0]['role_for_estimation']);
        self::assertTrue($payload['pages'][0]['review']['required']);
        self::assertSame('failed', $payload['processing_units'][0]['status']);
        self::assertSame('pdf_page', $payload['processing_units'][0]['unit_type']);
    }

    #[Test]
    public function file_service_never_logs_generated_temporary_url(): void
    {
        Log::spy();
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
        Log::shouldNotHaveReceived('debug');
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
        ]);
        $document->setRelation('session', $session);

        return $document;
    }
}
