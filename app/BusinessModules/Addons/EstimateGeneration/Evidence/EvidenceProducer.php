<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evidence;

enum EvidenceProducer: string
{
    case PdfGeometry = 'pdf_geometry';
    case OcrFactExtractor = 'ocr_fact_extractor';
    case DrawingAnalyzer = 'drawing_analyzer';
    case ScopeInference = 'scope_inference';
    case WorkPlanner = 'work_planner';
    case NormativeMatcher = 'normative_matcher';
    case PriceResolver = 'price_resolver';
    case UserInputNormalizer = 'user_input_normalizer';
    case Pipeline = 'pipeline';
    case Test = 'test';
    case Contract = 'contract';
}
