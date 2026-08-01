<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Geometry;

use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\GeometryReviewDataSource;
use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\GeometryReviewPayloadService;
use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\GeometryReviewSourcePresenter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\GeometrySourceConfirmationFactory;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VectorGeometryData;
use App\Services\Storage\FileService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GeometryReviewPayloadServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_reads_and_signs_only_the_requested_bounded_page_at_large_cardinality(): void
    {
        $rows = array_map(fn (int $index): object => $this->row($index), range(1, 50));
        $source = new FakeGeometryReviewDataSource(10000, $rows);
        $files = Mockery::mock(FileService::class);
        $files->expects('temporaryUrl')->times(50)->andReturnUsing(
            static fn (string $path): string => 'https://storage.example/'.basename($path),
        );
        $service = new GeometryReviewPayloadService($source, new GeometryReviewSourcePresenter($files), new GeometrySourceConfirmationFactory);

        $payload = $service->handle($this->session(), 7, 50);

        self::assertCount(50, $payload['sources']);
        self::assertSame([
            'total' => 10000,
            'current_page' => 7,
            'per_page' => 50,
            'last_page' => 200,
        ], $payload['sources_meta']);
        self::assertSame('sha256:document-source', $payload['sources'][0]['source_version']);
        self::assertSame([[7, 9, 11, 7, 50]], $source->calls);
    }

    #[Test]
    public function it_returns_an_explicit_empty_overflow_page_without_signing(): void
    {
        $source = new FakeGeometryReviewDataSource(101, []);
        $files = Mockery::mock(FileService::class);
        $files->shouldNotReceive('temporaryUrl');
        $service = new GeometryReviewPayloadService($source, new GeometryReviewSourcePresenter($files), new GeometrySourceConfirmationFactory);

        $payload = $service->handle($this->session(), 4, 50);

        self::assertSame([], $payload['sources']);
        self::assertSame([
            'total' => 101,
            'current_page' => 4,
            'per_page' => 50,
            'last_page' => 3,
        ], $payload['sources_meta']);
        self::assertSame([[7, 9, 11, 4, 50]], $source->calls);
    }

    #[Test]
    public function it_exposes_server_derived_semantic_confirmation_only_for_a_current_complete_vector_capture_with_one_active_evidence(): void
    {
        $source = new FakeGeometryReviewDataSource(1, [$this->vectorRow()]);
        $files = Mockery::mock(FileService::class);
        $files->expects('temporaryUrl')->once()->andReturn('https://storage.example/plan.png');
        $service = new GeometryReviewPayloadService($source, new GeometryReviewSourcePresenter($files), new GeometrySourceConfirmationFactory);

        $payload = $service->handle($this->session());

        $confirmation = $payload['sources'][0]['source_confirmation'];
        self::assertIsArray($confirmation);
        self::assertSame(1, $confirmation['schema_version']);
        self::assertSame('sha256:'.str_repeat('b', 64), $confirmation['source_fingerprint']);
        self::assertSame(
            VectorGeometryData::fromArray($this->vectorGeometry())->payloadSha256(),
            $confirmation['geometry_payload_sha256'],
        );
        self::assertSame([['role' => 'measured_segment', 'entity_handle' => 'W1', 'point_indexes' => [0, 1], 'real_world_value' => 4000.0, 'unit' => 'mm']], $confirmation['scale_evidence']);
        self::assertSame(['R1'], array_column(array_filter($confirmation['elements'], static fn (array $item): bool => $item['type'] === 'room'), 'boundary_handle'));
        self::assertSame(['W1'], array_column(array_filter($confirmation['elements'], static fn (array $item): bool => $item['type'] === 'wall'), 'segment_handles')[0]);
        self::assertArrayNotHasKey('source_confirmation_unavailable_reason', $payload['sources'][0]);
    }

    #[Test]
    public function it_marks_stale_or_missing_semantic_confirmation_inputs_unavailable(): void
    {
        $stale = $this->vectorRow();
        $stale->page_source_version = 'sha256:'.str_repeat('c', 64);
        $missingEvidence = $this->vectorRow();
        $missingEvidence->source_evidence_count = 0;
        $missingEvidence->source_evidence_id = null;
        $missingCapture = $this->vectorRow();
        $missingCapture->normalized_payload = [];
        $source = new FakeGeometryReviewDataSource(3, [$stale, $missingEvidence, $missingCapture]);
        $files = Mockery::mock(FileService::class);
        $files->expects('temporaryUrl')->times(3)->andReturn('https://storage.example/plan.png');
        $service = new GeometryReviewPayloadService($source, new GeometryReviewSourcePresenter($files), new GeometrySourceConfirmationFactory);

        $payload = $service->handle($this->session());

        self::assertNull($payload['sources'][0]['source_confirmation']);
        self::assertSame('source_not_current', $payload['sources'][0]['source_confirmation_unavailable_reason']);
        self::assertNull($payload['sources'][1]['source_confirmation']);
        self::assertSame('source_evidence_unavailable', $payload['sources'][1]['source_confirmation_unavailable_reason']);
        self::assertNull($payload['sources'][2]['source_confirmation']);
        self::assertSame('semantic_confirmation_unavailable', $payload['sources'][2]['source_confirmation_unavailable_reason']);
    }

    #[Test]
    public function it_keeps_pdf_pages_unavailable_for_semantic_confirmation_even_with_an_injected_vector_capture(): void
    {
        $source = new FakeGeometryReviewDataSource(1, [$this->pdfGeometryRow()]);
        $files = Mockery::mock(FileService::class);
        $files->expects('temporaryUrl')->once()->andReturn('https://storage.example/plan.png');
        $service = new GeometryReviewPayloadService($source, new GeometryReviewSourcePresenter($files), new GeometrySourceConfirmationFactory);

        $payload = $service->handle($this->session());

        self::assertNull($payload['sources'][0]['source_confirmation']);
        self::assertSame('semantic_confirmation_unavailable', $payload['sources'][0]['source_confirmation_unavailable_reason']);
    }

    #[Test]
    public function it_marks_cad_drawings_without_an_explicit_cad_source_kind_unavailable(): void
    {
        $row = $this->vectorRow();
        unset($row->normalized_payload['source_kind']);
        $source = new FakeGeometryReviewDataSource(1, [$row]);
        $files = Mockery::mock(FileService::class);
        $files->expects('temporaryUrl')->once()->andReturn('https://storage.example/plan.png');
        $service = new GeometryReviewPayloadService($source, new GeometryReviewSourcePresenter($files), new GeometrySourceConfirmationFactory);

        $payload = $service->handle($this->session());

        self::assertNull($payload['sources'][0]['source_confirmation']);
        self::assertSame('semantic_confirmation_unavailable', $payload['sources'][0]['source_confirmation_unavailable_reason']);
    }

    private function session(): EstimateGenerationSession
    {
        $session = new EstimateGenerationSession;
        $session->forceFill([
            'id' => 11,
            'organization_id' => 7,
            'project_id' => 9,
            'state_version' => 3,
        ]);

        return $session;
    }

    private function row(int $index): object
    {
        return (object) [
            'document_id' => 13,
            'source_version' => 'sha256:document-source',
            'unit_type' => 'raster_image',
            'page_id' => $index,
            'page_number' => $index,
            'filename' => 'plan.png',
            'storage_path' => "org-7/estimate-generation/sessions/11/documents/plan-{$index}.png",
            'mime_type' => 'image/png',
            'width' => 2000,
            'height' => 1000,
            'locator' => [
                'artifact_path' => "org-7/estimate-generation/sessions/11/documents/plan-{$index}.png",
                'content_type' => 'image/png',
            ],
            'normalized_payload' => [],
        ];
    }

    private function vectorRow(): object
    {
        $row = $this->row(1);
        $row->source_version = 'sha256:'.str_repeat('b', 64);
        $row->unit_type = 'cad_drawing';
        $row->document_source_version = $row->source_version;
        $row->unit_source_version = $row->source_version;
        $row->page_source_version = $row->source_version;
        $row->unit_status = 'completed';
        $row->source_evidence_id = 91;
        $row->source_evidence_count = 1;
        $row->normalized_payload = ['source_kind' => 'cad', 'vector_geometry' => $this->vectorGeometry()];

        return $row;
    }

    private function pdfGeometryRow(): object
    {
        $row = $this->row(1);
        $row->source_version = 'sha256:'.str_repeat('b', 64);
        $row->unit_type = 'pdf_page';
        $row->document_source_version = $row->source_version;
        $row->unit_source_version = $row->source_version;
        $row->page_source_version = $row->source_version;
        $row->unit_status = 'completed';
        $row->source_evidence_id = 91;
        $row->source_evidence_count = 1;
        $row->normalized_payload = [
            'schema_version' => 1,
            'source_kind' => 'pdf_page',
            'vector_geometry' => $this->vectorGeometry(),
            'pdf_geometry' => [
                'schema_version' => 1,
                'geometry' => [
                    'page_number' => 1,
                    'width' => 595,
                    'height' => 842,
                    'rotation' => 0,
                    'vector_elements' => [
                        ['kind' => 'line', 'geometry' => ['points' => [[0, 0], [400, 0]]]],
                    ],
                ],
            ],
        ];

        return $row;
    }

    private function vectorGeometry(): array
    {
        return [
            'schema_version' => 1,
            'runtime_version' => 'cad-geometry:v1;ezdxf:1.4.4',
            'source_fingerprint' => 'sha256:'.str_repeat('b', 64),
            'source_unit' => 'mm',
            'unit_status' => 'confirmed',
            'bounds' => [0, 0, 4000, 3000],
            'layers' => [['name' => 'A', 'visible' => true]],
            'blocks' => [],
            'entities' => [
                ['handle' => 'R1', 'type' => 'lwpolyline', 'layer' => 'A', 'points' => [[0, 0], [4000, 0], [4000, 3000], [0, 3000]], 'closed' => true],
                ['handle' => 'W1', 'type' => 'line', 'layer' => 'A', 'points' => [[0, 0], [4000, 0]]],
            ],
            'texts' => [],
            'dimensions' => [],
            'pages' => [],
            'scale_candidates' => [],
            'warnings' => [],
        ];
    }
}

final class FakeGeometryReviewDataSource implements GeometryReviewDataSource
{
    /** @var list<array{0: int, 1: int, 2: int, 3: int, 4: int}> */
    public array $calls = [];

    /** @param list<object> $rows */
    public function __construct(private int $total, private array $rows) {}

    public function latestModel(int $organizationId, int $projectId, int $sessionId): ?array
    {
        return null;
    }

    public function sourcePage(int $organizationId, int $projectId, int $sessionId, int $page, int $perPage): array
    {
        $this->calls[] = [$organizationId, $projectId, $sessionId, $page, $perPage];

        return ['total' => $this->total, 'rows' => $this->rows];
    }
}
