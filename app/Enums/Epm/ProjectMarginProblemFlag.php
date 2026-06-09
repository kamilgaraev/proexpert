<?php

declare(strict_types=1);

namespace App\Enums\Epm;

enum ProjectMarginProblemFlag: string
{
    case MissingProject = 'missing_project';
    case MissingContract = 'missing_contract';
    case MissingStage = 'missing_stage';
    case MissingBudgetArticle = 'missing_budget_article';
    case MissingResponsibilityCenter = 'missing_responsibility_center';
    case MissingCounterparty = 'missing_counterparty';
    case MissingPeriod = 'missing_period';
    case MissingCurrency = 'missing_currency';
    case MissingSourceDocument = 'missing_source_document';
    case UnmappedSource = 'unmapped_source';
    case DuplicateExternalReference = 'duplicate_external_reference';
    case AllocationNotApproved = 'allocation_not_approved';
    case AllocationTotalMismatch = 'allocation_total_mismatch';
    case ClosedPeriodChangeRequired = 'closed_period_change_required';
    case ReconciliationMismatch = 'reconciliation_mismatch';
    case StaleExternalConfirmation = 'stale_external_confirmation';
    case HiddenByPermissions = 'hidden_by_permissions';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $flag): string => $flag->value,
            self::cases(),
        );
    }
}
