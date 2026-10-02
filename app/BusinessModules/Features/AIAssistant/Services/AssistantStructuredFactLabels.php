<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Features\ExecutiveDocumentation\Enums\ExecutiveDocumentStatusEnum;
use App\BusinessModules\Features\ExecutiveDocumentation\Enums\ExecutiveDocumentTypeEnum;
use App\BusinessModules\Features\Procurement\Enums\PurchaseOrderStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\PurchaseReceiptStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\PurchaseRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierProposalStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierRequestStatusEnum;
use App\BusinessModules\Features\QualityControl\Enums\QualityDefectSeverityEnum;
use App\BusinessModules\Features\QualityControl\Enums\QualityDefectStatusEnum;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\EstimatePositionItemType;
use App\Enums\Schedule\ScheduleStatusEnum;
use App\Enums\Schedule\TaskStatusEnum;
use Illuminate\Support\Facades\Lang;

final class AssistantStructuredFactLabels
{
    public static function display(string $entityType, string $field, string $value): string
    {
        $label = match ($entityType.'.'.$field) {
            'contract.status' => ContractStatusEnum::tryFrom($value)?->label(),
            'payment_document.status' => PaymentDocumentStatus::tryFrom($value)?->label(),
            'payment_document.document_type' => PaymentDocumentType::tryFrom($value)?->label(),
            'schedule.status' => ScheduleStatusEnum::tryFrom($value)?->label(),
            'schedule_task.status' => TaskStatusEnum::tryFrom($value)?->label(),
            'estimate_item.item_type' => EstimatePositionItemType::tryFrom($value)?->label(),
            'estimate_item_resource.resource_type' => in_array($value, ['material', 'labor', 'equipment'], true) ? EstimatePositionItemType::tryFrom($value)?->label() : null,
            'purchase_request.status' => PurchaseRequestStatusEnum::tryFrom($value)?->label(),
            'supplier_request.status' => SupplierRequestStatusEnum::tryFrom($value)?->label(),
            'supplier_proposal.status' => SupplierProposalStatusEnum::tryFrom($value)?->label(),
            'purchase_order.status' => PurchaseOrderStatusEnum::tryFrom($value)?->label(),
            'purchase_receipt.status' => PurchaseReceiptStatusEnum::tryFrom($value)?->label(),
            'quality_defect.status' => QualityDefectStatusEnum::tryFrom($value)?->label(),
            'quality_defect.severity' => QualityDefectSeverityEnum::tryFrom($value)?->label(),
            'executive_document.status' => ExecutiveDocumentStatusEnum::tryFrom($value)?->label(),
            'executive_document.document_type' => ExecutiveDocumentTypeEnum::tryFrom($value)?->label(),
            default => null,
        };
        if ($label !== null && ! preg_match('/^[a-z_]+(?:\.[a-z_]+)+$/D', $label)) {
            return $label;
        }
        $group = match ($entityType.'.'.$field) {
            'design_package.status' => 'design_management.statuses.packages',
            'design_artifact_version.status' => 'design_management.statuses.versions',
            'design_review_comment.status' => 'design_management.review_comment_statuses',
            'design_review_comment.severity' => 'design_management.review_comment_severities',
            'design_artifact.artifact_type' => 'design_management.artifact_types',
            'design_package.project_stage' => 'design_management.project_stages',
            'estimate.status' => 'budget_estimates.mobile.statuses',
            'project.status' => 'ai_assistant_facts.project_statuses',
            'machinery_asset.status' => 'machinery_operations.asset_statuses',
            'machinery_assignment.status' => 'machinery_operations.assignment_statuses',
            'machinery_shift_report.status' => 'machinery_operations.shift_statuses',
            'machinery_maintenance_order.status' => 'machinery_operations.maintenance_statuses',
            default => null,
        };
        if ($group !== null && preg_match('/^[a-z_]+$/D', $value)) {
            $key = $group.'.'.$value;
            return Lang::has($key) ? trans_message($key) : $value;
        }

        return $value;
    }
}
