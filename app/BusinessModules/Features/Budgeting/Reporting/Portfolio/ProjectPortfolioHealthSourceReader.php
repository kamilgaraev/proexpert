<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;

interface ProjectPortfolioHealthSourceReader
{
    /** @return array{components:list<ProjectPortfolioHealthSourceComponent>,gaps:list<array{code:string,kind?:string}>,calendar:list<\App\BusinessModules\Core\Payments\DTOs\PaymentCalendarItem>} */
    public function read(ReportExecutionContext $context, ReportQuery $query): array;
}
