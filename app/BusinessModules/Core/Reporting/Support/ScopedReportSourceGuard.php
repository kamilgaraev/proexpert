<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Support;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;

final class ScopedReportSourceGuard
{
    public static function assertAccessible(
        ReportExecutionContext $context,
        int $projectId,
        array $resources,
    ): void {
        if ($projectId < 1
            || ($context->scope->projectIds !== [] && ! in_array($projectId, $context->scope->projectIds, true))
            || ($context->authorization->projectIds !== [] && ! in_array($projectId, $context->authorization->projectIds, true))) {
            self::deny();
        }

        $scoped = $context->authorization->resources;
        if ($scoped === []) {
            return;
        }

        foreach ($scoped as $authorized) {
            if (! $authorized instanceof ReportScopedResource) {
                self::deny();
            }
            if ($authorized->kind === 'project' && $authorized->id === $projectId) {
                return;
            }
            foreach ($resources as $resource) {
                if ($resource instanceof ReportScopedResource
                    && $resource->kind === $authorized->kind
                    && $resource->id === $authorized->id
                    && ($authorized->projectId === null || $authorized->projectId === $projectId)) {
                    return;
                }
            }
        }

        self::deny();
    }

    private static function deny(): never
    {
        throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
    }
}
