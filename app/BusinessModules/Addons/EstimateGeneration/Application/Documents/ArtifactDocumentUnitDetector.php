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
        $this->adapters = [$pdf, $image, $cad, $spreadsheet];
    }

    public function detect(EstimateGenerationDocument $document, string $sourceVersion): array
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($document)) {
                return $adapter->detect($document, $sourceVersion);
            }
        }

        throw new DocumentManifestNeedsReview('document_source_kind_unsupported');
    }
}
