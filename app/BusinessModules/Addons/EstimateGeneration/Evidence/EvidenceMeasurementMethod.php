<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evidence;

enum EvidenceMeasurementMethod: string
{
    case Geometry = 'geometry';
    case Ocr = 'ocr';
    case Calculated = 'calculated';
    case UserConfirmed = 'user_confirmed';
}
