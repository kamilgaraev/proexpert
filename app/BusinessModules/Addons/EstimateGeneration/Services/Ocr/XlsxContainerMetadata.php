<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr;

final readonly class XlsxContainerMetadata
{
    /** @param array<string, list<string>> $mergesBySheet */
    public function __construct(
        public array $mergesBySheet,
        public array $mergeLimitationsBySheet = [],
    ) {}
}
