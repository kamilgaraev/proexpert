<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Enums;

enum ProcurementAuditEventTypeEnum: string
{
    case SUPPLIER_REQUEST_CREATED = 'supplier_request_created';
    case SUPPLIER_REQUEST_SENT = 'supplier_request_sent';
    case SUPPLIER_REQUEST_VERSION_CREATED = 'supplier_request_version_created';
    case SUPPLIER_REQUEST_CANCELLED = 'supplier_request_cancelled';
    case SUPPLIER_PROPOSAL_CREATED = 'supplier_proposal_created';
    case SUPPLIER_PROPOSAL_INTAKE_RECORDED = 'supplier_proposal_intake_recorded';
    case SUPPLIER_PROPOSAL_VERSION_CREATED = 'supplier_proposal_version_created';
    case SUPPLIER_PROPOSAL_SELECTED = 'supplier_proposal_selected';
    case PROCUREMENT_APPROVAL_REQUESTED = 'procurement_approval_requested';
    case PROCUREMENT_APPROVAL_APPROVED = 'procurement_approval_approved';
    case PROCUREMENT_APPROVAL_REJECTED = 'procurement_approval_rejected';
    case PURCHASE_ORDER_CREATED = 'purchase_order_created';
    case MATERIALS_RECEIVED = 'materials_received';
}
