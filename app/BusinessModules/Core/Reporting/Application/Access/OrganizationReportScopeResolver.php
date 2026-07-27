<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Access;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use Throwable;

final class OrganizationReportScopeResolver
{
    public function resolve(
        ReportActor $actor,
        AuthorizationDecisionContext $authorization,
    ): ReportScope {
        try {
            return new ReportScope(
                $authorization->organizationId,
                $authorization->holdingOrganizationIds,
                $authorization->projectIds,
                $authorization->resourceIds,
                $authorization->timezone,
            );
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_SCOPE_FORBIDDEN,
                previous: $exception,
            );
        }
    }
}
