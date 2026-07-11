<?php

declare(strict_types=1);

namespace App\Integrations\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Training\TrainingEstimateRowsReader;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportFormatDetector;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportFormatRegistry;
use App\Models\ImportSession;

final class BudgetEstimateTrainingRowsReader implements TrainingEstimateRowsReader
{
    public function __construct(
        private ImportFormatDetector $formatDetector,
        private ImportFormatRegistry $formatRegistry,
    ) {}

    public function rows(object $importSession, string $path): iterable
    {
        if (! $importSession instanceof ImportSession) {
            return [];
        }

        $detection = $this->formatDetector->detect($importSession, $path);
        if ($detection === null || $detection->confidence <= 0.0) {
            throw new \RuntimeException(trans_message('estimate_generation.training_format_not_detected'));
        }

        $handler = $this->formatRegistry->bySlug($detection->formatSlug);
        $structure = $handler->detectStructure($importSession, $path);

        return $handler->preview($importSession, $path, $structure)->items;
    }
}
