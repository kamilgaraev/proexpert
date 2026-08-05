<?php

declare(strict_types=1);

namespace App\Services\Customer\Reporting\Sla;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;

final readonly class CustomerSlaPublishedRuntimeBindingRegistrar
{
    public function __construct(private ReportDefinitionRegistry $definitions, private CustomerSlaReportBindingFactory $bindings) {}

    public function register(ReportDefinitionBindingAssembler $assembler): void
    {
        try {
            $definition = $this->definitions->published(CustomerSlaCandidateContract::CODE);
        } catch (ReportContractException $exception) {
            if ($exception->errorCode === ReportErrorCode::REPORT_NOT_FOUND) {
                return;
            }
            throw $exception;
        }
        $assembler->register($this->bindings->create($definition->payload()));
    }
}
