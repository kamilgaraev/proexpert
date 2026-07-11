<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;

interface DocumentSourceManifestStorage
{
    public function read(EstimateGenerationDocument $document): string;

    public function put(EstimateGenerationDocument $document, string $sourceVersion, DocumentUnitType $type, int $index, string $content): string;
}
