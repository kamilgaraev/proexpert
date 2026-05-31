<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime;

use App\Models\ImportSession;

final readonly class ImportFormatDetector
{
    public function __construct(private ImportFormatRegistry $registry) {}

    public function detect(ImportSession $session, string $filePath): ?ImportDetectionResult
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $best = null;

        foreach ($this->registry->forExtension($extension) as $handler) {
            $result = $handler->detect($session, $filePath);

            if ($best === null || $result->confidence > $best->confidence) {
                $best = $result;
            }
        }

        return $best;
    }
}
