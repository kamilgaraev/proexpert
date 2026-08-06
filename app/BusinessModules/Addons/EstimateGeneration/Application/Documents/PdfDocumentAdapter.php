<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Geometry\PdfGeometryExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\PdfTextLayerExtractor;

final readonly class PdfDocumentAdapter implements DocumentUnitAdapter
{
    public function __construct(
        private DocumentSourceManifestStorage $storage,
        private PdfTextLayerExtractor $textExtractor,
        private PdfGeometryExtractor $geometryExtractor,
    ) {}

    public function supports(EstimateGenerationDocument $document): bool
    {
        return strtolower((string) $document->mime_type) === 'application/pdf'
            || strtolower((string) pathinfo((string) $document->filename, PATHINFO_EXTENSION)) === 'pdf';
    }

    public function detect(EstimateGenerationDocument $document, string $sourceVersion): array
    {
        $source = $this->storage->open($document, $sourceVersion);

        try {
            $geometry = $this->geometryExtractor->extractFile(
                $source->path(),
                $document->filename,
                function (int $pageNumber, string $path, array $metadata) use ($document, $sourceVersion): array {
                    $bytes = file_get_contents($path);
                    if (! is_string($bytes) || $bytes === '') {
                        throw new DocumentManifestNeedsReview('pdf_raster_vision_artifact_required');
                    }
                    $artifact = $this->storage->put(
                        $document,
                        $sourceVersion,
                        DocumentUnitType::Sketch,
                        $pageNumber,
                        $bytes,
                        'image/png',
                    );

                    return [
                        ...$artifact->locator(),
                        'width' => $metadata['width'] ?? null,
                        'height' => $metadata['height'] ?? null,
                    ];
                },
            );
            $textLayer = $this->textExtractor->extractFile($source->path(), $document->filename);
            $textByPage = [];
            foreach ($textLayer?->pages ?? [] as $page) {
                $textByPage[$page->pageNumber] = $page->text;
            }
            $units = [];

            foreach ($geometry->pages as $page) {
                $preview = $page->preview;
                $artifactPath = $preview['artifact_path'] ?? null;
                $artifactSha256 = $preview['artifact_sha256'] ?? null;
                $artifactBytes = $preview['artifact_bytes'] ?? null;

                if (! is_string($artifactPath) || ! is_string($artifactSha256)
                    || ! is_int($artifactBytes)
                    || ($preview['content_type'] ?? null) !== 'image/png') {
                    throw new DocumentManifestNeedsReview('pdf_raster_vision_artifact_required');
                }

                $payload = [
                    'schema_version' => 1,
                    'source_kind' => DocumentUnitType::PdfPage->sourceKind(),
                    'geometry' => $page->toArray(),
                    'text' => $textByPage[$page->pageNumber] ?? $page->text(),
                    'sources' => [
                        'text_layer' => ['status' => isset($textByPage[$page->pageNumber]) ? 'available' : 'unavailable'],
                        'geometry' => ['status' => 'available'],
                        'render' => ['status' => 'available', 'detail' => 'high'],
                    ],
                    'provenance' => [
                        'provider' => $geometry->provider,
                        'model' => $geometry->model,
                        'source_version' => $sourceVersion,
                    ],
                ];
                $geometryArtifact = $this->storage->put(
                    $document,
                    $sourceVersion,
                    DocumentUnitType::PdfPage,
                    $page->pageNumber,
                    json_encode($payload, JSON_THROW_ON_ERROR),
                    'application/json',
                );

                $units[] = new DocumentUnitData(
                    DocumentUnitType::PdfPage,
                    $page->pageNumber,
                    $sourceVersion,
                    [
                        'source_kind' => DocumentUnitType::PdfPage->sourceKind(),
                        'source_version' => $sourceVersion,
                        'coordinate_space' => DocumentUnitType::PdfPage->coordinateSpace(),
                        'artifact_path' => $artifactPath,
                        'artifact_bytes' => $artifactBytes,
                        'artifact_sha256' => $artifactSha256,
                        'artifact_source_version' => $artifactSha256,
                        'content_type' => 'image/png',
                        'page' => $page->pageNumber,
                        'geometry_artifact_path' => $geometryArtifact->path,
                        'geometry_artifact_bytes' => $geometryArtifact->bytes,
                        'geometry_artifact_sha256' => $geometryArtifact->sha256,
                    ],
                );
            }

            if ($units === []) {
                throw new DocumentManifestNeedsReview('pdf_units_empty');
            }

            return DocumentUnitData::normalize($units);
        } finally {
            $source->close();
        }
    }
}
