<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;

interface ReportDefinitionBindingAssembler
{
    public function register(ReportDefinitionBinding $binding): void;

    public function assemble(ReportDefinitionRegistry $registry): ReportDefinitionBindingMap;
}
