<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\PdfTextLayerExtractor;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\SpreadsheetDocumentExtractor;

final readonly class ArtifactDocumentUnitDetector implements DocumentUnitDetector
{
    public function __construct(
        private DocumentSourceManifestStorage $storage,
        private PdfTextLayerExtractor $pdf,
        private SpreadsheetDocumentExtractor $spreadsheet,
        private MetadataDocumentUnitDetector $metadata,
    ) {}

    public function detect(EstimateGenerationDocument $document, string $sourceVersion): array
    {
        $units = $this->metadata->detect($document, $sourceVersion);
        $firstType = $units[0]->type ?? null;

        if ($firstType === DocumentUnitType::CadDrawing) {
            throw new DocumentManifestNeedsReview('cad_geometry_processor_required');
        }

        if (! in_array($firstType, [DocumentUnitType::PdfPage, DocumentUnitType::SpreadsheetSheet], true)) {
            return $units;
        }

        $content = $this->storage->read($document);

        $recognition = $firstType === DocumentUnitType::PdfPage
            ? $this->pdf->extract($content, $document->filename)
            : $this->spreadsheet->extract($document, $content);

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
