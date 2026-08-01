<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\SavedViews;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSavedViewReferenceResolver;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSavedViewStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedView;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewRef;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;

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

        return $this->reference($view);
    }

    public function assertCurrent(ReportExecutionContext $context, ReportSavedViewRef $reference): void
    {
        $current = $this->resolve($context, $reference->id);
        if ($current->revision !== $reference->revision
            || ! hash_equals($current->hash->value, $reference->hash->value)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID);
        }
    }

    private function reference(ReportSavedView $view): ReportSavedViewRef
    {
        $revision = (int) $view->updatedAt->format('Uu');
        $projection = [
            'id' => $view->id,
            'report_code' => $view->reportCode,
            'contract_version' => $view->contractVersion,
            'name' => $view->name,
            'visibility' => $view->visibility,
            'filters' => $view->filters->values,
            'comparison' => $view->comparison,
            'sort' => [
                'field' => $view->sort->field,
                'direction' => $view->sort->direction->value,
            ],
            'columns' => $view->columns,
            'status' => $view->status,
            'is_default' => $view->isDefault,
            'updated_at' => $view->updatedAt->format('Y-m-d\TH:i:s.u\Z'),
        ];

        return new ReportSavedViewRef(
            $view->id,
            max(1, $revision),
            new Sha256Hash(hash('sha256', CanonicalJson::encode($projection))),
        );
    }
}
