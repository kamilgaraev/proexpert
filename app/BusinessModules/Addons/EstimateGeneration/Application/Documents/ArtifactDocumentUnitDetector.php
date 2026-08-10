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
        private DocumentRepresentationResourceMeter $resourceMeter = new SystemDocumentRepresentationResourceMeter,
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
                $measurement = $this->resourceMeter->measure(
                    static fn (): array => $adapter->createUnits($document, $sourceVersion),
                );

                return array_map(
                    static function (DocumentUnitData $unit) use ($adapter, $measurement): DocumentUnitData {
                        $unit = new DocumentUnitData(
                            $unit->type,
                            $unit->index,
                            $unit->sourceVersion,
                            [
                                ...$unit->locator,
                                'peak_memory_bytes' => $measurement->incrementalPeakMemoryBytes,
                                'duration_ms' => $measurement->durationMs,
                                'resource_measurement' => [
                                    'memory_metric' => $measurement->memoryMetric,
                                    'limitations' => $measurement->limitations,
                                ],
                            ],
                        );
                        $representation = $adapter->representation($unit);
                        if ($representation->capabilities->format === 'xlsx') {
                            foreach (['sheets', 'cells', 'formulas', 'merges', 'table_render', 'source_coordinates'] as $capability) {
                                $representation->capabilities->assertAvailable($capability);
                            }
                        }

                        return new DocumentUnitData(
                            $unit->type,
                            $unit->index,
                            $unit->sourceVersion,
                            [...$unit->locator, 'document_representation' => $representation->toArray()],
                        );
                    },
                    $measurement->result,
                );
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
