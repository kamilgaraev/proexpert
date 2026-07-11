<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evidence;

enum EvidenceUnit: string
{
    case Meter = 'm';
    case SquareMeter = 'm2';
    case CubicMeter = 'm3';
    case Piece = 'pcs';
    case Kilogram = 'kg';
    case Tonne = 't';
    case Hour = 'h';
}
