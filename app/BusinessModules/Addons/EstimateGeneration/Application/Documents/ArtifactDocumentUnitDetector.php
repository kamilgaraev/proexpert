<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Geometry\PdfGeometryExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\PdfTextLayerExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\SpreadsheetDocumentExtractor;

final readonly class ArtifactDocumentUnitDetector implements DocumentUnitDetector
{
    public function __construct(
        private DocumentSourceManifestStorage $storage,
        private PdfTextLayerExtractor $pdf,
        private PdfGeometryExtractor $pdfGeometry,
        private SpreadsheetDocumentExtractor $spreadsheet,
        private MetadataDocumentUnitDetector $metadata,
    ) {}

    public function detect(EstimateGenerationDocument $document, string $sourceVersion): array
    {
        $units = $this->metadata->detect($document, $sourceVersion);
        $firstType = $units[0]->type ?? null;

        if ($firstType === DocumentUnitType::CadDrawing) {
            return $units;
        }

        if (! in_array($firstType, [DocumentUnitType::PdfPage, DocumentUnitType::SpreadsheetSheet], true)) {
            return $units;
        }

        $source = $this->storage->open($document);

        try {
            if ($firstType === DocumentUnitType::PdfPage) {
                $geometry = $this->pdfGeometry->extractFile(
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
                            'artifact_path' => $artifact->path,
                            'content_type' => $artifact->contentType,
                            'sha256' => substr($artifact->sha256, 7),
                            'bytes' => $artifact->bytes,
                            'version_id' => $artifact->versionId,
                            'width' => $metadata['width'],
                            'height' => $metadata['height'],
                        ];
                    },
                );
                $text = $this->pdf->extractFile($source->path(), $document->filename);
                $textByPage = [];
                foreach ($text?->pages ?? [] as $page) {
                    $textByPage[$page->pageNumber] = $page->text;
                }
                $detected = [];
                foreach ($geometry->pages as $page) {
                    $payload = [
                        'schema_version' => 1,
                        'source_kind' => 'pdf_page',
                        'geometry' => $page->toArray(),
                        'text' => $textByPage[$page->pageNumber] ?? $page->text(),
                        'provenance' => [
                            'provider' => $geometry->provider,
                            'model' => $geometry->model,
                            'source_version' => $sourceVersion,
                        ],
                    ];
                    $preview = $page->preview;
                    if (! is_string($preview['artifact_path'] ?? null)
                        || ! is_string($preview['sha256'] ?? null)
                        || ($preview['content_type'] ?? null) !== 'image/png') {
                        throw new DocumentManifestNeedsReview('pdf_raster_vision_artifact_required');
                    }
                    $geometryArtifact = $this->storage->put(
                        $document,
                        $sourceVersion,
                        $firstType,
                        $page->pageNumber,
                        json_encode($payload, JSON_THROW_ON_ERROR),
                        'application/json',
                    );
                    $detected[] = new DocumentUnitData($firstType, $page->pageNumber, $sourceVersion, [
                        'artifact_path' => $preview['artifact_path'],
                        'artifact_bytes' => $preview['bytes'],
                        'artifact_sha256' => 'sha256:'.$preview['sha256'],
                        'artifact_version_id' => $preview['version_id'],
                        'geometry_artifact_path' => $geometryArtifact->path,
                        'geometry_artifact_bytes' => $geometryArtifact->bytes,
                        'geometry_artifact_sha256' => $geometryArtifact->sha256,
                        'geometry_artifact_version_id' => $geometryArtifact->versionId,
                        'content_type' => 'image/png',
                        'artifact_source_version' => 'sha256:'.$preview['sha256'],
                    ]);
                }

                return DocumentUnitData::normalize($detected);
            }

            $recognition = $this->spreadsheet->extractFile($document, $source->path());

            if ($recognition === null) {
                throw new DocumentManifestNeedsReview('pdf_page_renderer_required');
            }

            $detected = [];

            foreach ($recognition->pages as $page) {
                $artifact = $this->storage->put(
                    $document,
                    $sourceVersion,
                    $firstType,
                    $page->pageNumber,
                    $page->text,
                );

                $detected[] = new DocumentUnitData($firstType, $page->pageNumber, $sourceVersion, $artifact->locator());
            }

            return DocumentUnitData::normalize($detected);
        } finally {
            $source->close();
        }
    }
}
