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

        $content = $this->storage->read($document);

        if ($firstType === DocumentUnitType::PdfPage) {
            $geometry = $this->pdfGeometry->extract($content, $document->filename);
            $text = $this->pdf->extract($content, $document->filename);
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
                $previewBytes = is_string($preview['content_base64'] ?? null)
                    ? base64_decode($preview['content_base64'], true)
                    : false;
                if (! is_string($previewBytes) || $previewBytes === ''
                    || ! is_string($preview['sha256'] ?? null)
                    || ! hash_equals($preview['sha256'], hash('sha256', $previewBytes))) {
                    throw new DocumentManifestNeedsReview('pdf_raster_vision_artifact_required');
                }
                unset($payload['geometry']['preview']['content_base64']);
                $geometryPath = $this->storage->put(
                    $document,
                    $sourceVersion,
                    $firstType,
                    $page->pageNumber,
                    json_encode($payload, JSON_THROW_ON_ERROR),
                );
                $artifactPath = $this->storage->put(
                    $document,
                    $sourceVersion,
                    DocumentUnitType::Sketch,
                    $page->pageNumber,
                    $previewBytes,
                );
                $detected[] = new DocumentUnitData($firstType, $page->pageNumber, $sourceVersion, [
                    'artifact_path' => $artifactPath,
                    'geometry_artifact_path' => $geometryPath,
                    'content_type' => 'image/png',
                    'artifact_source_version' => 'sha256:'.$preview['sha256'],
                ]);
            }

            return DocumentUnitData::normalize($detected);
        }

        $recognition = $this->spreadsheet->extract($document, $content);

        if ($recognition === null) {
            throw new DocumentManifestNeedsReview('pdf_page_renderer_required');
        }

        $detected = [];

        foreach ($recognition->pages as $page) {
            $artifactPath = $this->storage->put(
                $document,
                $sourceVersion,
                $firstType,
                $page->pageNumber,
                $page->text,
            );

            $detected[] = new DocumentUnitData($firstType, $page->pageNumber, $sourceVersion, [
                'artifact_path' => $artifactPath,
                'content_type' => 'text/plain',
            ]);
        }

        return DocumentUnitData::normalize($detected);
    }
}
