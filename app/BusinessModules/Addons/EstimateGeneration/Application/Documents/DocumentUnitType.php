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
}
