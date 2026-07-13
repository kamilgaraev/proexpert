<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Geometry;

use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\GeometryReviewDataSource;
use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\GeometryReviewPayloadService;
use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\GeometryReviewSourcePresenter;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
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
        $service = new GeometryReviewPayloadService($source, new GeometryReviewSourcePresenter($files));

        $payload = $service->handle($this->session(), 7, 50);

        self::assertCount(50, $payload['sources']);
        self::assertSame([
            'total' => 10000,
            'current_page' => 7,
            'per_page' => 50,
            'last_page' => 200,
        ], $payload['sources_meta']);
        self::assertSame([[7, 9, 11, 7, 50]], $source->calls);
    }

    #[Test]
    public function it_returns_an_explicit_empty_overflow_page_without_signing(): void
    {
        $source = new FakeGeometryReviewDataSource(101, []);
        $files = Mockery::mock(FileService::class);
        $files->shouldNotReceive('temporaryUrl');
        $service = new GeometryReviewPayloadService($source, new GeometryReviewSourcePresenter($files));

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
            'unit_type' => 'raster_image',
            'page_id' => $index,
            'page_number' => $index,
            'filename' => 'plan.png',
            'storage_path' => "org-7/estimate-generation/sessions/11/documents/plan-{$index}.png",
            'mime_type' => 'image/png',
            'width' => 2000,
            'height' => 1000,
            'locator' => null,
            'normalized_payload' => [],
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
