<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Training;

interface TrainingEstimateRowsReader
{
    /** @return iterable<int, array<string, mixed>|object> */
    public function rows(object $importSession, string $path): iterable;
}
