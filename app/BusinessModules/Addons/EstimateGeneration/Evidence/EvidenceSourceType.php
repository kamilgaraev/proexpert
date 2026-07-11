<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evidence;

enum EvidenceSourceType: string
{
    case Document = 'document';
    case DocumentUnit = 'document_unit';
    case PageRegion = 'page_region';
    case UserInput = 'user_input';
    case CatalogNorm = 'catalog_norm';
    case PriceSnapshot = 'price_snapshot';
    case Pipeline = 'pipeline';
}
