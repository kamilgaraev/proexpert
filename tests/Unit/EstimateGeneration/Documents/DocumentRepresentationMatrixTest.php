<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentCoordinateTransform;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentManifestNeedsReview;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentRepresentationCapabilities;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentRepresentationResourceLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentRepresentationMatrixTest extends TestCase
{
    #[Test]
    #[DataProvider('fixtures')]
    public function every_format_has_native_visual_and_source_coordinate_capabilities(string $fixture): void
    {
        $data = $this->fixture($fixture);
        $capabilities = DocumentRepresentationCapabilities::fromArray($data['format'], $data['capabilities']);

        self::assertSame($data['capabilities'], $capabilities->toArray());
        self::assertSame([], $capabilities->limitations());

        $transform = DocumentCoordinateTransform::fromBounds($data['source_bounds']);
        $normalized = $transform->toNormalized($data['source_point']);

        self::assertEqualsWithDelta($data['normalized_point'][0], $normalized[0], 0.000000001);
        self::assertEqualsWithDelta($data['normalized_point'][1], $normalized[1], 0.000000001);
        self::assertEqualsWithDelta($data['source_point'][0], $transform->toSource($normalized)[0], 0.000000001);
        self::assertEqualsWithDelta($data['source_point'][1], $transform->toSource($normalized)[1], 0.000000001);

        (new DocumentRepresentationResourceLimits())->assertWithin($data['resource_usage']);
    }

    #[Test]
    public function unavailable_native_capability_is_typed_and_requires_review(): void
    {
        $capabilities = DocumentRepresentationCapabilities::fromArray('cad', [
            'layers' => 'available',
            'blocks' => 'unavailable:dwg_blocks_not_supported',
            'polylines' => 'available',
            'dimensions' => 'unavailable:dwg_dimensions_not_supported',
            'texts' => 'available',
            'sheet_render' => 'available',
            'source_coordinates' => 'available',
        ]);

        self::assertSame('blocks', $capabilities->limitations()[0]->capability);
        self::assertSame('dwg_blocks_not_supported', $capabilities->limitations()[0]->reason);

        try {
            $capabilities->assertAvailable('blocks');
            self::fail('Unavailable native capability must require review.');
        } catch (DocumentManifestNeedsReview $exception) {
            self::assertSame('document_native_capability_unavailable', $exception->safeCode);
            self::assertSame(['capability' => 'blocks', 'reason' => 'dwg_blocks_not_supported'], $exception->safeContext);
        }
    }

    #[Test]
    #[DataProvider('limitViolations')]
    public function every_resource_limit_is_enforced(array $usage, string $safeCode): void
    {
        $this->expectException(DocumentManifestNeedsReview::class);
        $this->expectExceptionMessage($safeCode);

        (new DocumentRepresentationResourceLimits(
            maxPages: 2,
            maxObjects: 3,
            maxBytes: 4,
            maxPeakMemoryBytes: 5,
            maxDurationMs: 6,
        ))->assertWithin($usage);
    }

    public static function fixtures(): iterable
    {
        foreach (['pdf', 'image', 'cad', 'xlsx'] as $format) {
            yield $format.' minimal' => [$format.'/minimal.json'];
            yield $format.' production' => [$format.'/production.json'];
        }
    }

    public static function limitViolations(): iterable
    {
        yield 'pages' => [['pages' => 3, 'objects' => 0, 'bytes' => 1, 'peak_memory_bytes' => 1, 'duration_ms' => 1], 'document_representation_page_limit_exceeded'];
        yield 'objects' => [['pages' => 1, 'objects' => 4, 'bytes' => 1, 'peak_memory_bytes' => 1, 'duration_ms' => 1], 'document_representation_object_limit_exceeded'];
        yield 'bytes' => [['pages' => 1, 'objects' => 0, 'bytes' => 5, 'peak_memory_bytes' => 1, 'duration_ms' => 1], 'document_representation_size_limit_exceeded'];
        yield 'memory' => [['pages' => 1, 'objects' => 0, 'bytes' => 1, 'peak_memory_bytes' => 6, 'duration_ms' => 1], 'document_representation_memory_limit_exceeded'];
        yield 'timeout' => [['pages' => 1, 'objects' => 0, 'bytes' => 1, 'peak_memory_bytes' => 1, 'duration_ms' => 7], 'document_representation_timeout_exceeded'];
    }

    private function fixture(string $relative): array
    {
        $path = dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/documents/v2/'.$relative;
        $decoded = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
