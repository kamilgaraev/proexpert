<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Input;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;

interface ReportFilterReferenceResolver
{
    public function resolve(
        ReportScope $scope,
        string $filter,
        int|string $value,
    ): int|string;
}
