<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

interface DocumentSourceReplacementTransaction
{
    public function transaction(callable $callback): mixed;
}
