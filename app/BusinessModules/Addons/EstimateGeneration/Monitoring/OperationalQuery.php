<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Monitoring;

final readonly class OperationalQuery
{
    /**
     * @param  list<mixed>  $bindings
     * @param  list<string>  $selectedColumns
     */
    public function __construct(
        public string $sql,
        public array $bindings,
        public array $selectedColumns,
        public int $rowLimit,
    ) {}
}
