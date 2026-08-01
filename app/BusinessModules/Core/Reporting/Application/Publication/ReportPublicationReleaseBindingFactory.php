<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;

interface ReportPublicationReleaseBindingFactory
{
    public function create(ReportDefinition $definition): ReportDefinitionBinding;
}
