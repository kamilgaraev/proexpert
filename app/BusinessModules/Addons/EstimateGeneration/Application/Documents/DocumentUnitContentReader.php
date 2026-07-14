<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

interface DocumentUnitContentReader
{
    public function read(DocumentUnitExecutionContext $context): string;
}
