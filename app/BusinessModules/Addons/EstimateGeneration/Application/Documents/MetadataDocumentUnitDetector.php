<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;

final class MetadataDocumentUnitDetector implements DocumentUnitDetector
{
    public function detect(EstimateGenerationDocument $document, string $sourceVersion): array
    {
        $mime = strtolower((string) $document->mime_type);
        $extension = strtolower((string) pathinfo((string) $document->filename, PATHINFO_EXTENSION));
        $meta = is_array($document->meta) ? $document->meta : [];

        if ($mime === 'application/pdf' || $extension === 'pdf') {
            return $this->indexed(DocumentUnitType::PdfPage, max(1, (int) $document->page_count), $sourceVersion, 'page');
        }

        if (in_array($extension, ['xlsx', 'xls', 'ods', 'csv'], true)) {
            return $this->indexed(DocumentUnitType::SpreadsheetSheet, max(1, (int) ($meta['sheet_count'] ?? 1)), $sourceVersion, 'sheet');
        }

        if (in_array($extension, ['dwg', 'dxf', 'ifc'], true)) {
            return [new DocumentUnitData(DocumentUnitType::CadDrawing, 1, $sourceVersion, ['drawing' => 1])];
        }

        if (str_starts_with($mime, 'image/') || in_array($extension, ['png', 'jpg', 'jpeg', 'webp', 'tif', 'tiff'], true)) {
            $type = ($meta['is_sketch'] ?? false) === true ? DocumentUnitType::Sketch : DocumentUnitType::RasterImage;

            return $this->indexed($type, max(1, (int) ($meta['frame_count'] ?? 1)), $sourceVersion, 'frame');
        }

        return $this->indexed(DocumentUnitType::TextPage, max(1, (int) ($document->page_count ?? 1)), $sourceVersion, 'page');
    }

    /** @return list<DocumentUnitData> */
    private function indexed(DocumentUnitType $type, int $count, string $sourceVersion, string $locatorKey): array
    {
        $count = min($count, DocumentUnitData::MAX_INDEX);
        $units = [];

        for ($index = 1; $index <= $count; $index++) {
            $units[] = new DocumentUnitData($type, $index, $sourceVersion, [$locatorKey => $index]);
        }

        return $units;
    }
}
