<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Geometry;

use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\GeometryReviewSourcePresenter;
use App\Models\Organization;
use App\Services\Storage\FileService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GeometryReviewSourcePresenterTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_signs_only_session_scoped_page_images_and_exposes_closed_normalized_elements(): void
    {
        $files = Mockery::mock(FileService::class);
        $files->expects('temporaryUrl')->once()->with(
            'org-7/estimate-generation/sessions/11/documents/13/manifests/source/page-00001.png',
            5,
            Mockery::on(static fn (Organization $organization): bool => (int) $organization->id === 7),
            ['ResponseContentType' => 'image/png'],
        )->andReturn('https://storage.example/signed-page');

        $payload = (new GeometryReviewSourcePresenter($files))->present($this->sourceRow(), 7, 11);

        self::assertSame('https://storage.example/signed-page', $payload['image_url']);
        self::assertSame([2000, 1000], $payload['source_size']);
        self::assertSame([[
            'key' => 'room-1',
            'type' => 'room',
            'label' => 'Кухня',
            'polygon' => [[0.1, 0.2], [0.8, 0.2], [0.8, 0.7]],
            'confidence' => 0.91,
            'evidence_ref' => 'evidence-1',
        ]], $payload['elements']);
    }

    #[Test]
    public function it_rejects_foreign_artifact_paths_without_signing(): void
    {
        $files = Mockery::mock(FileService::class);
        $files->shouldNotReceive('temporaryUrl');
        $row = $this->sourceRow();
        $row['artifact_path'] = 'org-8/estimate-generation/sessions/11/documents/13/manifests/source/page.png';

        self::assertNull((new GeometryReviewSourcePresenter($files))->present($row, 7, 11));
    }

    private function sourceRow(): array
    {
        return [
            'document_id' => 13,
            'page_id' => 17,
            'page_number' => 1,
            'filename' => 'План.pdf',
            'width' => 2000,
            'height' => 1000,
            'content_type' => 'image/png',
            'artifact_path' => 'org-7/estimate-generation/sessions/11/documents/13/manifests/source/page-00001.png',
            'normalized_payload' => [
                'vision_analysis' => [
                    'elements' => [[
                        'key' => 'room-1', 'type' => 'room', 'label' => 'Кухня',
                        'polygon' => [[0.1, 0.2], [0.8, 0.2], [0.8, 0.7]],
                        'confidence' => 0.91, 'evidence_ref' => 'evidence-1',
                    ], [
                        'key' => 'unsafe', 'type' => 'script', 'label' => null,
                        'polygon' => [[0, 0], [1, 1]], 'confidence' => 1, 'evidence_ref' => 'evidence-2',
                    ]],
                ],
            ],
        ];
    }
}
