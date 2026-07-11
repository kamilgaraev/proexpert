<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use Illuminate\Database\Connection;

final readonly class LaravelDocumentSourceReplacementTransaction implements DocumentSourceReplacementTransaction
{
    public function __construct(private Connection $database) {}

    public function transaction(callable $callback): mixed
    {
        return $this->database->transaction($callback, 3);
    }
}
