<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

enum DocumentUnitType: string
{
    case PdfPage = 'pdf_page';
    case SpreadsheetSheet = 'spreadsheet_sheet';
    case RasterImage = 'raster_image';
    case Sketch = 'sketch';
    case CadDrawing = 'cad_drawing';
    case TextPage = 'text_page';

    public function sourceKind(): string
    {
        return match ($this) {
            self::PdfPage => 'pdf',
            self::SpreadsheetSheet => 'spreadsheet',
            self::RasterImage, self::Sketch => 'image',
            self::CadDrawing => 'cad',
            self::TextPage => 'text',
        };
    }

    public function coordinateSpace(): string
    {
        return match ($this) {
            self::PdfPage => 'pdf_page_pixels',
            self::SpreadsheetSheet => 'spreadsheet_cells',
            self::RasterImage, self::Sketch => 'image_pixels',
            self::CadDrawing => 'cad_model',
            self::TextPage => 'text_offsets',
        };
    }
}
