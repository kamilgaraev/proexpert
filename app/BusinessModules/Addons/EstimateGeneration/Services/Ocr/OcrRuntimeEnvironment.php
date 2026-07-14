<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr;

interface OcrRuntimeEnvironment
{
    public function isProduction(): bool;
}
