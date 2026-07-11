<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evidence;

enum EvidenceAttribute: string
{
    case WallLength = 'wall_length';
    case WallHeight = 'wall_height';
    case Area = 'area';
    case Perimeter = 'perimeter';
    case OpeningWidth = 'opening_width';
    case OpeningHeight = 'opening_height';
    case OpeningCount = 'opening_count';
    case RoomArea = 'room_area';
    case RoomTypeCode = 'room_type_code';
    case FloorCount = 'floor_count';
    case FloorHeight = 'floor_height';
    case RoofArea = 'roof_area';
    case RoofSlope = 'roof_slope';
    case MaterialCode = 'material_code';
    case Quantity = 'quantity';
    case ElementTypeCode = 'element_type_code';
}
