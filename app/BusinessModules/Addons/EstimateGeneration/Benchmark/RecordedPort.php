<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

enum RecordedPort: string
{
    case VisionExtraction = 'vision_extraction';
    case DocumentExtraction = 'document_extraction';
    case CadExtraction = 'cad_extraction';
    case WorkPlanningModel = 'work_planning_model';
    case NormativeReranker = 'normative_reranker';
}
