<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

interface DocumentUnitProcessor
{
    public function process(DocumentUnitExecutionContext $context): DocumentUnitOutput;
}
