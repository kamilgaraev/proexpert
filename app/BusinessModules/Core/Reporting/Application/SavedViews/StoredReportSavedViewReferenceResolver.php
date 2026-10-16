<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\SavedViews;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSavedViewReferenceResolver;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSavedViewStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewRef;

final readonly class StoredReportSavedViewReferenceResolver implements ReportSavedViewReferenceResolver
{
    public function __construct(private ReportSavedViewStore $store)
    {
    }

    public function resolve(ReportExecutionContext $context, string $savedViewId): ReportSavedViewRef
    {
        $view = $this->store->getVisible(
            $context->scope->organizationId,
            $context->actor->id,
            $savedViewId,
        );
        if ($view->status !== 'active') {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
        }

        return ReportSavedViewRef::fromView($view);
    }

    public function assertCurrent(ReportExecutionContext $context, ReportSavedViewRef $reference): void
    {
        $current = $this->resolve($context, $reference->id);
        if ($current->revision !== $reference->revision
            || ! hash_equals($current->hash->value, $reference->hash->value)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
        }
    }

}
