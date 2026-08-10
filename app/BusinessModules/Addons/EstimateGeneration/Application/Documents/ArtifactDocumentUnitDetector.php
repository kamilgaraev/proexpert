<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;

final readonly class ArtifactDocumentUnitDetector implements DocumentUnitDetector
{
    /** @var list<DocumentUnitAdapter> */
    private array $adapters;

    public function __construct(
        PdfDocumentAdapter $pdf,
        ImageDocumentAdapter $image,
        CadDocumentAdapter $cad,
        SpreadsheetDocumentAdapter $spreadsheet,
    ) {
        $this->adapters = [$pdf, $cad, $image, $spreadsheet];
    }

    public function detect(EstimateGenerationDocument $document, string $sourceVersion): array
    {
        if ($this->isIfc($document)) {
            throw new DocumentManifestNeedsReview('document_source_kind_unsupported');
        }

        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($document)) {
                return $adapter->createUnits($document, $sourceVersion);
            }
        }

        throw new DocumentManifestNeedsReview('document_source_kind_unsupported');
    }

    private function isIfc(EstimateGenerationDocument $document): bool
    {
        return strtolower((string) pathinfo((string) $document->filename, PATHINFO_EXTENSION)) === 'ifc'
            || str_contains(strtolower((string) $document->mime_type), 'ifc');
    }
}
