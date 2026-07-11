<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

interface DocumentUnitContentReader
{
    /** @return resource */
    public function open(DocumentUnitExecutionContext $context);
}
