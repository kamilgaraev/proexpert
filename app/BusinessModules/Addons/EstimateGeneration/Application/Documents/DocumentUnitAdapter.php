<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;

interface DocumentUnitAdapter
{
    public function supports(EstimateGenerationDocument $document): bool;

    /** @return list<DocumentUnitData> */
    public function detect(EstimateGenerationDocument $document, string $sourceVersion): array;
}
