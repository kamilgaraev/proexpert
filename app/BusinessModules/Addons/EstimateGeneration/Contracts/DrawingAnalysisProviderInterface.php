<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Contracts;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Documents\DrawingAnalysisResultData;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;

interface DrawingAnalysisProviderInterface
{
    public function analyze(int $documentId, string $filename, OcrRecognitionResult $recognition): DrawingAnalysisResultData;
}
