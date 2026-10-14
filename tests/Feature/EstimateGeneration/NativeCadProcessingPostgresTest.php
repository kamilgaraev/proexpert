<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitClaimStatus;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitDetector;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ProcessDocumentUnit;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\NativeNumericFactProof;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Contracts\OcrClientInterface;
use App\BusinessModules\Addons\EstimateGeneration\Storage\BoundedVersionedS3ObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\CadGeometryProvider;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\CadConversionRuntime;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\DwgDxfGeometryProvider;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;
use Tests\Support\EstimateGeneration\InMemoryS3HttpStorage;

final class NativeCadProcessingPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_real_dxf_dimension_reaches_the_production_processor_and_atomic_publisher_without_inventing_area(): void
    {
        $python = getenv('MOST_CAD_TEST_PYTHON');
        if (! is_string($python) || $python === '') {
            self::markTestSkipped('Set MOST_CAD_TEST_PYTHON to the pinned geometry Python runtime; no recorded parser substitute is used.');
        }
        Http::fake([]);
        Http::preventStrayRequests();
        Queue::fake();
        $ocr = \Mockery::mock(OcrClientInterface::class);
        $ocr->shouldNotReceive('recognize');
        $this->app->instance(OcrClientInterface::class, $ocr);
        (new InMemoryS3HttpStorage)->install();
        $files = app(FileService::class);
        $this->app->instance(CadGeometryProvider::class, new DwgDxfGeometryProvider(
            new BoundedVersionedS3ObjectReader($files),
            new CadConversionRuntime($python, base_path('app/BusinessModules/Addons/EstimateGeneration/bin/cad_geometry_extract.py')),
        ));
        $body = file_get_contents(base_path('tests/Fixtures/EstimateGeneration/Vision/native-dimension-3000mm.dxf'));
        self::assertIsString($body);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id, 'user_id' => $user->id,
            'status' => 'draft', 'processing_stage' => 'draft', 'processing_progress' => 0, 'state_version' => 0, 'input_payload' => [],
        ]);
        $path = 'org-'.$organization->id.'/estimate-generation/native-tests/dimension.dxf';
        $files->putImmutable($path, $body, 'application/dxf');
        $version = 'sha256:'.hash('sha256', $body);
        $document = EstimateGenerationDocument::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id, 'session_id' => $session->id,
            'user_id' => $user->id, 'filename' => 'dimension.dxf', 'mime_type' => 'application/dxf',
            'storage_path' => $path, 'file_size_bytes' => strlen($body), 'source_version' => $version, 'checksum_sha256' => substr($version, 7),
        ]);
        $definitions = app(DocumentUnitDetector::class)->detect($document, $version);
        self::assertCount(1, $definitions);
        self::assertSame(DocumentUnitType::CadDrawing, $definitions[0]->type);
        $store = app(DocumentProcessingUnitStore::class);
        $unit = $store->create((int) $organization->id, (int) $project->id, (int) $session->id, (int) $document->id, $definitions[0]);
        $outcome = app(ProcessDocumentUnit::class)->handle($unit->id, $version);
        self::assertSame(DocumentProcessingUnitClaimStatus::Acquired, $outcome->status);
        self::assertSame('completed', $store->find($unit->id)->status->value);
        $snapshot = app(ProjectModelRepository::class)->snapshotForPlanning((int) $organization->id, (int) $project->id, (int) $session->id, 100)['snapshot'];
        self::assertCount(1, $snapshot->facts);
        $fact = $snapshot->facts[0];
        self::assertSame('dimension_chain', $fact->type);
        self::assertSame('3000', $fact->value);
        self::assertSame('mm', $fact->unit);
        self::assertSame('document', $fact->origin);
        self::assertSame('confirmed', $fact->status);
        self::assertSame($version, $fact->sourceVersion);
        $proof = array_values(array_filter($snapshot->evidence, static fn ($item): bool => in_array($item->id, $fact->evidenceIds, true)))[0];
        self::assertStringStartsWith('cad:dimension:', $proof->nativeReference);
        self::assertTrue((new NativeNumericFactProof)->matches($fact, $proof));
        $preview = app(CurrentProjectDerivedQuantityService::class)->previewInput((int) $organization->id, (int) $project->id, (int) $session->id);
        self::assertArrayNotHasKey('floor_area', $preview['quantities']);
        self::assertArrayNotHasKey('net_wall_area', $preview['quantities']);
        $counts = [];
        foreach (['estimate_generation_project_model_assertions', 'estimate_generation_evidence', 'estimate_generation_document_facts'] as $table) {
            $counts[$table] = DB::table($table)->where('session_id', $session->id)->count();
        }
        self::assertSame(DocumentProcessingUnitClaimStatus::AlreadyCompleted, app(ProcessDocumentUnit::class)->handle($unit->id, $version)->status);
        foreach ($counts as $table => $count) {
            self::assertSame($count, DB::table($table)->where('session_id', $session->id)->count());
        }
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
        Http::assertNothingSent();
    }
}
