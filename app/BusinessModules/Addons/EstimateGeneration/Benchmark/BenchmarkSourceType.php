<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

enum BenchmarkSourceType: string
{
    case VectorPdf = 'vector_pdf';
    case ScannedPdf = 'scanned_pdf';
    case PhotoPlan = 'photo_plan';
    case DimensionedSketch = 'dimensioned_sketch';
    case UndimensionedSketch = 'undimensioned_sketch';
    case Dwg = 'dwg';
    case Dxf = 'dxf';
}
