<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr;

final readonly class FixedOcrRuntimeEnvironment implements OcrRuntimeEnvironment
{
    public function __construct(private bool $production) {}

    public function isProduction(): bool
    {
        return $this->production;
    }
}
