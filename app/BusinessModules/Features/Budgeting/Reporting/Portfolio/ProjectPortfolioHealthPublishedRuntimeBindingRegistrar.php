<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;

final readonly class ProjectPortfolioHealthPublishedRuntimeBindingRegistrar
{
    public function __construct(private ReportDefinitionRegistry $definitions, private ProjectPortfolioHealthReportBindingFactory $bindings) {}
    public function register(ReportDefinitionBindingAssembler $assembler): void { $assembler->register($this->bindings->create($this->definitions->published(ProjectPortfolioHealthCandidateContract::CODE)->payload())); }
}
