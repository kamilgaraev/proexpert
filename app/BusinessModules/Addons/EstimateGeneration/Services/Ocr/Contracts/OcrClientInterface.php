<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Contracts;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;

interface OcrClientInterface
{
    public function recognize(OcrDocumentInput $input): OcrRecognitionResult;
}
