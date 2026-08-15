<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use RuntimeException;

final class DocumentProcessingControlConflict extends RuntimeException
{
    public function __construct(public readonly string $disposition)
    {
        parent::__construct($disposition);
    }
}
