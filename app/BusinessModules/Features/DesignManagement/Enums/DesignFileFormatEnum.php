<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Enums;

enum DesignFileFormatEnum: string
{
    case PDF = 'pdf';
    case PDF_A = 'pdfa';
    case DWG = 'dwg';
    case DXF = 'dxf';
    case DOCX = 'docx';
    case XLSX = 'xlsx';
    case ODT = 'odt';
    case ODS = 'ods';
    case XML = 'xml';
    case ZIP = 'zip';
    case IFC = 'ifc';

    public static function values(): array
    {
        return array_map(static fn (self $format): string => $format->value, self::cases());
    }
}
